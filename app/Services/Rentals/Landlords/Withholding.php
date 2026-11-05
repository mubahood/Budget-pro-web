<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money an agency holds back for a landlord (LC-23, EL-29): off until the agency turns it on, and then only for a landlord it typed a percent for. This class states no
 * law and knows no rate: it multiplies the agency's own percent by the rent the agency collected, as a suggestion the office can change. Recording it is an expense
 * charged to the landlord, on the day it is actually paid over (cash −, landlord −). Nothing is held in the books until then, so no new account is needed (ADR 0010).
 */
final class Withholding
{
    public const CATEGORY = 'Withholding tax';

    /** Whether this account uses it: its own switch, and only an agency has a landlord to hold back for. */
    public static function on(int $companyId): bool
    {
        $s = RentalsModule::settings($companyId);

        return (bool) $s->withholding_on && ! $s->isOwnerMode();
    }

    /**
     * What the agency's percents come to for a month, per landlord who has one: rent the agency collected (not fees, not what a tenant paid the landlord directly, net of
     * reversals, by the day it was applied), the figure, what is recorded for that month, and what is left. Read only.
     *
     * @param  string  $month  Y-m
     * @return list<array{landlord_id: int, name: string, rate_bp: int, rent_minor: int, suggested_minor: int, recorded_minor: int, left_minor: int}>
     */
    public static function suggestions(int $companyId, string $month): array
    {
        if (! self::on($companyId)) {
            return [];
        }
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->where('withholding_bp', '>', 0)->orderBy('name')->orderBy('id')->get(['id', 'name', 'withholding_bp']);
        if ($landlords->isEmpty()) {
            return [];
        }
        $ids = $landlords->pluck('id')->all();
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $rent = array_map('intval', DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.company_id', $companyId)->whereIn('c.landlord_id', $ids)->where('c.kind', 'rent')->whereNotIn('p.method', ['waiver', RentalPayment::DIRECT])->whereBetween('a.applied_on', [$from, $to])
            ->groupBy('c.landlord_id')->selectRaw('c.landlord_id, SUM(a.amount_minor) AS total')->pluck('total', 'landlord_id')->all());
        $recorded = array_map('intval', RentalExpense::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('landlord_id', $ids)->where('category', self::CATEGORY)->where('for_month', $month)->whereNull('reversed_at')
            ->groupBy('landlord_id')->selectRaw('landlord_id, SUM(amount_minor) AS total')->pluck('total', 'landlord_id')->all());
        $out = [];
        foreach ($landlords as $l) {
            $id = (int) $l->id;
            $base = max(0, $rent[$id] ?? 0);
            $figure = Minor::percent($base, (int) $l->withholding_bp);
            $done = $recorded[$id] ?? 0;
            $out[] = ['landlord_id' => $id, 'name' => (string) $l->name, 'rate_bp' => (int) $l->withholding_bp, 'rent_minor' => $base, 'suggested_minor' => $figure, 'recorded_minor' => $done, 'left_minor' => max(0, $figure - $done)];
        }

        return $out;
    }

    /**
     * It has been paid over: an expense charged to the landlord (rentals_expenses), dated the day the money went, for the month it belongs to. The amount is whatever
     * the office typed. $data: amount_minor, method, paid_on, vendor, receipt_uuid.
     *
     * @param  string  $month  Y-m
     */
    public function record(User $by, RentalLandlord $landlord, string $month, array $data): RentalExpense
    {
        $cid = (int) $landlord->company_id;
        if ((int) $by->company_id !== $cid) {
            throw BusinessRuleException::make('not_found', 'That landlord was not found.');
        }
        if (! self::on($cid)) {
            throw BusinessRuleException::make('withholding_off', 'Holding money back is switched off for this account. Turn it on in Settings first.');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw BusinessRuleException::make('month_invalid', 'Choose the month this is for.');
        }

        return app(Expenses::class)->record($by, ['category' => self::CATEGORY, 'description' => RentalLedger::lateMark($month.'-01', LocalDate::today($cid)), 'for_month' => $month, 'charged_to' => 'landlord',
            'amount_minor' => $data['amount_minor'] ?? null, 'method' => $data['method'] ?? 'bank', 'paid_on' => $data['paid_on'] ?? null, 'vendor' => $data['vendor'] ?? null, 'receipt_uuid' => $data['receipt_uuid'] ?? null], $landlord);
    }
}
