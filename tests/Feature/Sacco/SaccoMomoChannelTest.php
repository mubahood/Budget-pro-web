<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoPayout;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P3.3 (04 §5.1, §11.2): mobile money as a way to pay out, end to end with the simulated driver. A
 * withdrawal moves the savings to 2160 and the wallet is paid; one above the threshold waits for a second officer
 * first; a refused payout leaves a reversal receipt and the money back in the account; an unanswered one waits in
 * 2160; a loan is paid out by MoMo through its approval; the driver `off` and group mode refuse the channel.
 */
class SaccoMomoChannelTest extends ApiTestCase
{
    use MakesSaccoLoans;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sacco.payouts.driver' => 'simulated', 'sacco.payouts.simulated_delay_seconds' => 0]);
    }

    private function balance(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    private function savings(int $id): int
    {
        return (int) DB::table('sacco_savings_accounts')->where('id', $id)->value('balance_minor');
    }

    /** @return array<string, string> */
    private function as(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_withdrawals_by_momo_paid_refused_waiting_and_approved(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $teller = $this->saccoStaff($company, 'sacco_treasurer');
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 3_000_000, $owner);
        $stx = app(SaccoTransactions::class);
        $fee = (int) \App\Models\SaccoSavingsProduct::find($account->product_id)->withdrawal_fee_minor;

        $paid = $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'momo', 'savings_account_id' => $account->id, 'amount_minor' => 200_000, 'momo_phone' => '0772123456'], $teller)['transaction'];
        $payout = SaccoPayout::find($paid->fresh()->payout_id);
        $this->assertSame(['posted', 'successful', 'withdrawal', '+256772123456'], [$paid->fresh()->status, $payout->status, $payout->purpose, $payout->phone_e164]);
        $this->assertSame(3_000_000 - 200_000 - $fee, $this->savings((int) $account->id));
        $this->assertSame([0, -200_000], [$this->balance($cid, 'payouts.in_transit'), $this->balance($cid, 'momo.float')], 'sent through 2160, paid from the float');
        $this->assertSame(1, DB::table('message_log')->where('company_id', $cid)->where('purpose', 'sacco_receipt')->where('body', 'like', '%'.$paid->fresh()->receipt_no.'%')->count(),
            'the receipt goes once the wallet is paid');

        $refused = $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'momo', 'savings_account_id' => $account->id, 'amount_minor' => 100_000, 'momo_phone' => '+256772123000'], $teller)['transaction'];
        $reversal = SaccoTransaction::where('reverses_id', $refused->id)->firstOrFail();
        $this->assertSame(['reversal', 'posted'], [$reversal->kind, $reversal->status]);
        $this->assertStringContainsString('Mobile-money payout failed', (string) $reversal->reason);
        $this->assertSame(3_000_000 - 200_000 - $fee, $this->savings((int) $account->id), 'the money is back');
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'));

        $waiting = $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'momo', 'savings_account_id' => $account->id, 'amount_minor' => 50_000, 'momo_phone' => '+256772123999'], $teller)['transaction'];
        $this->assertSame('unknown', SaccoPayout::find($waiting->fresh()->payout_id)->status);
        $this->assertSame(50_000, $this->balance($cid, 'payouts.in_transit'), 'in transit until the provider answers');
        $this->assertSame(0, (new PayoutService())->poll($cid));

        // Above the threshold: a second officer first, then the same payout path.
        $big = $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'momo', 'savings_account_id' => $account->id, 'amount_minor' => 1_500_000, 'momo_phone' => '+256772123456'], $teller)['transaction'];
        $this->assertSame(['pending_approval', null], [$big->status, $big->payout_id]);
        $this->assertSame('self_approval', $this->errorCode(fn () => app(SaccoApprovals::class)->approve($company, (int) $big->approval_id, $teller)));
        app(SaccoApprovals::class)->approve($company, (int) $big->approval_id, $this->loanStaffOf($company)['manager']);
        $big->refresh();
        $this->assertSame(['posted', 'successful'], [$big->status, SaccoPayout::find($big->payout_id)->status]);
        $this->assertSame((int) $big->approval_id, (int) SaccoPayout::find($big->payout_id)->approval_id);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        config(['sacco.payouts.driver' => 'off']);
        $this->assertSame('payouts_off', $this->errorCode(fn () => $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'momo', 'savings_account_id' => $account->id,
            'amount_minor' => 10_000, 'momo_phone' => '+256772123456'], $teller)));
        $this->assertSame('channel_not_available', $this->errorCode(fn () => $stx->record($company, ['kind' => 'withdrawal', 'channel' => 'checkoff', 'savings_account_id' => $account->id,
            'amount_minor' => 10_000], $teller)));
    }

    public function test_over_http_and_a_loan_paid_out_by_momo_through_its_approval(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 3_000_000, $owner);
        $treasurer = $this->as($this->loanTreasurer($company));
        $manager = $this->as($this->loanStaffOf($company)['manager']);
        $this->postJson('/api/v1/sacco/withdrawals', ['uuid' => (string) Str::uuid(), 'savings_account_id' => $account->id, 'amount_minor' => 60_000, 'channel' => 'momo', 'momo_phone' => 'not a phone'], $treasurer)
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_phone');
        $id = $this->postJson('/api/v1/sacco/withdrawals', ['uuid' => (string) Str::uuid(), 'savings_account_id' => $account->id, 'amount_minor' => 60_000, 'channel' => 'momo', 'momo_phone' => '0772111222'], $treasurer)
            ->assertCreated()->assertJsonPath('data.channel', 'momo')->json('data.id');
        $this->assertSame('successful', SaccoPayout::find(SaccoTransaction::find($id)->payout_id)->status);

        $product = $this->ordinaryProduct($company);
        SaccoLoanProduct::whereKey($product->id)->update(['allowed_channels' => json_encode(['savings', 'momo'])]);
        $loan = $this->signedLoan($company, $member, 400_000, 6, null, $product->fresh());
        $approval = $this->postJson("/api/v1/sacco/loans/{$loan->id}/disburse", ['channel' => 'momo', 'momo_phone_e164' => '+256772111333'], $treasurer)->assertStatus(202)->json('data.approval.id');
        $this->assertSame(0, SaccoPayout::where('subject_type', 'sacco_loans')->where('subject_id', $loan->id)->count(), 'nothing is sent before the approval');
        $this->postJson("/api/v1/sacco/approvals/{$approval}/approve", [], $manager)->assertOk();
        $this->assertSame('active', $loan->fresh()->status);
        $this->assertSame('successful', SaccoPayout::where('subject_type', 'sacco_loans')->where('subject_id', $loan->id)->value('status'));
        $this->assertSame('executed', SaccoApproval::find($approval)->status);
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_a_group_pays_out_in_cash(): void
    {
        [$company, $owner] = $this->loanSacco('group');
        $member = $this->loanMember($company, $owner);
        $code = \App\Models\SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $company->id)->value('code');
        $account = $this->loanSavings($company, $member, 100_000, $owner, (string) $code);
        $this->assertSame('channel_not_available', $this->errorCode(fn () => app(SaccoTransactions::class)->record($company, ['kind' => 'withdrawal', 'channel' => 'momo',
            'savings_account_id' => $account->id, 'amount_minor' => 1_000, 'momo_phone' => '+256772123456'], $this->saccoStaff($company, 'sacco_treasurer'))));
    }
}
