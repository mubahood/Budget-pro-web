<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;

/**
 * Records payments and posts the matching Income ledger row (P0-7 / G5).
 * A credit sale posts nothing until money arrives; a void/refund posts a
 * contra row that references the same source.
 */
class PaymentService
{
    /**
     * @param  array<string, mixed>  $attrs  amount, method, provider, reference, received_at, received_by_id, client_uuid, notes, currency, shift_id
     */
    public function record(SaleRecord $sale, array $attrs): Payment
    {
        $amount = round((float) $attrs['amount'], 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Payment amount must be greater than zero.');
        }

        if (! empty($attrs['client_uuid'])) {
            $existing = Payment::withoutGlobalScopes()->where('company_id', $sale->company_id)->where('client_uuid', $attrs['client_uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($sale, $attrs, $amount) {
            $this->adoptPaidAtSale($sale);
            $method = Payment::normalizeMethod($attrs['method'] ?? $sale->payment_method);
            $receivedAt = isset($attrs['received_at']) ? \Illuminate\Support\Carbon::parse($attrs['received_at']) : now();

            $payment = new Payment();
            $payment->client_uuid = $attrs['client_uuid'] ?? null;
            $payment->company_id = $sale->company_id;
            $payment->sale_record_id = $sale->id;
            $payment->method = $method;
            $payment->provider = $attrs['provider'] ?? null;
            $payment->reference = $attrs['reference'] ?? null;
            $payment->amount = $amount;
            $payment->currency = $attrs['currency'] ?? $sale->currency ?? Company::withoutGlobalScopes()->find($sale->company_id)?->currency;
            $payment->received_at = $receivedAt;
            $payment->received_by_id = $attrs['received_by_id'] ?? $sale->created_by_id;
            $payment->customer_id = $sale->customer_id;
            $payment->shift_id = $attrs['shift_id'] ?? $sale->shift_id;
            $payment->notes = $attrs['notes'] ?? null;
            $payment->save();

            $ledger = $this->postIncome($sale, $payment);
            if ($ledger !== null) {
                $payment->financial_record_id = $ledger->id;
                $payment->saveQuietlySynced();
            }

            $this->syncSaleTotals($sale);
            if ($sale->customer_id) {
                (new CustomerService())->recalc((int) $sale->customer_id);
            }

            return $payment;
        });
    }

    /** Contra payment + contra ledger row. */
    public function reverse(Payment $payment, ?string $reason = null, ?int $userId = null): Payment
    {
        if ($payment->is_reversal) {
            throw BusinessRuleException::make('already_reversal', 'A reversal cannot itself be reversed.');
        }
        $already = Payment::withoutGlobalScopes()->where('reverses_id', $payment->id)->first();
        if ($already) {
            return $already;
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            $contra = new Payment();
            $contra->company_id = $payment->company_id;
            $contra->sale_record_id = $payment->sale_record_id;
            $contra->customer_id = $payment->customer_id; // the debt book and statement net it
            $contra->shift_id = $payment->shift_id; // ShiftService::totals nets it in the same drawer
            $contra->method = $payment->method;
            $contra->provider = $payment->provider;
            $contra->reference = $payment->reference;
            $contra->amount = -1 * (float) $payment->amount;
            $contra->currency = $payment->currency;
            $contra->received_at = now();
            $contra->received_by_id = $userId ?? $payment->received_by_id;
            $contra->is_reversal = true;
            $contra->reverses_id = $payment->id;
            $contra->notes = 'Reversal of payment #'.$payment->id.($reason ? ': '.$reason : '');
            $contra->save();

            if ($payment->financial_record_id) {
                $original = FinancialRecord::withoutGlobalScopes()->find($payment->financial_record_id);
                if ($original) {
                    $ledger = $this->postContra($original, $contra, $reason);
                    $contra->financial_record_id = $ledger->id;
                    $contra->saveQuietlySynced();
                }
            }

            if ($payment->sale_record_id) {
                $sale = SaleRecord::withoutGlobalScopes()->find($payment->sale_record_id);
                if ($sale) {
                    $this->syncSaleTotals($sale);
                }
            }

            return $contra;
        });
    }

    /** amount_paid / balance / payment_status from the payment rows (single source of truth). */
    public function syncSaleTotals(SaleRecord $sale): void
    {
        $this->adoptPaidAtSale($sale);
        $paid = round((float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'), 2);
        $total = round((float) $sale->total_amount, 2);
        $net = max(0, round($total - (float) $sale->refunded_amount, 2)); // what the customer owes after returns

        $sale->amount_paid = max(0, min($paid, $net));
        $sale->change_given = max(0, round($paid - $net, 2));
        $sale->balance = max(0, round($net - $paid, 2));
        $sale->payment_status = $sale->balance <= 0 ? 'Paid' : ($sale->amount_paid > 0 ? 'Partial' : 'Unpaid');
        if ($sale->voided_at === null && (float) $sale->refunded_amount > 0) {
            $sale->status = (float) $sale->refunded_amount >= $total ? 'Refunded' : 'Partially Refunded';
        }
        $sale->saveQuietlySynced();
    }

    /**
     * Money handed back to a customer (return/refund, P2-6): a negative payment on
     * the sale plus a contra Income row, so cash-up and the ledger both drop.
     */
    public function refund(SaleRecord $sale, float $amount, string $method, int $userId, ?int $shiftId = null, ?string $clientUuid = null): Payment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Refund amount must be greater than zero.');
        }

        return DB::transaction(function () use ($sale, $amount, $method, $userId, $shiftId, $clientUuid) {
            $this->adoptPaidAtSale($sale);
            $p = new Payment();
            $p->client_uuid = $clientUuid;
            $p->company_id = $sale->company_id;
            $p->sale_record_id = $sale->id;
            $p->customer_id = $sale->customer_id;
            $p->shift_id = $shiftId;
            $p->method = Payment::normalizeMethod($method);
            $p->amount = -$amount;
            $p->currency = $sale->currency;
            $p->received_at = now();
            $p->received_by_id = $userId;
            $p->notes = 'Refund for sale '.($sale->receipt_number ?: '#'.$sale->id);
            $p->save();

            $category = $this->salesCategory((int) $sale->company_id);
            $row = new FinancialRecord();
            $row->financial_category_id = $category->id;
            $row->company_id = $sale->company_id;
            $row->user_id = $userId;
            $row->created_by_id = $userId;
            $row->amount = -$amount;
            $row->quantity = 1;
            $row->type = 'Income';
            $row->payment_method = $p->method;
            $row->recipient = $sale->customer_name ?? '';
            $row->receipt = $sale->receipt_number ?? '';
            $row->date = LocalDate::today((int) $sale->company_id);
            $row->description = 'Refund for sale '.($sale->receipt_number ?: '#'.$sale->id);
            $row->source_type = 'payment';
            $row->source_id = $p->id;
            $row->is_reversal = true;
            $row->currency = $sale->currency;
            $row->save();
            $p->financial_record_id = $row->id;
            $p->saveQuietlySynced();

            return $p;
        });
    }

    /** Money received on a customer's account that is not tied to one sale (advance / overpayment). */
    public function recordAccountPayment(\App\Models\Customer $customer, float $amount, string $method, int $userId, ?string $reference = null, ?string $clientUuid = null, ?int $shiftId = null): Payment
    {
        return DB::transaction(function () use ($customer, $amount, $method, $userId, $reference, $clientUuid, $shiftId) {
            $p = new Payment();
            $p->client_uuid = $clientUuid;
            $p->company_id = $customer->company_id;
            $p->customer_id = $customer->id;
            $p->shift_id = $shiftId;
            $p->method = Payment::normalizeMethod($method);
            $p->reference = $reference;
            $p->amount = round($amount, 2);
            $p->currency = Company::withoutGlobalScopes()->find($customer->company_id)?->currency;
            $p->received_at = now();
            $p->received_by_id = $userId;
            $p->notes = 'Payment on account';
            $p->save();

            $category = $this->salesCategory((int) $customer->company_id);
            $row = new FinancialRecord();
            $row->financial_category_id = $category->id;
            $row->company_id = $customer->company_id;
            $row->user_id = $userId;
            $row->created_by_id = $userId;
            $row->amount = $p->amount;
            $row->quantity = 1;
            $row->type = 'Income';
            $row->payment_method = $p->method;
            $row->recipient = $customer->name;
            $row->receipt = $reference ?? '';
            $row->date = LocalDate::today((int) $customer->company_id);
            $row->description = 'Payment on account — '.$customer->name;
            $row->source_type = 'payment';
            $row->source_id = $p->id;
            $row->currency = $p->currency;
            $row->save();
            $p->financial_record_id = $row->id;
            $p->saveQuietlySynced();

            return $p;
        });
    }

    /**
     * A sale recorded before payment rows existed keeps what was paid at the till only in
     * amount_paid. The first time money moves on such a sale again (a later payment, a refund,
     * a void) that amount becomes a payment row, so amount_paid — recomputed from payment rows —
     * does not forget it. No ledger row: the old app booked that sale's income when it was sold.
     */
    public function adoptPaidAtSale(SaleRecord $sale): ?Payment
    {
        $paidAtSale = round((float) $sale->amount_paid, 2);
        if ($sale->processed_at === null || $paidAtSale <= 0 || Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->exists()) {
            return null;
        }
        $p = new Payment();
        $p->company_id = $sale->company_id;
        $p->sale_record_id = $sale->id;
        $p->customer_id = $sale->customer_id;
        $p->method = Payment::normalizeMethod($sale->payment_method);
        $p->amount = $paidAtSale;
        $p->currency = $sale->currency;
        $p->received_at = $sale->created_at ?? now();
        $p->received_by_id = $sale->created_by_id;
        $p->notes = self::PAID_AT_SALE;
        $p->save();

        return $p;
    }

    public const PAID_AT_SALE = 'Paid at sale';

    /**
     * Income for a payment, never more than money received beyond what the ledger already holds for
     * this sale. Before Phase 0 every sale movement posted its full value as Income (source_type
     * stock_record); a later payment on such a sale collects income that is already booked.
     */
    private function postIncome(SaleRecord $sale, Payment $payment): ?FinancialRecord
    {
        $legacy = (float) DB::table('financial_records as f')->join('stock_records as m', 'm.id', '=', 'f.source_id')
            ->where('f.company_id', $sale->company_id)->where('f.source_type', 'stock_record')->where('f.is_deleted', 0)
            ->where('m.sale_record_id', $sale->id)->sum('f.amount');
        $viaPayments = (float) DB::table('financial_records as f')->join('payments as p', 'p.financial_record_id', '=', 'f.id')
            ->where('p.sale_record_id', $sale->id)->where('p.id', '!=', $payment->id)->where('f.is_deleted', 0)->sum('f.amount');
        $received = (float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'); // includes this payment
        $income = round(min((float) $payment->amount, $received - $legacy - $viaPayments), 2);
        if ($income <= 0) {
            return null;
        }

        $category = $this->salesCategory((int) $sale->company_id);

        $row = new FinancialRecord();
        $row->financial_category_id = $category->id;
        $row->company_id = $sale->company_id;
        $row->user_id = $payment->received_by_id;
        $row->created_by_id = $payment->received_by_id;
        $row->amount = $income;
        $row->quantity = 1;
        $row->type = 'Income';
        $row->payment_method = $payment->method;
        $row->recipient = $sale->customer_name ?? '';
        $row->receipt = $sale->receipt_number ?? '';
        $row->date = LocalDate::date((int) $sale->company_id, $payment->received_at);
        $row->description = 'Payment for sale '.($sale->receipt_number ?: '#'.$sale->id);
        $row->source_type = 'payment';
        $row->source_id = $payment->id;
        $row->currency = $payment->currency;
        $row->financial_period_id = $sale->financial_period_id;
        $row->save();

        return $row;
    }

    private function postContra(FinancialRecord $original, Payment $contraPayment, ?string $reason): FinancialRecord
    {
        $row = new FinancialRecord();
        $row->financial_category_id = $original->financial_category_id;
        $row->company_id = $original->company_id;
        $row->user_id = $contraPayment->received_by_id;
        $row->created_by_id = $contraPayment->received_by_id;
        $row->amount = -1 * (float) $original->amount;
        $row->quantity = 1;
        $row->type = $original->type;
        $row->payment_method = $original->payment_method;
        $row->recipient = $original->recipient;
        $row->receipt = $original->receipt;
        $row->date = LocalDate::today((int) $original->company_id);
        $row->description = 'Reversal of ledger #'.$original->id.($reason ? ': '.$reason : '');
        $row->source_type = 'payment';
        $row->source_id = $contraPayment->id;
        $row->is_reversal = true;
        $row->reverses_id = $original->id;
        $row->currency = $original->currency;
        $row->financial_period_id = $original->financial_period_id;
        $row->save();

        return $row;
    }

    /**
     * The tenant's "Sales" income category, created on first use. Under parallel checkouts
     * the loser of the insert race re-reads with a locking read: inside REPEATABLE READ a
     * plain SELECT keeps the transaction's snapshot and would not see the winner's row.
     */
    public function salesCategory(int $companyId): FinancialCategory
    {
        $find = fn (bool $locking) => FinancialCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('name', 'Sales')
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->first();

        $category = $find(false);
        if ($category === null) {
            try {
                Company::prepare_account_categories($companyId);
            } catch (\Illuminate\Database\QueryException|BusinessRuleException $e) {
                // A parallel checkout created the default categories a moment ago (unique index) — reuse them.
            }
            $category = $find(true);
        }
        if ($category === null) {
            try {
                $category = new FinancialCategory();
                $category->company_id = $companyId;
                $category->name = 'Sales';
                $category->type = 'Income';
                $category->save();
            } catch (\Illuminate\Database\QueryException|BusinessRuleException $e) {
                $category = $find(true);
                if ($category === null) {
                    throw $e;
                }
            }
        }

        return $category;
    }
}
