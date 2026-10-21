<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoDormancyNotice;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoShareAccount;
use App\Models\SaccoStfBuyback;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Members\MemberExit;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\YearEnd\Dormancy;
use App\Services\Sacco\YearEnd\ShareTransferFund;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.9 (the Share Transfer Fund buys a leaving member's shares; at most 5 % of the share capital a year
 * for a licensed SACCO, a warning for any other) and P4.10 (the dormancy timeline: 2 years → register and notice,
 * 3 → advertise, 5 → transfer to the regulator, behind a second officer).
 */
class StfAndDormancyTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bal(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    /** A fund of $fund in 3300 (as an appropriation would leave it) and members holding $holdings, bought before this financial year. @return array{0: Company, 1: User, 2: User, 3: list<\App\Models\SaccoMember>} */
    private function fund(int $fund, array $holdings, bool $licensed = true): array
    {
        Carbon::setTestNow('2026-10-21 09:00:00');
        $s = $this->saccoCompany(licensed: $licensed);
        $c = $s['company'];
        $cid = (int) $c->id;
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2025-11-01'), 12);
        $members = [];
        foreach ($holdings as $i => $h) {
            $m = $this->activeMember($c, $s['owner'], 'Holder', chr(65 + $i));
            (new ShareService())->payEntranceFee($c, ['member_id' => $m->id], $s['owner'], ['entry_date' => '2025-12-01']);
            (new ShareService())->purchase($c, ['member_id' => $m->id, 'amount_minor' => $h, 'channel' => 'cash', 'value_date' => '2025-12-02'], $s['owner'], ['entry_date' => '2025-12-02']);
            $members[] = $m;
        }
        $money = Amount::of($fund, 'UGX');
        (new PostingService())->post(JournalDraft::make($cid, 'manual', 'test:stf')->debit(ChartOfAccounts::id($cid, 're.prior'), $money)->credit(ChartOfAccounts::id($cid, 'stf'), $money)
            ->dated('2026-03-20', '2026-03-20'));

        return [$c->fresh(), $s['owner'], $s['treasurer'], $members];
    }

    public function test_the_fund_buys_back_a_leaving_members_shares_and_the_exit_follows(): void
    {
        [$c, $owner, $treasurer, $m] = $this->fund(200_000, [1_000_000, 1_000_000, 1_000_000, 40_000]);
        $cid = (int) $c->id;
        $p = (new ShareTransferFund())->preview($cid, (int) $m[3]->id, ['channel' => 'cash']);
        $this->assertSame([40_000, 4, 152_000], [$p['amount_minor'], $p['shares_count'], $p['year_limit_minor']], '5 % of 3,040,000 at the year\'s start');
        $cash = $this->bal($cid, 'cash.office');
        $a = (new ShareTransferFund())->request($c, (int) $m[3]->id, ['channel' => 'cash', 'reason' => 'Moving abroad'], $owner);
        $this->assertSame('self_approval', $this->code(fn () => (new SaccoApprovals())->approve($c, (int) $a->id, $owner)));
        $this->assertSame('executed', (new SaccoApprovals())->approve($c, (int) $a->id, $treasurer)->status);
        $acc = SaccoShareAccount::where('member_id', $m[3]->id)->firstOrFail();
        $this->assertSame([0, 0], [$acc->shares_count, $acc->balance_minor]);
        $this->assertSame([3_000_000, 160_000, 40_000, $cash - 40_000], [$this->bal($cid, 'shares.capital'), $this->bal($cid, 'stf'), $this->bal($cid, 'reserve.general'),
            $this->bal($cid, 'cash.office')], 'the fund pays; what it spent becomes a permanent reserve');
        $this->assertSame('stf_buyback', SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'stf:'.SaccoStfBuyback::where('company_id', $cid)->value('id'))->value('kind'));
        $this->assertSame('no_shares', $this->code(fn () => (new ShareTransferFund())->preview($cid, (int) $m[3]->id)));
        // with no shares left the exit only pays the savings
        $exit = (new MemberExit())->request($c, (int) $m[3]->id, $owner, ['reason' => 'voluntary']);
        $this->assertNull($exit->payload['settlement']['shares']);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_the_fund_never_pays_more_than_it_holds_and_the_yearly_limit_binds_a_licensed_sacco_only(): void
    {
        [$c, $owner, , $m] = $this->fund(500_000, [1_000_000, 1_000_000, 1_000_000, 400_000]);
        $cid = (int) $c->id;
        $this->assertSame('stf_limit_exceeded', $this->code(fn () => (new ShareTransferFund())->request($c, (int) $m[3]->id, [], $owner)), '400,000 > 5 % of 3,400,000');
        [$c2, $owner2, $treasurer2, $m2] = $this->fund(500_000, [1_000_000, 1_000_000, 1_000_000, 400_000], licensed: false);
        $p = (new ShareTransferFund())->preview((int) $c2->id, (int) $m2[3]->id);
        $this->assertSame(['stf_limit_exceeded'], array_column($p['warnings'], 'code'));
        $a = (new ShareTransferFund())->request($c2, (int) $m2[3]->id, [], $owner2);
        $this->assertSame('executed', (new SaccoApprovals())->approve($c2, (int) $a->id, $treasurer2)->status);
        $this->assertSame('stf_insufficient', $this->code(fn () => (new ShareTransferFund())->preview((int) $c2->id, (int) $m2[0]->id)), '1,000,000 > the 100,000 left');
    }

    public function test_the_dormancy_timeline_register_advertise_and_transfer(): void
    {
        Carbon::setTestNow('2021-01-10 09:00:00');
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['owner']);
        $this->deposit($c, $a, 250_000, $s['owner']);
        $quiet = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['owner']);
        $this->deposit($c, $quiet, 90_000, $s['owner']);
        $dormancy = new Dormancy();

        $at = function (string $day) use ($cid) {
            Carbon::setTestNow($day.' 09:00:00');
            (new PeriodService())->ensureAhead($cid, Carbon::parse($day), 1);
        };
        $at('2022-12-31');
        $this->assertSame(['registered' => 0], $dormancy->run($cid), 'not yet two years');
        $at('2023-01-10');
        $this->deposit($c, $a, 1_000, $s['owner']); // the member came in: that account stays active
        $this->assertSame(['registered' => 1], $dormancy->run($cid));
        $this->assertSame('dormant', $quiet->fresh()->status);
        $this->assertSame(['register', 'notice'], SaccoDormancyNotice::where('savings_account_id', $quiet->id)->orderBy('id')->pluck('stage')->all());
        $this->assertSame(['registered' => 0], $dormancy->run($cid), 'once');
        $this->assertSame([null], array_column($dormancy->register($cid), 'due'));

        $at('2024-01-15');
        $this->assertSame(['advertise'], array_column($dormancy->register($cid), 'due'), 'three years quiet');
        $at('2026-01-15');
        $row = $dormancy->register($cid)[0];
        $this->assertSame(['transfer', 60], [$row['due'], $row['months_quiet']]);
        $this->assertSame('advertise_first', $this->code(fn () => $dormancy->requestTransfer($c, (int) $quiet->id, $s['owner'])), 'a licensed SACCO advertises first');
        $this->assertSame(1, $dormancy->advertised($c, [(int) $quiet->id], 'New Vision, 20 Jan 2024, p. 31', '2024-01-20', $s['owner']));
        $approval = $dormancy->requestTransfer($c, (int) $quiet->id, $s['owner']);
        $this->assertSame('executed', (new SaccoApprovals())->approve($c, (int) $approval->id, $s['treasurer'])->status);
        $this->assertSame([0, 90_000], [$this->balanceOf($quiet->id), $this->bal($cid, 'unclaimed.regulator')]);
        $this->assertSame('transferred', $dormancy->register($cid)[0]['stage']);
        $this->assertSame('not_dormant', $this->code(fn () => $dormancy->requestTransfer($c, (int) $a->id, $s['owner'])));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_an_unlicensed_saccos_due_transfer_is_a_warning_and_a_deposit_wakes_the_account(): void
    {
        Carbon::setTestNow('2020-06-01 09:00:00');
        $s = $this->saccoCompany(licensed: false);
        $c = $s['company'];
        $cid = (int) $c->id;
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['owner']);
        $this->deposit($c, $a, 50_000, $s['owner']);
        Carbon::setTestNow('2025-07-01 09:00:00');
        (new PeriodService())->ensureAhead($cid, now(), 1);
        (new Dormancy())->run($cid);
        $row = (new Dormancy())->register($cid)[0];
        $this->assertSame('transfer', $row['due']);
        $this->assertNotNull($row['warning']);
        $approval = (new Dormancy())->requestTransfer($c, (int) $a->id, $s['owner']);
        $this->assertSame(['not_advertised'], $approval->payload['warnings']);
        $approval->forceFill(['status' => 'cancelled'])->save();
        $this->deposit($c, $a, 1_000, $s['owner']);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame([], (new Dormancy())->register($cid));
    }
}
