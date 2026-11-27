<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\UnityDrive;
use App\Models\UnityDrivePayment;
use App\Models\UnityPledge;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Levies and harambee drives (M5). A levy asks every current member in scope for the same amount (apply gives each a
 * pledge); a harambee collects what people pledge and give freely. Payments are numbered receipts and fill the pledge.
 */
final class Drives
{
    public const KINDS = ['levy', 'harambee'];

    /** Gives every current member in the drive's scope a pledge of per_member_minor. Safe to run again. @return int pledges added */
    public static function apply(UnityDrive $drive): int
    {
        if ($drive->kind !== 'levy') {
            throw BusinessRuleException::make('validation', 'Only a levy is applied to every member. A harambee takes pledges one by one.');
        }
        self::expectOpen($drive);
        if ((int) $drive->per_member_minor <= 0) {
            throw BusinessRuleException::make('validation', 'Set the amount each member pays first.', ['per_member_minor' => ['Required for a levy.']]);
        }
        $cid = (int) $drive->company_id;
        $have = DB::table('unity_pledges')->where('drive_id', $drive->id)->pluck('member_id')->map(fn ($i) => (int) $i)->all();
        $ids = MemberFilters::current($cid)->when($drive->chapter_id, fn ($q) => $q->where('chapter_id', $drive->chapter_id))
            ->whereNotIn('id', $have ?: [0])->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $now = now();
        $seq = SyncSequence::reserve($ids->count());
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'drive_id' => $drive->id, 'member_id' => (int) $id,
                'pledged_minor' => (int) $drive->per_member_minor, 'paid_minor' => 0, 'due_on' => $drive->due_on?->toDateString(), 'status' => 'open',
                'server_seq' => $seq++, 'version' => 1, 'is_deleted' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('unity_pledges')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /** Adds a pledge, or raises one already there to the new amount. */
    public static function pledge(UnityDrive $drive, SaccoMember $m, int $amount, ?string $dueOn): UnityPledge
    {
        self::expectOpen($drive);
        self::inScope($drive, $m);
        $p = UnityPledge::withoutGlobalScopes()->where('drive_id', $drive->id)->where('member_id', $m->id)->first();
        if ($p !== null && $amount < (int) $p->paid_minor) {
            throw BusinessRuleException::make('validation', 'A pledge is never below what is already paid.', ['pledged_minor' => ['At least '.$p->paid_minor.'.']]);
        }
        $p ??= new UnityPledge(['company_id' => $drive->company_id, 'drive_id' => $drive->id, 'member_id' => $m->id, 'paid_minor' => 0]);
        $p->pledged_minor = $amount;
        $p->due_on = $dueOn ?? $p->due_on ?? $drive->due_on?->toDateString();
        $p->status = (int) $p->paid_minor >= $amount && $amount > 0 ? 'paid' : 'open';
        $p->is_deleted = false;
        $p->save();

        return $p->fresh();
    }

    /**
     * A member pays towards the drive: a numbered receipt, the pledge filled. On a harambee, paying more than the pledge (or
     * with no pledge) raises it; on a levy that is refused (422 overpaid).
     *
     * @param  array{amount_minor: int, method?: ?string, paid_on?: ?string, reference?: ?string}  $d
     */
    public static function pay(UnityDrive $drive, SaccoMember $m, array $d, ?int $userId): UnityDrivePayment
    {
        self::expectOpen($drive);
        self::inScope($drive, $m);
        $cid = (int) $drive->company_id;
        $amount = (int) $d['amount_minor'];
        $on = LocalDate::date($cid, $d['paid_on'] ?? null);
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('validation', 'That day has not come yet.', ['paid_on' => ['Not in the future.']]);
        }

        return DB::transaction(function () use ($drive, $m, $amount, $on, $d, $cid, $userId) {
            $p = UnityPledge::withoutGlobalScopes()->where('drive_id', $drive->id)->where('member_id', $m->id)->lockForUpdate()->first();
            $after = (int) ($p?->paid_minor ?? 0) + $amount;
            if ($drive->kind === 'levy' && ($p === null || $after > (int) $p->pledged_minor)) {
                $owed = $p === null ? 0 : (int) $p->pledged_minor - (int) $p->paid_minor;
                throw BusinessRuleException::make('overpaid', $p === null ? 'This member owes nothing on this levy. Apply the levy first.'
                    : "This member owes {$owed} on this levy: take no more than that.", ['amount_minor' => ['More than is owed.']]);
            }
            $p ??= new UnityPledge(['company_id' => $cid, 'drive_id' => $drive->id, 'member_id' => $m->id, 'pledged_minor' => 0, 'paid_minor' => 0,
                'due_on' => $drive->due_on?->toDateString()]);
            $p->paid_minor = $after;
            $p->pledged_minor = max((int) $p->pledged_minor, $after); // a harambee gives freely
            $p->status = $after >= (int) $p->pledged_minor ? 'paid' : 'open';
            $p->save();

            return UnityDrivePayment::create(['company_id' => $cid, 'drive_id' => $drive->id, 'pledge_id' => $p->id, 'member_id' => $m->id,
                'amount_minor' => $amount, 'method' => $d['method'] ?? null, 'paid_on' => $on->toDateString(), 'reference' => $d['reference'] ?? null,
                'receipt_no' => NumberSequencer::next($cid, 'rct'), 'created_by_id' => $userId]);
        });
    }

    /** @return array{pledged_minor: int, paid_minor: int, pledgers: int, progress_pct: ?float, overdue: int} */
    public static function totals(UnityDrive $drive): array
    {
        $today = LocalDate::today((int) $drive->company_id)->toDateString();
        $r = DB::table('unity_pledges')->where('drive_id', $drive->id)->where('is_deleted', 0)->whereIn('status', ['open', 'paid'])
            ->selectRaw('COALESCE(SUM(pledged_minor), 0) AS pledged, COALESCE(SUM(paid_minor), 0) AS paid, COUNT(*) AS n,
                SUM(status = \'open\' AND paid_minor < pledged_minor AND COALESCE(due_on, ?) < ?) AS overdue', [$drive->due_on?->toDateString() ?? '9999-12-31', $today])->first();
        $goal = (int) ($drive->target_minor ?: $r->pledged);

        return ['pledged_minor' => (int) $r->pledged, 'paid_minor' => (int) $r->paid, 'pledgers' => (int) $r->n,
            'progress_pct' => $goal > 0 ? round((int) $r->paid * 100 / $goal, 1) : null, 'overdue' => (int) $r->overdue];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityDrive $d, bool $money = true): array
    {
        return ['id' => (int) $d->id, 'uuid' => $d->uuid, 'kind' => $d->kind, 'title' => $d->title, 'description' => $d->description,
            'target_minor' => $money && $d->target_minor !== null ? (int) $d->target_minor : null,
            'per_member_minor' => $money && $d->per_member_minor !== null ? (int) $d->per_member_minor : null,
            'due_on' => $d->due_on?->toDateString(), 'chapter_id' => $d->chapter_id ? (int) $d->chapter_id : null, 'status' => $d->status, 'version' => (int) $d->version];
    }

    private static function expectOpen(UnityDrive $drive): void
    {
        if ($drive->status !== 'open') {
            throw BusinessRuleException::make('not_open', 'This drive is closed.');
        }
    }

    private static function inScope(UnityDrive $drive, SaccoMember $m): void
    {
        if ((int) $m->company_id !== (int) $drive->company_id) {
            throw BusinessRuleException::make('not_found', 'That member was not found.');
        }
        if ($drive->chapter_id && (int) $m->chapter_id !== (int) $drive->chapter_id) {
            throw BusinessRuleException::make('validation', 'This drive is for another chapter\'s members.', ['member_id' => ['Not in the drive\'s chapter.']]);
        }
    }
}
