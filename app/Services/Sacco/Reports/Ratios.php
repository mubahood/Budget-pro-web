<?php

namespace App\Services\Sacco\Reports;

use App\Services\Sacco\Loans\LoanBooks;
use App\Support\Sacco\WorkingDays;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The prudential ratios (P4.4, 02 §1.3 and §7.1 item 10), from the statement of financial position (Figures):
 *
 * | key | ratio | limit | source |
 * |---|---|---|---|
 * | core_capital | core capital / total assets | ≥ 10 % | SI 51 reg. 27 |
 * | institutional_capital | institutional capital / total assets | ≥ 8 % | Form 7 |
 * | core_to_deposits | core capital / deposit liabilities | ≥ 8 % | Form 7 |
 * | liquidity | net liquid assets / (deposits + short-term liabilities), on the last working day of the week | ≥ 15 % | reg. 29 |
 * | savings_in_banks | bank balances and government securities / savings and deposits | ≥ 20 % | Coop s.55AI(3) |
 * | external_borrowing | external borrowings / total assets | ≤ 25 % | reg. 30 |
 * | non_earning | non-earning assets and property / total assets | ≤ 10 % | reg. 31 |
 * | investments | non-government financial investments / core capital | ≤ 40 % | reg. 37 |
 *
 * And the Bank of Uganda threshold (Tier 4 s.2): a SACCO with voluntary savings above UGX 1.5 bn **and**
 * institutional capital above UGX 500 m comes under the central bank; the dashboard warns from 80 % of either.
 * The labels shown to people name no law; the limits are the same for every SACCO (a ratio informs, it never refuses).
 */
final class Ratios
{
    /** key => [label, limit bp, direction (min | max)] */
    public const LIMITS = [
        'core_capital' => ['Core capital to total assets', 1000, 'min'],
        'institutional_capital' => ['Institutional capital to total assets', 800, 'min'],
        'core_to_deposits' => ['Core capital to deposits', 800, 'min'],
        'liquidity' => ['Liquidity (weekly, last working day)', 1500, 'min'],
        'savings_in_banks' => ['Savings held in banks or government securities', 2000, 'min'],
        'external_borrowing' => ['External borrowing to total assets', 2500, 'max'],
        'non_earning' => ['Non-earning assets and property to total assets', 1000, 'max'],
        'investments' => ['Non-government investments to core capital', 4000, 'max'],
    ];

    /** The central bank's thresholds, in UGX minor units (UGX has no minor unit). */
    public const BOU_SAVINGS_MINOR = 1_500_000_000;

    public const BOU_INSTITUTIONAL_MINOR = 500_000_000;

    /** Warn from this share of a threshold. */
    public const BOU_WARN_BP = 8000;

    /**
     * The ratios on a day; the liquidity ratio on the last working day of that day's week.
     *
     * @return array{as_of: string, liquidity_on: string, ratios: list<array<string, mixed>>, bou: array<string, mixed>, figures: array<string, int>}
     */
    public function compute(int $companyId, CarbonInterface|string $asOf): array
    {
        $day = Carbon::parse($asOf)->startOfDay();
        $position = Figures::position($companyId, $day);
        $f = self::figures($companyId, $position);
        $liquidOn = self::weekEnd($day);
        $lf = $liquidOn->equalTo($day) ? $f : self::figures($companyId, Figures::position($companyId, $liquidOn));
        $values = [
            'core_capital' => [$f['core_capital'], $f['total_assets']],
            'institutional_capital' => [$f['institutional_capital'], $f['total_assets']],
            'core_to_deposits' => [$f['core_capital'], $f['deposits']],
            'liquidity' => [$lf['net_liquid_assets'], $lf['deposits'] + $lf['short_term_liabilities']],
            'savings_in_banks' => [$f['bank_and_securities'], $f['deposits']],
            'external_borrowing' => [$f['external_borrowings'], $f['total_assets']],
            'non_earning' => [$f['non_earning'], $f['total_assets']],
            'investments' => [$f['non_gov_investments'], $f['core_capital']],
        ];
        $out = [];
        foreach (self::LIMITS as $key => [$label, $limit, $dir]) {
            [$num, $den] = $values[$key];
            $bp = Figures::bp($num, $den);
            $ok = $bp === null ? ($dir === 'max' || $num > 0) : ($dir === 'min' ? $bp >= $limit : $bp <= $limit);
            $out[] = ['key' => $key, 'label' => $label, 'value_bp' => $bp, 'limit_bp' => $limit, 'direction' => $dir, 'ok' => $ok,
                'numerator_minor' => $num, 'denominator_minor' => $den];
        }

        return ['as_of' => $day->toDateString(), 'liquidity_on' => $liquidOn->toDateString(), 'ratios' => $out, 'bou' => $this->bou($companyId, $f), 'figures' => $f];
    }

    /** The liquidity ratio on the last working day of each of the last $weeks weeks up to a day. @return list<array{on: string, value_bp: ?int, ok: bool}> */
    public function weekly(int $companyId, CarbonInterface|string $asOf, int $weeks = 8): array
    {
        $out = [];
        $end = self::weekEnd(Carbon::parse($asOf));
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $on = self::lastWorkingDayOfWeek($end->copy()->subWeeks($i));
            $f = self::figures($companyId, Figures::position($companyId, $on));
            $bp = Figures::bp($f['net_liquid_assets'], $f['deposits'] + $f['short_term_liabilities']);
            $out[] = ['on' => $on->toDateString(), 'value_bp' => $bp, 'ok' => $bp === null || $bp >= self::LIMITS['liquidity'][1]];
        }

        return $out;
    }

    /**
     * The figures the ratios and Forms 2–4 and 7 share.
     *
     * @param  array{lines: array<string, int>, totals: array<string, int>, accounts: array<string, list<array{code: string, name: string, minor: int}>>}  $position
     * @return array<string, int>
     */
    public static function figures(int $companyId, array $position): array
    {
        $l = $position['lines'];
        $capital = Figures::capital($position);
        [$tbills, $bonds] = self::securities($companyId, $position);

        return [
            'total_assets' => $position['totals']['assets'],
            'core_capital' => $capital['core'],
            'institutional_capital' => $capital['institutional'],
            'deposits' => $l['savings'] + $l['fixed_deposits'] + $l['non_withdrawable'] + $l['interest_payable'],
            'voluntary_savings' => $l['savings'] + $l['fixed_deposits'],
            'short_term_liabilities' => $l['payables'] + $l['tax_payable'] + $l['dividends_payable'] + $l['other_liabilities'],
            'net_liquid_assets' => $l['cash_hand'] + $l['cash_bank'] + $l['other_fi'] + $tbills + $bonds,
            'tbills' => $tbills,
            'tbonds' => $bonds,
            'bank_and_securities' => self::bank($companyId, $position) + $l['gov_securities'],
            'external_borrowings' => $l['external_borrowings'],
            'non_earning' => $l['cash_hand'] + $l['prepayments'] + $l['ppe'] + $l['intangibles'] + $l['other_assets'],
            'non_gov_investments' => $l['other_securities'] + $l['other_investments'],
            'land_and_buildings' => self::landAndBuildings($companyId, $position),
            'financial_investments' => $l['gov_securities'] + $l['other_securities'] + $l['other_investments'],
            'loans_net' => $l['loans_gross'] + $l['loan_allowance'],
        ];
    }

    /** @param array<string, int> $f @return array<string, mixed> */
    private function bou(int $companyId, array $f): array
    {
        if (LoanBooks::settings($companyId)->currency !== 'UGX') {
            return ['applies' => false];
        }
        $state = fn (int $v, int $limit) => $v >= $limit ? 'over' : ($v * 10000 >= $limit * self::BOU_WARN_BP ? 'approaching' : 'below');
        $savings = $state($f['voluntary_savings'], self::BOU_SAVINGS_MINOR);
        $capital = $state($f['institutional_capital'], self::BOU_INSTITUTIONAL_MINOR);
        $warn = $savings !== 'below' || $capital !== 'below';

        return ['applies' => true, 'voluntary_savings_minor' => $f['voluntary_savings'], 'savings_threshold_minor' => self::BOU_SAVINGS_MINOR, 'savings' => $savings,
            'institutional_capital_minor' => $f['institutional_capital'], 'capital_threshold_minor' => self::BOU_INSTITUTIONAL_MINOR, 'capital' => $capital,
            'warning' => ! $warn ? null : ($savings === 'over' && $capital === 'over'
                ? 'Voluntary savings and institutional capital are both above the central bank\'s thresholds: the SACCO would need a central bank licence.'
                : 'The SACCO is nearing the central bank\'s thresholds (UGX 1.5 bn in voluntary savings and UGX 500 m in institutional capital).')];
    }

    /**
     * The day the weekly liquidity is measured for a day: the last working day of its week (Monday to Sunday; a holiday
     * Friday moves it back), or of the week before when that day is still to come.
     */
    public static function weekEnd(CarbonInterface $day): Carbon
    {
        $on = Carbon::parse($day->toDateString())->startOfDay();
        $last = self::lastWorkingDayOfWeek($on);

        return $last->gt($on) ? self::lastWorkingDayOfWeek($on->copy()->subWeek()) : $last;
    }

    private static function lastWorkingDayOfWeek(Carbon $day): Carbon
    {
        $d = $day->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $monday = $d->copy()->subDays(6);
        while ($d->gt($monday) && ! WorkingDays::isWorkingDay($d)) {
            $d->subDay();
        }

        return $d;
    }

    /** @param array{accounts: array<string, list<array{code: string, minor: int}>>} $position @return array{0: int, 1: int} T-bills (1251…) and other government securities */
    private static function securities(int $companyId, array $position): array
    {
        $bills = 0;
        $other = 0;
        foreach ($position['accounts']['gov_securities'] ?? [] as $a) {
            str_starts_with($a['code'], '1251') ? $bills += $a['minor'] : $other += $a['minor'];
        }

        return [$bills, $other];
    }

    /** Bank accounts only (1100 and its children), not mobile money. @param array{accounts: array<string, list<array{code: string, minor: int}>>} $position */
    private static function bank(int $companyId, array $position): int
    {
        return (int) array_sum(array_map(fn ($a) => str_starts_with($a['code'], '11') && ! str_starts_with($a['code'], '115') && ! str_starts_with($a['code'], '116') ? $a['minor'] : 0,
            $position['accounts']['cash_bank'] ?? []));
    }

    /** @param array{accounts: array<string, list<array{code: string, minor: int}>>} $position */
    private static function landAndBuildings(int $companyId, array $position): int
    {
        return (int) array_sum(array_map(fn ($a) => str_starts_with($a['code'], '171') ? $a['minor'] : 0, $position['accounts']['ppe'] ?? []));
    }
}
