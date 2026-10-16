<?php

namespace App\Services\Shop;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Engage\DebtReminders;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One customer's record for the phone (MOBILE_EXCELLENCE_PLAN.md D3), from the same services the web
 * customer page uses: the balance (CustomerService), points and store credit (LoyaltyService), what is
 * overdue on the payment terms (DebtReminders), the open sales by age, the latest sales and payments,
 * and the statement from a date with what was brought forward (CustomerService::statement).
 */
class CustomerSummary
{
    public const DEFAULT_DAYS = 90;

    public const RECENT = 20;

    /** @return array<string, mixed> */
    public function for(Customer $customer, ?string $from = null): array
    {
        $cid = (int) $customer->company_id;
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        $today = LocalDate::today($cid);
        $from ??= $today->copy()->subDays(self::DEFAULT_DAYS)->toDateString();
        $balance = (new CustomerService)->balance($customer);
        $loyalty = (new LoyaltyService)->summary($customer);
        $position = app(DebtReminders::class)->position($customer, $company);
        $limit = $customer->credit_limit === null ? null : round((float) $customer->credit_limit, 2);
        $owes = max(0.0, $balance);

        $statement = (new CustomerService)->statement($customer, $from);

        return [
            'customer' => ['id' => (int) $customer->id, 'uuid' => $customer->uuid, 'name' => (string) $customer->name, 'phone' => $customer->phone,
                'email' => $customer->email, 'address' => $customer->address, 'is_active' => (bool) $customer->is_active,
                'payment_terms_days' => (int) ($customer->payment_terms_days ?? $company->credit_terms_days ?? 30),
                'messages_opt_out' => (bool) $customer->messages_opt_out, 'last_reminded_at' => $customer->last_reminded_at ? Carbon::parse($customer->last_reminded_at)->toIso8601String() : null],
            'figures' => [
                'owes' => $owes,
                'credit_limit' => $limit !== null && $limit > 0 ? $limit : null,
                'available' => $limit !== null && $limit > 0 ? round(max(0, $limit - $owes), 2) : null,
                'store_credit' => round((float) $loyalty['credit'], 2),
                'points' => $loyalty['loyalty'] ? (int) $loyalty['points'] : null,
                'points_value' => $loyalty['loyalty'] ? (float) $loyalty['points_value'] : null,
                'tier' => $loyalty['tier'],
                'overdue' => (float) $position['overdue'],
                'oldest_due' => $position['oldest_due'],
            ],
            'aging' => $this->aging($cid, (int) $customer->id, $today),
            'recent_sales' => $this->sales($cid, (int) $customer->id),
            'payments' => $this->payments($cid, (int) $customer->id),
            'statement' => [
                'from' => $from, 'to' => $today->toDateString(),
                'brought_forward' => (float) $statement['opening_balance'],
                'closing_balance' => (float) $statement['closing_balance'],
                'entries' => $statement['entries'],
            ],
        ];
    }

    /**
     * What is still owed on open sales, by days since the sale: 0–30, 31–60 and over 60.
     *
     * @return array{buckets: list<array{key: string, label: string, amount: float, sales: int}>, total: float}
     */
    public function aging(int $companyId, int $customerId, Carbon $today): array
    {
        $buckets = ['0_30' => ['0–30 days', 0.0, 0], '31_60' => ['31–60 days', 0.0, 0], '60_plus' => ['Over 60 days', 0.0, 0]];
        $open = DB::table('sale_records')->where('company_id', $companyId)->where('customer_id', $customerId)->where('balance', '>', 0)
            ->whereNull('voided_at')->where('status', '<>', 'Voided')->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->get(['sale_date', 'balance']);
        foreach ($open as $s) {
            $age = (int) Carbon::parse(substr((string) $s->sale_date, 0, 10), $today->getTimezone())->startOfDay()->diffInDays($today->copy()->startOfDay(), false);
            $key = $age <= 30 ? '0_30' : ($age <= 60 ? '31_60' : '60_plus');
            $buckets[$key][1] += (float) $s->balance;
            $buckets[$key][2]++;
        }

        return [
            'buckets' => array_map(fn ($k, $b) => ['key' => $k, 'label' => $b[0], 'amount' => round($b[1], 2), 'sales' => $b[2]], array_keys($buckets), $buckets),
            'total' => round(array_sum(array_column($buckets, 1)), 2),
        ];
    }

    /** @return list<array<string, mixed>> the latest sales, newest first */
    private function sales(int $companyId, int $customerId): array
    {
        return DB::table('sale_records')->where('company_id', $companyId)->where('customer_id', $customerId)->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->orderByDesc('sale_date')->orderByDesc('id')->limit(self::RECENT)
            ->get(['id', 'uuid', 'receipt_number', 'sale_date', 'created_at', 'total_amount', 'refunded_amount', 'amount_paid', 'balance', 'status', 'payment_status', 'voided_at'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'uuid' => $s->uuid, 'receipt_number' => $s->receipt_number, 'sale_date' => substr((string) $s->sale_date, 0, 10),
                'created_at' => $s->created_at ? Carbon::parse($s->created_at)->toIso8601String() : null,
                'total' => round((float) $s->total_amount - (float) $s->refunded_amount, 2), 'paid' => round((float) $s->amount_paid, 2),
                'balance' => $s->voided_at ? 0.0 : round((float) $s->balance, 2), 'status' => $s->voided_at ? 'Voided' : (string) $s->status,
                'payment_status' => $s->payment_status, 'voided' => $s->voided_at !== null])->all();
    }

    /** @return list<array<string, mixed>> the latest money in (and refunds out), newest first */
    private function payments(int $companyId, int $customerId): array
    {
        return DB::table('payments as p')->leftJoin('sale_records as s', 's.id', '=', 'p.sale_record_id')
            ->where('p.company_id', $companyId)->where('p.customer_id', $customerId)->where(fn ($q) => $q->whereNull('p.is_deleted')->orWhere('p.is_deleted', 0))
            ->orderByDesc('p.received_at')->orderByDesc('p.id')->limit(self::RECENT)
            ->get(['p.id', 'p.uuid', 'p.amount', 'p.method', 'p.reference', 'p.received_at', 'p.sale_record_id', 'p.is_reversal', 'p.notes', 's.receipt_number'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'uuid' => $p->uuid, 'amount' => round((float) $p->amount, 2), 'method' => Payment::methodKey($p->method),
                'method_label' => Payment::label((string) $p->method), 'reference' => $p->reference,
                'received_at' => $p->received_at ? Carbon::parse($p->received_at)->toIso8601String() : null,
                'sale_id' => $p->sale_record_id ? (int) $p->sale_record_id : null, 'receipt_number' => $p->receipt_number,
                'on_account' => $p->sale_record_id === null, 'is_reversal' => (bool) $p->is_reversal])->all();
    }
}
