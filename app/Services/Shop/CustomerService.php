<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Debt book (plan A1/A3, P2-4): a customer's balance is what they still owe
 * on non-voided sales minus money paid on account; payments received on
 * account settle the oldest open sales first.
 */
class CustomerService
{
    public function balance(Customer $customer): float
    {
        $owed = (float) SaleRecord::withoutGlobalScopes()->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)->whereNull('voided_at')->sum('balance');
        $credit = (float) Payment::withoutGlobalScopes()->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)->whereNull('sale_record_id')->sum('amount');

        return round($owed - $credit, 2);
    }

    public function recalc(int $customerId): ?Customer
    {
        $customer = Customer::withoutGlobalScopes()->find($customerId);
        if ($customer === null) {
            return null;
        }
        $balance = $this->balance($customer);
        if (round((float) $customer->balance, 2) !== $balance) {
            $customer->balance = $balance;
            $customer->saveQuietlySynced();
        }

        return $customer;
    }

    /**
     * Money received from a customer: allocated to open sales oldest-first,
     * any remainder kept as account credit. Returns the payment rows created.
     *
     * @return array<int, Payment>
     */
    public function receivePayment(Customer $customer, float $amount, string $method, int $userId, ?string $reference = null, ?string $clientUuid = null, ?int $shiftId = null): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Payment amount must be greater than zero.');
        }
        if ($clientUuid) {
            $existing = Payment::withoutGlobalScopes()->where('company_id', $customer->company_id)
                ->where('notes', 'like', '%['.$clientUuid.']%')->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }
        }

        return DB::transaction(function () use ($customer, $amount, $method, $userId, $reference, $clientUuid, $shiftId) {
            $payments = new PaymentService();
            $left = $amount;
            $created = [];
            $open = SaleRecord::withoutGlobalScopes()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)
                ->whereNull('voided_at')->where('balance', '>', 0)->orderBy('sale_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $i => $sale) {
                if ($left <= 0) {
                    break;
                }
                $apply = min($left, (float) $sale->balance);
                $created[] = $payments->record($sale, [
                    'amount' => $apply, 'method' => $method, 'reference' => $reference, 'received_by_id' => $userId, 'shift_id' => $shiftId,
                    'client_uuid' => $clientUuid ? \App\Support\Sync\SyncSequence::childUuid($clientUuid, (string) $i) : null,
                    'notes' => $clientUuid ? 'Account payment ['.$clientUuid.']' : null,
                ]);
                $left = round($left - $apply, 2);
            }
            if ($left > 0) {
                $created[] = $p = $payments->recordAccountPayment($customer, $left, $method, $userId, $reference, $clientUuid ? \App\Support\Sync\SyncSequence::childUuid($clientUuid, 'credit') : null, $shiftId);
                if ($clientUuid) {
                    $p->notes = 'Payment on account ['.$clientUuid.']';
                    $p->saveQuietlySynced();
                }
            }
            $this->recalc($customer->id);

            return $created;
        });
    }

    /**
     * Statement: sales (debits), returns and payments (credits) with a running balance.
     *
     * @return array{customer: array, opening_balance: float, closing_balance: float, entries: array<int, array>}
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $entries = [];
        $sales = SaleRecord::withoutGlobalScopes()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->whereNull('voided_at')->get();
        $recorded = $sales->isEmpty() ? collect() : Payment::withoutGlobalScopes()->whereIn('sale_record_id', $sales->pluck('id'))
            ->groupBy('sale_record_id')->selectRaw('sale_record_id, SUM(amount) AS total')->pluck('total', 'sale_record_id');
        foreach ($sales as $s) {
            $entries[] = ['date' => (string) $s->sale_date->toDateString(), 'at' => $s->created_at, 'type' => 'sale', 'ref' => $s->receipt_number, 'description' => 'Sale '.$s->receipt_number, 'debit' => round((float) $s->total_amount, 2), 'credit' => 0.0];
            // Sales from before payment rows existed carry what was paid at the till only in amount_paid.
            $paidAtSale = round((float) $s->amount_paid - (float) ($recorded[$s->id] ?? 0), 2);
            if ($paidAtSale > 0) {
                $entries[] = ['date' => (string) $s->sale_date->toDateString(), 'at' => $s->created_at, 'type' => 'payment', 'ref' => $s->receipt_number, 'description' => 'Paid at sale', 'debit' => 0.0, 'credit' => $paidAtSale];
            }
            if ((float) $s->refunded_amount > 0) {
                $entries[] = ['date' => (string) $s->updated_at?->toDateString(), 'at' => $s->updated_at, 'type' => 'return', 'ref' => $s->receipt_number, 'description' => 'Returned goods', 'debit' => 0.0, 'credit' => round((float) $s->refunded_amount, 2)];
            }
        }
        $pays = Payment::withoutGlobalScopes()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->get();
        foreach ($pays as $p) {
            $amt = round((float) $p->amount, 2);
            $entries[] = ['date' => (string) $p->received_at?->toDateString(), 'at' => $p->received_at, 'type' => $amt < 0 ? 'refund' : 'payment', 'ref' => $p->reference, 'description' => $amt < 0 ? 'Cash refunded' : ($p->notes === PaymentService::PAID_AT_SALE ? 'Paid at sale' : 'Payment ('.$p->method.')'), 'debit' => $amt < 0 ? -$amt : 0.0, 'credit' => $amt > 0 ? $amt : 0.0];
        }
        usort($entries, fn ($a, $b) => [$a['date'], (string) $a['at']] <=> [$b['date'], (string) $b['at']]);

        $opening = 0.0;
        $running = 0.0;
        $out = [];
        foreach ($entries as $e) {
            $running = round($running + $e['debit'] - $e['credit'], 2);
            if ($from && $e['date'] < $from) {
                $opening = $running;

                continue;
            }
            if ($to && $e['date'] > $to) {
                continue;
            }
            unset($e['at']);
            $out[] = $e + ['balance' => $running];
        }

        return [
            'customer' => ['id' => $customer->id, 'uuid' => $customer->uuid, 'name' => $customer->name, 'phone' => $customer->phone, 'credit_limit' => $customer->credit_limit],
            'opening_balance' => $opening,
            'closing_balance' => $out === [] ? $opening : end($out)['balance'],
            'entries' => $out,
        ];
    }
}
