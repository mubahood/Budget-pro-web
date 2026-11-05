<?php

namespace App\Services\Rentals\Reports;

use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;
use Illuminate\Support\Facades\DB;

/**
 * The commission report (RentalReports registers it, LC-16): all the rent collected in the period per landlord and property, the ones earning no commission
 * included, so the blended rate is true; the rule on what was collected and the rate it really came to; and what deserves a look. The total ties to the
 * commission on the allocations and so to the journal (rentals:check).
 */
final class CommissionReport
{
    use ReportTable;

    /** A flat fee above this share of the rent is listed: the most the earlier agency system ever charged. */
    public const FLAT_LIMIT_BP = 2500;

    /** A percent above this looks like an amount typed in the wrong box. */
    public const PERCENT_LIMIT_BP = 5000;

    /** A flat fee of this many whole units of money or fewer, and under 1% of rent, looks like a percent typed in the wrong box. */
    public const FLAT_LOOKS_LIKE_PERCENT = 100;

    /** @return array<string, mixed> */
    public function commission(object $x): array
    {
        $landlordId = $x->landlord !== null ? (int) $x->landlord->id : null;
        $narrow = $landlordId === null || $x->property !== null ? $x->ids : null; // a landlord chosen goes by the bill's landlord, not who owns the property today
        $rows = self::rows((int) $x->cid, (string) $x->from, (string) $x->to, $narrow, $landlordId);
        $gross = array_sum(array_column($rows, 'gross_minor'));
        $c = array_sum(array_column($rows, 'commission_minor'));
        $exceptions = self::exceptionsFrom((int) $x->cid, $rows, $narrow, $landlordId);
        $direct = array_sum(array_column($rows, 'direct_minor'));
        $notes = $direct === 0 ? [] : [['title' => 'Notes', 'columns' => [self::c('note', 'Note')], 'totals' => [], 'rows' => [['note' => 'Rent collected includes '.Minor::format($direct, (int) $x->cid).' tenants paid landlords directly. It never came through the agency. The commission on it comes off the landlord\'s balance.']]]];

        return [
            'columns' => [self::c('landlord', 'Landlord'), self::c('property', 'Property'), self::c('rule', 'Rule'), self::c('gross_minor', 'Rent collected', 'money'),
                self::c('commission_minor', 'Commission', 'money'), self::c('rate_bp', 'Effective', 'pct')],
            'rows' => $rows,
            'totals' => ['gross_minor' => $gross, 'commission_minor' => $c, 'rate_bp' => $gross > 0 ? Minor::share(10000, $c, $gross) : 0],
            'sections' => [...($exceptions === [] ? [] : [[
                'title' => 'To look at',
                'columns' => [self::c('what', 'What'), self::c('landlord', 'Landlord'), self::c('property', 'Property'), self::c('detail', 'Detail'), self::c('amount_minor', 'Amount', 'money')],
                'rows' => $exceptions,
            ]]), ...$notes],
        ];
    }

    /**
     * What deserves a look in a period, for this report and the month-end screen (it never blocks closing): rent collected with no commission, an effective
     * rate well under the account's usual, leases on a rule other than their property's, and fees that look mistyped.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<array{what: string, kind: string, landlord: string, property: string, detail: string, amount_minor: int}>
     */
    public static function exceptions(int $companyId, string $from, string $to, ?array $propertyIds = null, ?int $landlordId = null): array
    {
        return self::exceptionsFrom($companyId, self::rows($companyId, $from, $to, $propertyIds, $landlordId), $propertyIds, $landlordId);
    }

    /**
     * Per landlord and property: the rent collected (and whatever else carried commission), the commission and the rule. By the landlord on the bill, so a
     * handed-over property shows under who earned the money.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<array{landlord: string, property: string, rule: string, gross_minor: int, commission_minor: int, rate_bp: int, none_minor: int, direct_minor: int}>
     */
    private static function rows(int $cid, string $from, string $to, ?array $propertyIds, ?int $landlordId): array
    {
        $list = DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.company_id', $cid)->whereBetween('a.applied_on', [$from, $to])->where('p.method', '!=', 'waiver')
            ->where(fn ($q) => $q->where('c.commission_type', '!=', 'none')->orWhere(fn ($r) => $r->whereIn('c.kind', ['rent', 'opening'])->where('c.goes_to', 'landlord')))
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('c.property_id', $propertyIds ?: [0]))
            ->when($landlordId !== null, fn ($q) => $q->where('c.landlord_id', $landlordId))
            ->groupBy('c.landlord_id', 'c.property_id', 'c.commission_type', 'c.commission_bp')
            ->selectRaw('c.landlord_id, c.property_id, c.commission_type, c.commission_bp, SUM(a.amount_minor) AS gross, SUM(a.commission_minor) AS commission, SUM(CASE WHEN p.method = ? THEN a.amount_minor ELSE 0 END) AS direct', [RentalPayment::DIRECT])->get();
        $landlords = self::namesOf('rental_landlords', $list->pluck('landlord_id')->all(), $cid);
        $props = self::namesOf('rental_properties', $list->pluck('property_id')->all(), $cid);
        $rows = [];
        foreach ($list->groupBy(fn ($r) => $r->landlord_id.'|'.$r->property_id) as $group) {
            $first = $group->first();
            $gross = (int) $group->sum(fn ($r) => (int) $r->gross);
            if ($gross === 0 && (int) $group->sum(fn ($r) => (int) $r->commission) === 0) {
                continue; // a payment and its reversal in the same period: nothing was collected here
            }
            $c = (int) $group->sum(fn ($r) => (int) $r->commission);
            $rules = $group->filter(fn ($r) => (int) $r->gross !== 0)->map(fn ($r) => match ($r->commission_type) {
                'percent' => Billing::ruleWords($cid, 'percent', (int) $r->commission_bp, 0),
                'flat' => 'Flat',
                default => 'None',
            })->unique()->values();
            $rows[] = ['landlord' => $landlords[$first->landlord_id] ?? '', 'property' => $props[$first->property_id] ?? '', 'rule' => $rules->count() > 3 ? 'Mixed' : $rules->implode(' and '),
                'gross_minor' => $gross, 'commission_minor' => $c, 'rate_bp' => $gross > 0 ? Minor::share(10000, $c, $gross) : 0,
                'none_minor' => (int) $group->filter(fn ($r) => $r->commission_type === 'none')->sum(fn ($r) => (int) $r->gross), 'direct_minor' => (int) $group->sum(fn ($r) => (int) $r->direct)];
        }
        usort($rows, fn ($a, $b) => [$a['landlord'], $a['property']] <=> [$b['landlord'], $b['property']]);

        return $rows;
    }

    /**
     * @param  list<array{landlord: string, property: string, rule: string, gross_minor: int, commission_minor: int, rate_bp: int, none_minor: int, direct_minor: int}>  $rows
     * @param  list<int>|null  $propertyIds
     * @return list<array{what: string, kind: string, landlord: string, property: string, detail: string, amount_minor: int}>
     */
    private static function exceptionsFrom(int $cid, array $rows, ?array $propertyIds, ?int $landlordId): array
    {
        $s = RentalsModule::settings($cid);
        $out = [];
        $line = fn (string $kind, string $what, string $landlord, string $property, string $detail, int $amount) => ['what' => $what, 'kind' => $kind, 'landlord' => $landlord, 'property' => $property, 'detail' => $detail, 'amount_minor' => $amount];
        $usual = $s->commission_type === 'percent' ? (int) $s->commission_bp : null;
        $gap = (int) ($s->commission_gap_bp ?? 300);
        foreach ($rows as $r) {
            if ($r['none_minor'] > 0) {
                $out[] = $line('no_commission', 'Collected with no commission', $r['landlord'], $r['property'], 'Nothing earned on this rent', $r['none_minor']);
            }
            if ($usual !== null && $r['gross_minor'] > 0 && $r['none_minor'] < $r['gross_minor'] && $r['rate_bp'] < $usual - $gap) {
                $out[] = $line('low_rate', 'Well under your usual rate', $r['landlord'], $r['property'], 'Came to '.self::pct($r['rate_bp']).' against '.self::pct($usual), $r['commission_minor']);
            }
        }
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE)
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds ?: [0]))->when($landlordId !== null, fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['property', 'landlord', 'unit:id,code,commission_type,commission_bp,commission_minor'])->orderBy('property_id')->orderBy('id')->get();
        $decimals = Minor::decimals($cid);
        foreach ($leases as $l) {
            $who = (string) $l->landlord?->name;
            $where = (string) $l->property?->name;
            $unit = (string) $l->unit?->code;
            $mine = ['commission_type' => (string) $l->commission_type, 'commission_bp' => (int) $l->commission_bp, 'commission_minor' => (int) $l->commission_minor];
            if ($l->property !== null) {
                $theirs = Billing::commissionChain($l->property, $l->landlord, null, $s);
                if (! self::sameRule($mine, $theirs)) {
                    $words = fn (array $w) => Billing::ruleWords($cid, $w['commission_type'], $w['commission_bp'], $w['commission_minor']);
                    $onUnit = $l->unit !== null && $l->unit->commission_type !== null;
                    $out[] = $line('other_rule', 'Lease on another rule', $who, $where, "{$unit}: ".$words($mine).' against the property\'s '.$words($theirs).($onUnit ? ' (set on the unit)' : ''), self::monthlyOf($mine, (int) $l->rent_minor));
                }
            }
            if ($mine['commission_type'] === 'flat' && $mine['commission_minor'] > 0 && (int) $l->rent_minor > 0) {
                $rate = Minor::share(10000, $mine['commission_minor'], (int) $l->rent_minor);
                $major = intdiv($mine['commission_minor'], 10 ** $decimals);
                if ($rate > self::FLAT_LIMIT_BP) {
                    $out[] = $line('flat_high', 'Flat fee looks high', $who, $where, "{$unit}: ".self::pct($rate).' of rent', $mine['commission_minor']);
                } elseif ($major <= self::FLAT_LOOKS_LIKE_PERCENT && $rate < 100) {
                    $out[] = $line('flat_typed', 'Flat fee looks mistyped', $who, $where, "{$unit}: did you mean a percent?", $mine['commission_minor']);
                }
            }
            if ($mine['commission_type'] === 'percent' && $mine['commission_bp'] > self::PERCENT_LIMIT_BP) {
                $out[] = $line('percent_high', 'Percent looks mistyped', $who, $where, "{$unit}: ".self::pct($mine['commission_bp']).' of rent', self::monthlyOf($mine, (int) $l->rent_minor));
            }
        }

        return $out;
    }

    /** @param array{commission_type: string, commission_bp: int, commission_minor: int} $a @param array{commission_type: string, commission_bp: int, commission_minor: int} $b */
    private static function sameRule(array $a, array $b): bool
    {
        return $a['commission_type'] === $b['commission_type'] && ($a['commission_type'] !== 'percent' || $a['commission_bp'] === $b['commission_bp']) && ($a['commission_type'] !== 'flat' || $a['commission_minor'] === $b['commission_minor']);
    }

    /** @param array{commission_type: string, commission_bp: int, commission_minor: int} $rule */
    private static function monthlyOf(array $rule, int $rent): int
    {
        return match ($rule['commission_type']) {
            'percent' => Minor::percent($rent, $rule['commission_bp']),
            'flat' => $rule['commission_minor'],
            default => 0,
        };
    }

    private static function pct(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** @return array<int, string> */
    private static function namesOf(string $table, array $ids, int $cid): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : DB::table($table)->where('company_id', $cid)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
