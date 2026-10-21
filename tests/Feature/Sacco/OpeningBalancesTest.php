<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoPeriod;
use App\Models\SaccoSavingsAccount;
use App\Services\Sacco\Ledger\AccountStatement;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\OpeningBalances;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\TrialBalance;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Shares\ShareService;
use App\Support\LocalDate;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.13 (04 §5.1, §12, §13.2): opening balances against 3900 through an approval (3900 nets to zero
 * after a complete fictional import; an incomplete one shows the gap), the trial balance (equal to a closed month's
 * snapshot), account statements, and manual journals posted only after a second officer approves.
 */
class OpeningBalancesTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    public function test_a_complete_fictional_import_nets_3900_to_zero_and_an_incomplete_one_shows_the_gap(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $a = $this->activeMember($c, $s['owner'], 'Opio');
        $b = $this->activeMember($c, $s['owner'], 'Auma');
        $svc = app(OpeningBalances::class);
        $asOf = LocalDate::today($cid)->startOfMonth()->toDateString();

        $members = "member_no,name,shares,savings_ORD,savings_CMP\n{$a->member_no},Opio,10,250000,100000\n{$b->member_no},Auma,5,1250000,\n";
        $preview = $svc->preview($c, 'members', $members, $asOf);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(250000 + 100000 + 1250000 + 150000, $preview['totals']['credit_minor']);
        $bad = $svc->preview($c, 'members', "member_no,shares,savings_XYZ,loan_principal\nM-999999,1,5,\n{$a->member_no},1.5,,\n{$a->member_no},,,40000\n", $asOf);
        $this->assertCount(3, $bad['errors'], 'unknown member, a part share, an opening loan before the loans module');
        $this->assertSame('invalid_import', $this->code(fn () => $svc->request($c, 'members', "member_no,shares\nM-999999,1\n", $s['treasurer'], $asOf)));

        $req = $svc->request($c, 'members', $members, $s['treasurer'], $asOf);
        $this->assertSame('self_approval', $this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer'])));
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['owner']);
        $gap = OpeningBalances::status($cid);
        $this->assertSame(-1_750_000, $gap['opening_minor'], 'members alone leave 3900 owing what the books must still show they hold');
        $this->assertFalse($gap['complete']);
        $this->assertSame('warn', (new LedgerVerifier())->verify($cid)['checks']['opening']['status']);
        $this->assertSame(10, ShareService::sharesOf($cid, (int) $a->id));
        $this->assertSame(1_250_000, (int) SaccoSavingsAccount::withoutGlobalScopes()->where('member_id', $b->id)->value('balance_minor'));
        $entry = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'opening_balance')->firstOrFail();
        $this->assertSame([$asOf, 'open:'.$req->uuid.':2'], [$entry->entry_date->toDateString(), $entry->idempotency_key]);

        $this->assertSame('invalid_import', $this->code(fn () => $svc->request($c, 'ledger', "account_code,debit,credit\n2110,5000,\n", $s['treasurer'], $asOf)), 'control accounts come from member rows');
        $ledger = "account_code,debit,credit,memo\n1010,450000,,Cash counted\n1100,1500000,,Stanbic statement\n3200,,200000,Statutory reserve\n";
        app(SaccoApprovals::class)->approve($c, (int) $svc->request($c, 'ledger', $ledger, $s['treasurer'], $asOf)->id, $s['owner']);
        $done = OpeningBalances::status($cid);
        $this->assertSame([0, true], [$done['opening_minor'], $done['complete']]);
        $verify = (new LedgerVerifier())->verify($cid);
        $this->assertSame(['ok', []], [$verify['checks']['opening']['status'], LedgerVerifier::failed($verify)]);
        $tb = app(TrialBalance::class)->between($cid, null, LocalDate::today($cid));
        $this->assertTrue($tb['balanced']);
        $this->assertSame(1_950_000, $tb['totals']['debit_balance_minor']);
    }

    public function test_the_trial_balance_equals_the_closed_months_snapshot_and_statements_run(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-10 10:00:00', 'Africa/Kampala'));
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $acc = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['teller']);
        $this->deposit($c, $acc, 300_000, $s['teller']);
        $this->withdraw($c, $acc, 120_000, $s['teller']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-03 10:00:00', 'Africa/Kampala'));
        (new PeriodService())->ensureAhead($cid, now(), 2);
        $this->deposit($c, $acc, 50_000, $s['teller']);
        $sept = SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('year', 2026)->where('month', 9)->firstOrFail();
        app(SaccoApprovals::class)->approve($c, (int) app(SaccoApprovals::class)->request($c, 'period_close', ['period_id' => $sept->id], $s['treasurer'])->id, $s['owner']);
        $fromSnapshot = app(TrialBalance::class)->forPeriod($cid, $sept->fresh());
        $fromLines = app(TrialBalance::class)->between($cid, '2026-09-01', '2026-09-30');
        $strip = fn ($tb) => array_map(fn ($r) => array_intersect_key($r, array_flip(['code', 'opening_minor', 'debits_minor', 'credits_minor', 'closing_minor'])), $tb['rows']);
        $this->assertSame($strip($fromLines), $strip($fromSnapshot));
        $this->assertTrue($fromSnapshot['balanced']);

        $st = app(AccountStatement::class)->member($cid, 'savings', (int) $acc->id, '2026-10-01', '2026-10-31');
        $this->assertSame([180_000, 230_000, 1], [$st['opening_minor'], $st['closing_minor'], count($st['lines'])]);
        $this->assertNotNull($st['lines'][0]['receipt_no']);
        $cash = app(AccountStatement::class)->ledger($cid, ChartOfAccounts::id($cid, 'cash.office'), null, '2026-10-31');
        $this->assertSame([300_000 - 120_000 + 50_000, 3], [$cash['closing_minor'], count($cash['lines'])]);
    }

    public function test_a_manual_journal_posts_only_after_a_second_officer_approves(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $payload = ['memo' => 'Bank charges for October', 'lines' => [
            ['account_id' => ChartOfAccounts::id($cid, 'exp.bank'), 'debit_minor' => 12_500],
            ['account_id' => ChartOfAccounts::id($cid, 'bank.main'), 'credit_minor' => 12_500],
        ]];
        $req = app(SaccoApprovals::class)->request($c, 'manual_journal', $payload, $s['treasurer']);
        $this->assertSame(0, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'manual')->count(), 'a draft until approved');
        $this->assertSame('self_approval', $this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer'])));
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['owner']);
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'manual')->count());
        $this->assertSame('unbalanced_entry', $this->code(fn () => app(SaccoApprovals::class)->request($c, 'manual_journal', ['memo' => 'x', 'lines' => [
            ['account_id' => ChartOfAccounts::id($cid, 'exp.bank'), 'debit_minor' => 10], ['account_id' => ChartOfAccounts::id($cid, 'bank.main'), 'credit_minor' => 9]]], $s['treasurer'])));
    }
}
