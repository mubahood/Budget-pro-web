<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoPeriod;
use App\Models\SaccoPeriodBalance;
use App\Services\Sacco\Ledger\CloseChecks;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.6 (ADR 0009 §8, AK-45, AK-53): calendar months. Closing writes a snapshot that matches the trial
 * balance and anchors the head hash; nothing posts into a closed month (a late item is posted today with its own
 * value date); months close in order, only after they end, and only on sound books; only the latest closed month
 * reopens, with a reason, and closing it again writes a new snapshot.
 */
class PeriodServiceTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    public function test_close_snapshots_the_trial_balance_and_nothing_posts_into_a_closed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $this->assertSame(3, SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->count(), 'setup opens three months ahead');
        $p = new PeriodService();
        (new PostingService())->post($this->simple($cid, 'txn:s1', 40000));
        (new PostingService())->post($this->simple($cid, 'txn:s2', 15000, 'bank.main', 'suspense.receipts', 'held_receipt'));
        $september = $p->find($cid, now());

        $this->assertSame('period_not_ended', $this->code(fn () => $p->close($cid, (int) $september->id, $user->id)));
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $closed = $p->close($cid, (int) $september->id, $user->id, 55);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(1, $closed->snapshot_no);
        $head = DB::table('sacco_ledger_heads')->where('company_id', $cid)->value('last_hash');
        $this->assertSame($head, $closed->close_hash, 'the head hash is the month\'s anchor');
        $this->assertSame(55, (int) $closed->approval_id);

        $snap = SaccoPeriodBalance::withoutGlobalScopes()->where('period_id', $closed->id)->get()->keyBy('ledger_account_id');
        $this->assertSame(PeriodService::balances($cid), $snap->map(fn ($r) => (int) $r->closing_minor)->all(), 'closing = the caches (nothing posted since)');
        $this->assertSame((int) $snap->sum('debits_minor'), (int) $snap->sum('credits_minor'), 'the trial balance totals zero');
        $this->assertSame($head, $snap->first()->head_hash);
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $cid)->where('action', 'sacco.period.closed')->exists());

        // Posting into September now: refused; today with September's value date: fine (AK-45).
        $this->assertSame('period_closed', $this->code(fn () => (new PostingService())->post($this->simple($cid, 'txn:late', 500)->dated('2026-09-30'))));
        $late = (new PostingService())->post($this->simple($cid, 'txn:late', 500)->dated(null, '2026-09-30'));
        $this->assertSame('2026-10-02', $late->entry_date->toDateString());
        $this->assertSame('2026-09-30', $late->value_date->toDateString());
        $this->assertSame('period_not_open', $this->code(fn () => $p->close($cid, (int) $september->id, $user->id)));

        // October opens from September's closing.
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
        $october = $p->close($cid, (int) $p->find($cid, Carbon::parse('2026-10-15'))->id, $user->id);
        $cash = \App\Services\Sacco\Ledger\ChartOfAccounts::id($cid, 'cash.office');
        $row = SaccoPeriodBalance::withoutGlobalScopes()->where('period_id', $october->id)->where('ledger_account_id', $cash)->first();
        $this->assertSame([40000, 500, 0, 40500], [$row->opening_minor, $row->debits_minor, $row->credits_minor, $row->closing_minor]);
    }

    public function test_months_close_in_order_on_sound_books_and_only_the_latest_reopens(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $p = new PeriodService();
        $july = $p->find($cid, now());
        $august = $p->find($cid, Carbon::parse('2026-08-01'));
        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $this->assertSame('earlier_period_open', $this->code(fn () => $p->close($cid, (int) $august->id, $user->id)));

        // Unsound books block the close.
        (new PostingService())->post($this->simple($cid, 'txn:x', 1000)->dated('2026-09-05'));
        DB::table('sacco_account_balances')->where('company_id', $cid)->where('balance_minor', 1000)->limit(1)->update(['balance_minor' => 7]);
        $this->assertSame('ledger_not_verified', $this->code(fn () => $p->close($cid, (int) $july->id, $user->id)));
        DB::table('sacco_account_balances')->where('company_id', $cid)->where('balance_minor', 7)->update(['balance_minor' => 1000]);

        // A module's own close check stops it too (the approvals engine registers one in P1.10).
        CloseChecks::register('test_block', fn () => throw BusinessRuleException::make('approvals_pending', 'Pending.'));
        $this->assertSame('approvals_pending', $this->code(fn () => $p->close($cid, (int) $july->id, $user->id)));
        $checks = (new \ReflectionClass(CloseChecks::class))->getProperty('checks');
        $checks->setValue(null, array_diff_key(CloseChecks::all(), ['test_block' => 1]));

        $p->close($cid, (int) $july->id, $user->id);
        $p->close($cid, (int) $august->id, $user->id);
        $this->assertSame('period_not_latest', $this->code(fn () => $p->reopen($cid, (int) $july->id, $user->id, 'Late receipt')));
        $this->assertSame('reason_required', $this->code(fn () => $p->reopen($cid, (int) $august->id, $user->id, ' ')));
        $reopened = $p->reopen($cid, (int) $august->id, $user->id, 'Bank charge missed', 77);
        $this->assertSame('open', $reopened->status);
        $this->assertSame(1, $reopened->reopen_count);
        $this->assertSame('Bank charge missed', $reopened->reopen_reason);
        $this->assertSame('period_not_closed', $this->code(fn () => $p->reopen($cid, (int) $august->id, $user->id, 'Again')));

        (new PostingService())->post($this->simple($cid, 'txn:aug', 2500, 'cash.office', 'inc.entrance')->dated('2026-08-31'));
        $again = $p->close($cid, (int) $august->id, $user->id);
        $this->assertSame(2, $again->snapshot_no);
        $this->assertSame(2, SaccoPeriodBalance::withoutGlobalScopes()->where('period_id', $august->id)->distinct()->count('snapshot_no'), 'the first snapshot is kept');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $cid)->where('action', 'sacco.period.reopened')->exists());
    }

    public function test_postings_open_a_missing_month_but_never_one_behind_a_closed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 10:00:00'));
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $this->travelTo(Carbon::parse('2026-08-10 10:00:00'));
        $entry = (new PostingService())->post($this->simple($cid, 'txn:aug', 100));
        $this->assertSame(8, $entry->period->month, 'a month nobody created yet is opened');
        SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('month', 3)->update(['status' => 'closed']);
        SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('month', 4)->delete();
        $this->assertSame('period_closed', $this->code(fn () => (new PostingService())->post($this->simple($cid, 'txn:feb', 100)->dated('2026-02-15'))));
    }
}
