<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SaleRecord;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty points (supermarket plan C1, `loyalty` feature). A customer's points are the sum of their rows in
 * `loyalty_ledger`, like a customer balance is the sum of sales and payments, so every point can be traced:
 *  - earn:    a completed sale with a customer earns floor(money paid ÷ loyalty_spend_per_point). Money paid
 *             with points does not earn; gift cards bought are not sales, so they never earn either.
 *  - reverse: a return takes back its share of what the sale earned; a void takes back all of it.
 *  - redeem:  "Points" is a tender at the till (TenderService), worth points × loyalty_point_value.
 * Rows with a payment_id belong to a Points tender row (its redemption, or giving it back); rows without
 * one are what a sale earned (earn / reverse).
 *
 * Tiers are only a threshold on the last 12 months' spend (loyalty_silver_spend / loyalty_gold_spend).
 */
class LoyaltyService
{
    public static function enabled(?Company $company): bool
    {
        return StoreFeatures::enabled($company, 'loyalty');
    }

    public static function pointValue(?Company $company): float
    {
        return max(0.0, (float) StoreFeatures::setting($company, 'loyalty_point_value'));
    }

    public static function spendPerPoint(?Company $company): float
    {
        return max(0.0, (float) StoreFeatures::setting($company, 'loyalty_spend_per_point'));
    }

    public function balance(int $companyId, int $customerId): int
    {
        return (int) DB::table('loyalty_ledger')->where('company_id', $companyId)->where('customer_id', $customerId)->sum('points');
    }

    /** Sales (net of returns, not voided) to this customer in the last 12 months. */
    public function spend12m(int $companyId, int $customerId): float
    {
        $since = \App\Support\LocalDate::today($companyId)->subMonths(12)->toDateString();

        return round((float) DB::table('sale_records')->where('company_id', $companyId)->where('customer_id', $customerId)
            ->whereNull('voided_at')->where('status', '<>', 'Voided')->where('sale_date', '>', $since)
            ->sum(DB::raw('total_amount - COALESCE(refunded_amount, 0)')), 2);
    }

    /** 'Gold', 'Silver' or null, from the last 12 months' spend. */
    public static function tierFor(?Company $company, float $spend): ?string
    {
        $gold = (float) StoreFeatures::setting($company, 'loyalty_gold_spend');
        $silver = (float) StoreFeatures::setting($company, 'loyalty_silver_spend');

        return match (true) {
            $gold > 0 && $spend >= $gold => 'Gold',
            $silver > 0 && $spend >= $silver => 'Silver',
            default => null,
        };
    }

    /**
     * What the till and the customer screen show: points (and their value), tier, store credit.
     * `loyalty` / `credit` say which parts this shop uses.
     *
     * @return array{loyalty: bool, credit_on: bool, points: int, points_value: float, tier: ?string, spend_12m: float, credit: float}
     */
    public function summary(Customer $customer): array
    {
        $company = Company::withoutGlobalScopes()->find($customer->company_id);
        $cid = (int) $customer->company_id;
        $loyalty = self::enabled($company);
        $points = $loyalty ? $this->balance($cid, (int) $customer->id) : 0;
        $spend = $loyalty ? $this->spend12m($cid, (int) $customer->id) : 0.0;

        return [
            'loyalty' => $loyalty,
            'credit_on' => StoreFeatures::enabled($company, 'gift_cards'),
            'points' => $points,
            'points_value' => round(max(0, $points) * self::pointValue($company), 2),
            'tier' => $loyalty ? self::tierFor($company, $spend) : null,
            'spend_12m' => $spend,
            'credit' => max(0.0, -(new CustomerService())->balance($customer)),
        ];
    }

    /**
     * Points for a completed sale with a customer (once per sale). Money paid with points does not earn.
     * Off, no customer, or nothing paid: nothing is written.
     */
    public function earnForSale(SaleRecord $sale, int $userId): int
    {
        if (! $sale->customer_id || $sale->voided_at !== null) {
            return 0;
        }
        $company = Company::withoutGlobalScopes()->find($sale->company_id);
        if (! self::enabled($company) || ($per = self::spendPerPoint($company)) <= 0) {
            return 0;
        }
        if (DB::table('loyalty_ledger')->where('sale_record_id', $sale->id)->where('reason', 'earn')->exists()) {
            return 0;
        }
        $paid = (float) DB::table('payments')->where('sale_record_id', $sale->id)->where('is_deleted', 0)
            ->where('method', '<>', 'points')->where('amount', '>', 0)->where('is_reversal', 0)->sum('amount');
        $base = min($paid, (float) $sale->total_amount);
        $points = (int) floor(round($base / $per, 6));
        if ($points <= 0) {
            return 0;
        }
        $this->write((int) $sale->company_id, (int) $sale->customer_id, $points, 'earn', (int) $sale->id, null, $userId);

        return $points;
    }

    /**
     * Take back what a sale earned: its share for a return ($fraction of the sale's value), or everything
     * still standing (null: a void, or the sale now fully returned).
     */
    public function reverseForSale(SaleRecord $sale, ?float $fraction, int $userId): int
    {
        $rows = DB::table('loyalty_ledger')->where('sale_record_id', $sale->id)->whereNull('payment_id')->whereIn('reason', ['earn', 'reverse'])
            ->selectRaw("COALESCE(SUM(CASE WHEN reason = 'earn' THEN points ELSE 0 END), 0) AS earned, COALESCE(SUM(points), 0) AS standing, MAX(customer_id) AS customer_id")->first();
        $standing = (int) ($rows->standing ?? 0);
        if ($standing <= 0 || ! $rows->customer_id) {
            return 0;
        }
        $take = $fraction === null ? $standing : min($standing, (int) round((int) $rows->earned * max(0.0, min(1.0, $fraction))));
        if ($take <= 0) {
            return 0;
        }
        $this->write((int) $sale->company_id, (int) $rows->customer_id, -$take, 'reverse', (int) $sale->id, null, $userId);

        return $take;
    }

    /** Points earned by a sale and the customer's balance now, for the receipt (null when the sale has no points rows). @return array{earned: int, redeemed: int, balance: int}|null */
    public function receiptLine(SaleRecord $sale): ?array
    {
        if (! $sale->customer_id) {
            return null;
        }
        $r = DB::table('loyalty_ledger')->where('sale_record_id', $sale->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(CASE WHEN payment_id IS NULL THEN points ELSE 0 END), 0) AS earned, COALESCE(SUM(CASE WHEN payment_id IS NOT NULL THEN -points ELSE 0 END), 0) AS redeemed')->first();
        if ((int) ($r->n ?? 0) === 0) {
            return null;
        }

        return ['earned' => (int) $r->earned, 'redeemed' => (int) $r->redeemed, 'balance' => $this->balance((int) $sale->company_id, (int) $sale->customer_id)];
    }

    /** A manual correction (+/−) with a reason, by someone who manages the money. */
    public function adjust(Customer $customer, int $points, int $userId): void
    {
        if ($points === 0) {
            throw BusinessRuleException::make('invalid_points', 'Enter the points to add or take away.');
        }
        $this->write((int) $customer->company_id, (int) $customer->id, $points, 'adjust', null, null, $userId);
    }

    /** One ledger row. Used by TenderService for Points tender rows (payment_id set). */
    public function write(int $companyId, int $customerId, int $points, string $reason, ?int $saleId, ?int $paymentId, ?int $userId): void
    {
        DB::table('loyalty_ledger')->insert([
            'company_id' => $companyId, 'customer_id' => $customerId, 'points' => $points, 'sale_record_id' => $saleId, 'payment_id' => $paymentId,
            'reason' => $reason, 'created_by' => $userId, 'created_at' => now(),
        ]);
    }
}
