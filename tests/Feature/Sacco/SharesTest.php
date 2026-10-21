<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Shares\ShareService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.11 (04 §3.3, §5.1): share purchases at par (whole shares only), the entrance fee, the one-third cap,
 * transfers between members through an approval, the activation and nomination rules, and the books: Σ share
 * accounts = 3100. There is no way to withdraw shares (no method, no route).
 */
class SharesTest extends ApiTestCase
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

    private function buy(Company $c, int $memberId, int $minor, $by, array $extra = []): SaccoTransaction
    {
        return app(ShareService::class)->purchase($c, ['member_id' => $memberId, 'amount_minor' => $minor] + $extra, $by)['transaction'];
    }

    private function capital(Company $c): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'shares.capital'))->value('balance_minor');
    }

    public function test_shares_are_bought_whole_at_par_and_the_sub_ledger_equals_share_capital(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = $this->activeMember($c, $s['owner']);
        $tx = $this->buy($c, (int) $m->id, 50_000, $s['teller']);
        $this->assertSame([5, 'share_purchase', 'posted'], [$tx->shares_count, $tx->kind, $tx->status]);
        $acc = SaccoShareAccount::withoutGlobalScopes()->where('member_id', $m->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^SHR-\d{6}$/', $acc->account_no);
        $this->assertSame([5, 50_000], [$acc->shares_count, $acc->balance_minor]);
        $this->assertSame('not_whole_shares', $this->code(fn () => $this->buy($c, (int) $m->id, 15_000, $s['teller'])));
        $byCount = app(ShareService::class)->purchase($c, ['member_id' => $m->id, 'shares_count' => 2, 'channel' => 'bank'], $s['teller'])['transaction'];
        $this->assertSame(20_000, $byCount->amount_minor);
        $this->assertSame(70_000, $this->capital($c));
        $this->assertSame(7, $acc->fresh()->shares_count);
        $this->assertSame('ledger_immutable', $this->code(fn () => $acc->fresh()->forceFill(['shares_count' => 99])->save()));
        $this->assertFalse(method_exists(ShareService::class, 'withdraw'), 'shares are capital: never withdrawn in office mode');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $c->id)));
    }

    public function test_no_member_passes_one_third_once_three_members_hold_shares(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        [$a, $b, $d] = [$this->activeMember($c, $s['owner'], 'A'), $this->activeMember($c, $s['owner'], 'B'), $this->activeMember($c, $s['owner'], 'D')];
        $this->buy($c, (int) $a->id, 100_000, $s['teller']); // 10 shares: one holder, no cap yet
        $this->buy($c, (int) $b->id, 100_000, $s['teller']);
        $this->buy($c, (int) $d->id, 100_000, $s['teller']); // 30 shares, 10 each
        $this->assertSame('share_cap_exceeded', $this->code(fn () => $this->buy($c, (int) $a->id, 10_000, $s['teller'])), '11 of 31 is above one third');
        $this->buy($c, (int) $this->activeMember($c, $s['owner'], 'E')->id, 100_000, $s['teller']); // 40 shares
        $this->buy($c, (int) $a->id, 30_000, $s['teller']); // 13 of 43
        $this->assertSame('posted', $this->buy($c, (int) $a->id, 20_000, $s['teller'])->status, '15 of 45 is exactly one third (3333 bp)');
        $this->assertSame('share_cap_exceeded', $this->code(fn () => $this->buy($c, (int) $a->id, 10_000, $s['teller'])), '16 of 46');
    }

    public function test_the_entrance_fee_and_minimum_shares_come_before_activation(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = app(MemberService::class)->register($c, ['first_name' => 'Nakato', 'last_name' => 'Ruth', 'nin' => 'CF900101'.Str::upper(Str::random(6)), 'consent_data' => true], $s['teller'])['member'];
        app(MemberService::class)->verifyKyc($c, (int) $m->id, $s['owner'], 'verified');
        $this->assertSame('entrance_fee_required', $this->code(fn () => app(MemberService::class)->setStatus($c, (int) $m->id, 'active', $s['owner'])));
        $this->assertSame('wrong_entrance_fee', $this->code(fn () => app(ShareService::class)->payEntranceFee($c, ['member_id' => $m->id, 'amount_minor' => 5_000], $s['teller'])));
        $fee = app(ShareService::class)->payEntranceFee($c, ['member_id' => $m->id], $s['teller'])['transaction'];
        $this->assertSame(20_000, $fee->amount_minor);
        $this->assertSame(20_000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'inc.entrance'))->value('balance_minor'));
        $this->assertSame('entrance_fee_paid', $this->code(fn () => app(ShareService::class)->payEntranceFee($c, ['member_id' => $m->id], $s['teller'])));
        $this->assertSame('min_shares_required', $this->code(fn () => app(MemberService::class)->setStatus($c, (int) $m->id, 'active', $s['owner'])));
        $this->buy($c, (int) $m->id, 50_000, $s['teller']);
        $this->assertSame('active', app(MemberService::class)->setStatus($c, (int) $m->id, 'active', $s['owner'])->status);
    }

    public function test_two_nominees_need_more_than_one_share(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = $this->activeMember($c, $s['owner']);
        $two = [['name' => 'Child One', 'share_bp' => 5000], ['name' => 'Child Two', 'share_bp' => 5000]];
        $this->buy($c, (int) $m->id, 10_000, $s['teller']);
        $this->assertSame('nomination_needs_shares', $this->code(fn () => app(MemberService::class)->nominate($c, (int) $m->id, $two, 'W One', 'W Two', null, $s['owner'])));
        $this->buy($c, (int) $m->id, 10_000, $s['teller']);
        $this->assertCount(2, app(MemberService::class)->nominate($c, (int) $m->id, $two, 'W One', 'W Two', null, $s['owner']));
    }

    public function test_a_transfer_waits_for_a_second_officer_and_a_reversal_takes_the_shares_back(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        [$a, $b] = [$this->activeMember($c, $s['owner'], 'A'), $this->activeMember($c, $s['owner'], 'B')];
        $buy = $this->buy($c, (int) $a->id, 80_000, $s['teller']);
        $this->assertSame('not_enough_shares', $this->code(fn () => app(ShareService::class)->transfer($c, (int) $a->id, (int) $b->id, 9, 'Sale', $s['teller'])));
        $req = app(ShareService::class)->transfer($c, (int) $a->id, (int) $b->id, 3, 'Sale between members', $s['teller']);
        $this->assertSame(0, ShareService::sharesOf((int) $c->id, (int) $b->id), 'nothing moves before approval');
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer']);
        $this->assertSame([5, 3], [ShareService::sharesOf((int) $c->id, (int) $a->id), ShareService::sharesOf((int) $c->id, (int) $b->id)]);
        $this->assertSame(30_000, (int) SaccoShareAccount::withoutGlobalScopes()->where('member_id', $b->id)->value('balance_minor'));
        $this->assertSame(80_000, $this->capital($c), 'a transfer moves capital between members, never in or out');

        $rev = app(SaccoTransactions::class)->reverse($c, (int) $buy->id, 'Wrong member', $s['teller']);
        $this->assertContains($this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $rev->id, $s['treasurer'])), ['insufficient_balance', 'not_enough_shares'], 'three of those shares were sold on');
        $this->assertSame(5, ShareService::sharesOf((int) $c->id, (int) $a->id));
        $buy2 = $this->buy($c, (int) $b->id, 20_000, $s['teller']);
        app(SaccoApprovals::class)->approve($c, (int) app(SaccoTransactions::class)->reverse($c, (int) $buy2->id, 'Double entry', $s['teller'])->id, $s['treasurer']);
        $this->assertSame(3, ShareService::sharesOf((int) $c->id, (int) $b->id));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $c->id)));
    }

    public function test_a_phone_share_purchase_that_cannot_post_is_held_never_refused(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = $this->activeMember($c, $s['owner']);
        $svc = app(SaccoTransactions::class);
        $odd = $svc->applySynced($c, ['kind' => 'share_purchase', 'member_id' => $m->id, 'amount_minor' => 15_000, 'receipt_no' => 'P1-0007'], $s['teller'], 'dev-1', (string) Str::uuid());
        $this->assertSame(['applied', 'held'], [$odd['status'], $odd['code']]);
        $this->assertSame(['held', 'not_whole_shares', 'P1-0007'], [$odd['model']->status, $odd['model']->hold_reason, $odd['model']->device_receipt_no]);
        $this->assertSame(15_000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'suspense.receipts'))->value('balance_minor'));
        $ok = $svc->applySynced($c, ['kind' => 'share_purchase', 'member_id' => $m->id, 'amount_minor' => 20_000], $s['teller'], 'dev-1', (string) Str::uuid());
        $this->assertSame('posted', $ok['model']->status);
        $this->assertSame(2, ShareService::sharesOf((int) $c->id, (int) $m->id));

        // Allocating a held receipt to the share account buys whole shares.
        $acc = SaccoShareAccount::withoutGlobalScopes()->where('member_id', $m->id)->firstOrFail();
        $req = app(SaccoApprovals::class)->request($c, 'held_receipt_allocation', ['held_entry_id' => $odd['model']->journal_entry_id, 'amount_minor' => 10_000,
            'target' => ['type' => 'shares', 'id' => $acc->id, 'account_id' => $acc->ledger_account_id, 'member_id' => $m->id]], $s['teller']);
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer']);
        $this->assertSame(3, ShareService::sharesOf((int) $c->id, (int) $m->id));
        $this->assertSame('held', $odd['model']->fresh()->status, '5,000 of it is still unallocated');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $c->id)));
    }
}
