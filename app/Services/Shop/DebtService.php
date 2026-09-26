<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\SaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Who owes the shop: customer accounts AND credit sales that only carry a typed name
 * ("Member x", "Thembo 1": sold on credit without a customer account, customer_id NULL).
 *
 * A debtor is keyed 'c:<customer id>' (an account) or 'n:<name>' (the lower-cased, trimmed
 * name on the sales; '' for sales with no name at all). Money received against a name is
 * allocated to its open sales oldest first (SaleService::addPayment, so every ledger and
 * shift rule applies); against an account it goes through CustomerService::receivePayment.
 */
class DebtService
{
    public function __construct(
        private readonly SaleService $sales = new SaleService,
        private readonly CustomerService $customers = new CustomerService,
    ) {}

    /** Open, not voided, not deleted credit sales of a company. */
    private function open(int $companyId)
    {
        return DB::table('sale_records')->where('company_id', $companyId)->where('balance', '>', 0)
            ->whereNull('voided_at')->where('status', '<>', 'Voided')->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0));
    }

    public static function nameKey(?string $name): string
    {
        // Must match the SQL grouping: LOWER(TRIM(customer_name)).
        return 'n:'.mb_strtolower(trim((string) $name, ' '));
    }

    /**
     * Everyone who owes, biggest first.
     *
     * @return list<array{key: string, type: string, customer_id: ?int, name: string, phone: ?string, owed: float, sales: int, oldest: ?string, last_sale: ?string}>
     */
    public function debtors(int $companyId, string $search = ''): array
    {
        $rows = [];
        $accounts = DB::table('customers')->where('company_id', $companyId)->where('is_deleted', 0)->where('balance', '>', 0)
            ->get(['id', 'name', 'phone', 'balance']);
        $stats = $this->open($companyId)->whereNotNull('customer_id')->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS n, MIN(sale_date) AS oldest, MAX(sale_date) AS last_sale')->get()->keyBy('customer_id');
        foreach ($accounts as $c) {
            $s = $stats[$c->id] ?? null;
            $rows[] = ['key' => 'c:'.$c->id, 'type' => 'account', 'customer_id' => (int) $c->id, 'name' => (string) $c->name, 'phone' => $c->phone,
                'owed' => round((float) $c->balance, 2), 'sales' => (int) ($s->n ?? 0), 'oldest' => $s->oldest ?? null, 'last_sale' => $s->last_sale ?? null];
        }

        $names = $this->open($companyId)->whereNull('customer_id')
            ->selectRaw("LOWER(TRIM(COALESCE(customer_name, ''))) AS k, MAX(customer_name) AS name, MAX(NULLIF(customer_phone, '')) AS phone,
                SUM(balance) AS owed, COUNT(*) AS n, MIN(sale_date) AS oldest, MAX(sale_date) AS last_sale")
            ->groupBy('k')->get();
        foreach ($names as $n) {
            $label = trim((string) $n->name);
            $rows[] = ['key' => self::nameKey($n->k), 'type' => 'name', 'customer_id' => null,
                'name' => $label === '' || strcasecmp($label, 'Walk-in Customer') === 0 ? 'No name given' : $label, 'phone' => $n->phone,
                'owed' => round((float) $n->owed, 2), 'sales' => (int) $n->n, 'oldest' => $n->oldest, 'last_sale' => $n->last_sale];
        }

        if (($q = mb_strtolower(trim($search))) !== '') {
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name']), $q) || str_contains((string) $r['phone'], $q));
        }
        usort($rows, fn ($a, $b) => $b['owed'] <=> $a['owed']);

        return array_values($rows);
    }

    /** The open sales behind a debtor, oldest first. */
    public function openSales(int $companyId, string $key)
    {
        $q = $this->open($companyId);
        if (str_starts_with($key, 'c:')) {
            $q->where('customer_id', (int) substr($key, 2));
        } elseif (str_starts_with($key, 'n:')) {
            $q->whereNull('customer_id')->whereRaw("LOWER(TRIM(COALESCE(customer_name, ''))) = ?", [substr($key, 2)]);
        } else {
            return collect();
        }

        return $q->orderBy('sale_date')->orderBy('id')
            ->get(['id', 'receipt_number', 'sale_date', 'customer_name', 'customer_phone', 'total_amount', 'amount_paid', 'balance', 'due_date']);
    }

    /**
     * Money received from a debtor. For a name, it pays the open sales oldest first and may not exceed
     * what is owed (there is no account to keep credit on). Returns the amount applied.
     */
    public function receive(int $companyId, string $key, float $amount, string $method, int $userId, ?string $reference = null, ?int $shiftId = null): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Type how much was paid.');
        }
        if (str_starts_with($key, 'c:')) {
            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail((int) substr($key, 2));
            $this->customers->receivePayment($customer, $amount, $method, $userId, $reference, null, $shiftId);

            return $amount;
        }
        $open = $this->openSales($companyId, $key);
        $owed = round((float) $open->sum('balance'), 2);
        if ($open->isEmpty()) {
            throw BusinessRuleException::make('nothing_owed', 'Nothing is owed under this name any more.');
        }
        if ($amount > $owed) {
            throw BusinessRuleException::make('overpayment', 'Only '.number_format($owed).' is owed. Receive that much, or make them a customer to keep extra as credit.');
        }

        return DB::transaction(function () use ($companyId, $open, $amount, $method, $userId, $reference, $shiftId) {
            $left = $amount;
            foreach ($open as $row) {
                if ($left <= 0) {
                    break;
                }
                $pay = min($left, round((float) $row->balance, 2));
                $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($row->id);
                $this->sales->addPayment($sale, ['amount' => $pay, 'method' => $method, 'reference' => $reference, 'shift_id' => $shiftId], $userId);
                $left = round($left - $pay, 2);
            }

            return $amount;
        });
    }

    /**
     * Turn a name into a customer account: the customer is created (or an existing one with the same
     * phone is used) and every sale under that name, paid or not, is moved onto the account.
     */
    public function adopt(int $companyId, string $key, int $userId, array $attrs = []): Customer
    {
        if (! str_starts_with($key, 'n:')) {
            throw BusinessRuleException::make('not_a_name', 'This debtor already has a customer account.');
        }
        $name = trim((string) ($attrs['name'] ?? ''));
        if ($name === '') {
            throw BusinessRuleException::make('name_required', 'Give the customer a name.');
        }
        $phone = trim((string) ($attrs['phone'] ?? '')) ?: null;

        return DB::transaction(function () use ($companyId, $key, $userId, $name, $phone) {
            $customer = $phone ? Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->where('phone', $phone)->first() : null;
            if ($customer === null) {
                $customer = new Customer(['company_id' => $companyId, 'name' => $name, 'phone' => $phone, 'created_by_id' => $userId]);
                $customer->company_id = $companyId;
                $customer->save();
            }
            $ids = DB::table('sale_records')->where('company_id', $companyId)->whereNull('customer_id')
                ->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
                ->whereRaw("LOWER(TRIM(COALESCE(customer_name, ''))) = ?", [substr($key, 2)])->pluck('id');
            foreach ($ids as $id) {
                $sale = SaleRecord::withoutGlobalScopes()->find($id);
                $sale->customer_id = $customer->id;
                $sale->saveQuietlySynced();
                DB::table('payments')->where('company_id', $companyId)->where('sale_record_id', $id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            }

            return $this->customers->recalc((int) $customer->id) ?? $customer;
        });
    }
}
