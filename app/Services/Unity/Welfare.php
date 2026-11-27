<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\UnityFundEntry;
use App\Models\UnityWelfareRequest;
use App\Models\User;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The welfare fund (M4): a simple book of money in (contributions, income) and out (payouts, expenses), and members'
 * requests for help (bereavement, sickness...) that officers approve and pay from it. Never the SACCO ledger.
 */
final class Welfare
{
    public const IN_KINDS = ['contribution', 'income', 'adjustment'];

    public const OUT_KINDS = ['payout', 'expense', 'adjustment'];

    public const REQUEST_KINDS = ['bereavement', 'sickness', 'wedding', 'birth', 'other'];

    public const REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'paid'];

    public static function balance(int $cid): int
    {
        $r = DB::table('unity_fund_entries')->where('company_id', $cid)->where('is_deleted', 0)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0) AS b")->first();

        return (int) $r->b;
    }

    /** @param array{direction: string, kind: string, amount_minor: int, member_id?: ?int, on?: ?string, method?: ?string, reference?: ?string, note?: ?string, request_id?: ?int} $d */
    public static function entry(int $cid, array $d, ?int $userId): UnityFundEntry
    {
        $direction = $d['direction'];
        $kinds = $direction === 'in' ? self::IN_KINDS : self::OUT_KINDS;
        if (! in_array($d['kind'], $kinds, true)) {
            throw BusinessRuleException::make('validation', 'That kind does not go with that direction.', ['kind' => ['Use one of: '.implode(', ', $kinds).'.']]);
        }
        if (! empty($d['member_id'])) {
            UnityMembers::find($cid, (int) $d['member_id']);
        }
        $on = LocalDate::date($cid, $d['on'] ?? null);
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('validation', 'That day has not come yet.', ['on' => ['Not in the future.']]);
        }

        return DB::transaction(function () use ($cid, $d, $direction, $on, $userId) {
            if ($direction === 'out' && self::balanceForUpdate($cid) < (int) $d['amount_minor']) {
                throw BusinessRuleException::make('insufficient_fund', 'The fund holds less than that. Record what came in first.');
            }

            return UnityFundEntry::create([
                'company_id' => $cid, 'direction' => $direction, 'kind' => $d['kind'], 'amount_minor' => (int) $d['amount_minor'],
                'member_id' => $d['member_id'] ?? null, 'request_id' => $d['request_id'] ?? null, 'on' => $on->toDateString(),
                'method' => $d['method'] ?? null, 'reference' => $d['reference'] ?? null, 'note' => $d['note'] ?? null,
                'receipt_no' => $direction === 'in' && $d['kind'] === 'contribution' ? NumberSequencer::next($cid, 'rct') : null,
                'created_by_id' => $userId,
            ]);
        });
    }

    /** The balance with the fund's rows locked, so two payouts at once cannot both spend the same money. */
    private static function balanceForUpdate(int $cid): int
    {
        \App\Models\UnitySetting::for($cid);
        DB::table('unity_settings')->where('company_id', $cid)->lockForUpdate()->first();

        return self::balance($cid);
    }

    public static function request(SaccoMember $m, string $kind, ?string $details, int $requested, ?int $userId): UnityWelfareRequest
    {
        if (! in_array($m->status, UnityMembers::CURRENT, true)) {
            throw BusinessRuleException::make('validation', 'Only a current member can ask the welfare fund for help.', ['member_id' => ['Not a current member.']]);
        }

        return UnityWelfareRequest::create(['company_id' => $m->company_id, 'member_id' => $m->id, 'kind' => $kind, 'details' => $details,
            'requested_minor' => $requested, 'status' => 'pending', 'requested_by_id' => $userId]);
    }

    public static function approve(UnityWelfareRequest $r, User $by, int $approved, ?string $note): UnityWelfareRequest
    {
        self::expect($r, ['pending'], 'Only a pending request is approved.');
        $r->fill(['status' => 'approved', 'approved_minor' => $approved, 'decided_by_id' => $by->id,
            'decided_on' => LocalDate::today((int) $r->company_id)->toDateString(), 'decision_note' => $note])->save();

        return $r->fresh();
    }

    public static function reject(UnityWelfareRequest $r, User $by, string $note): UnityWelfareRequest
    {
        self::expect($r, ['pending', 'approved'], 'Only a request not yet paid is turned down.');
        $r->fill(['status' => 'rejected', 'decided_by_id' => $by->id, 'decided_on' => LocalDate::today((int) $r->company_id)->toDateString(), 'decision_note' => $note])->save();

        return $r->fresh();
    }

    /** Pays an approved request: one `out` payout entry; 409 insufficient_fund when the fund holds less. */
    public static function pay(UnityWelfareRequest $r, User $by, ?string $method, ?string $on, ?string $reference): UnityWelfareRequest
    {
        self::expect($r, ['approved'], 'Only an approved request is paid.');

        return DB::transaction(function () use ($r, $by, $method, $on, $reference) {
            $entry = self::entry((int) $r->company_id, ['direction' => 'out', 'kind' => 'payout', 'amount_minor' => (int) $r->approved_minor,
                'member_id' => (int) $r->member_id, 'request_id' => (int) $r->id, 'on' => $on, 'method' => $method, 'reference' => $reference,
                'note' => ucfirst($r->kind).' support'], (int) $by->id);
            $r->fill(['status' => 'paid', 'paid_entry_id' => $entry->id])->save();

            return $r->fresh();
        });
    }

    /** @return array<string, mixed> */
    public static function summary(int $cid): array
    {
        $today = LocalDate::today($cid);
        $from = $today->copy()->startOfYear()->toDateString();
        $ytd = DB::table('unity_fund_entries')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween('on', [$from, $today->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor END), 0) AS i, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_minor END), 0) AS o")->first();
        $byStatus = DB::table('unity_welfare_requests')->where('company_id', $cid)->where('is_deleted', 0)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $kinds = DB::table('unity_welfare_requests')->where('company_id', $cid)->where('is_deleted', 0)
            ->selectRaw("kind, COUNT(*) AS n, COALESCE(SUM(CASE WHEN status = 'paid' THEN approved_minor END), 0) AS paid")->groupBy('kind')->get()->keyBy('kind');
        $months = DB::table('unity_fund_entries')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween('on', [$from, $today->toDateString()])
            ->selectRaw("MONTH(`on`) AS m, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor END), 0) AS i, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_minor END), 0) AS o")
            ->groupBy('m')->get()->keyBy('m');
        $byMonth = [];
        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $label) {
            $byMonth[] = ['month' => $label, 'in_minor' => (int) ($months[$i + 1]->i ?? 0), 'out_minor' => (int) ($months[$i + 1]->o ?? 0)];
        }

        return [
            'balance_minor' => self::balance($cid), 'in_ytd_minor' => (int) $ytd->i, 'out_ytd_minor' => (int) $ytd->o,
            'pending' => (int) ($byStatus['pending'] ?? 0), 'approved_unpaid' => (int) ($byStatus['approved'] ?? 0),
            'by_kind' => array_map(fn ($k) => ['kind' => $k, 'count' => (int) ($kinds[$k]->n ?? 0), 'paid_minor' => (int) ($kinds[$k]->paid ?? 0)], self::REQUEST_KINDS),
            'by_month' => $byMonth,
        ];
    }

    /** @return array<string, mixed> */
    public static function shapeEntry(object $e, array $names = []): array
    {
        return ['id' => (int) $e->id, 'direction' => $e->direction, 'kind' => $e->kind, 'amount_minor' => (int) $e->amount_minor,
            'member_id' => $e->member_id ? (int) $e->member_id : null, 'member_name' => $e->member_id ? ($names[(int) $e->member_id] ?? null) : null,
            'request_id' => $e->request_id ? (int) $e->request_id : null, 'on' => self::d($e->on), 'method' => $e->method,
            'reference' => $e->reference, 'receipt_no' => $e->receipt_no, 'note' => $e->note];
    }

    /** @return array<string, mixed> */
    public static function shapeRequest(object $r, array $names = [], bool $money = true): array
    {
        return ['id' => (int) $r->id, 'member_id' => (int) $r->member_id, 'member_name' => $names[(int) $r->member_id] ?? null, 'kind' => $r->kind,
            'details' => $r->details, 'requested_minor' => $money ? (int) $r->requested_minor : null,
            'approved_minor' => $money && $r->approved_minor !== null ? (int) $r->approved_minor : null, 'status' => $r->status,
            'decided_on' => self::d($r->decided_on), 'decision_note' => $r->decision_note, 'created_at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null];
    }

    /** @param iterable<int> $ids @return array<int, string> */
    public static function names(int $cid, iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('sacco_members')->where('company_id', $cid)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'other_names'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => Insights::name($r)])->all();
    }

    private static function d(mixed $v): ?string
    {
        return $v === null ? null : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10));
    }

    /** @param list<string> $statuses */
    private static function expect(UnityWelfareRequest $r, array $statuses, string $message): void
    {
        if (! in_array($r->status, $statuses, true)) {
            throw BusinessRuleException::make('validation', $message, ['status' => ["The request is {$r->status}."]]);
        }
    }
}
