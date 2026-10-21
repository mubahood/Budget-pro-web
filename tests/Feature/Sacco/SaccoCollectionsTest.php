<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\FlutterwaveService;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Concerns\SignsInSaccoMembers;
use Tests\Feature\Api\ApiTestCase;
use Tests\Feature\Api\FakeFlutterwaveService;

/**
 * Akiba Ledger P3.1 (04 §11.1): a request-to-pay posts nothing; a confirmed payment posts once however often it is
 * settled (webhook, poll, status check); a declined one posts nothing; a mismatched amount posts nothing; money for an
 * account that closed meanwhile is held in 2150; the driver `off` refuses; tellers and members over HTTP; another
 * SACCO's collections are 404. No network: tests bind a fake provider.
 */
class SaccoCollectionsTest extends ApiTestCase
{
    use MakesSaccoLoans;
    use SignsInSaccoMembers;

    private FakeFlutterwaveService $flw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
        config(['sacco.collections.driver' => 'flutterwave', 'flutterwave.secret_hash' => 'test-hash']);
    }

    private function balance(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    /** @return array<string, string> */
    private function as(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_settled_once_declined_nothing_mismatch_nothing(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $company->forceFill(['momo_subaccount_id' => 'RS_TEST'])->saveQuietly();
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 10_000, $owner);
        $svc = app(SaccoCollections::class);

        $r = $svc->request($company, ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 50_000, 'phone' => '0772123456'], $teller);
        $this->assertSame(['pending', 50_000, '+256772123456'], [$r->status, (int) $r->amount_minor, $r->phone]);
        $this->assertSame('50000', $this->flw->charges[0]['payload']['amount'], 'the amount goes out as an exact decimal string');
        $this->assertSame([['id' => 'RS_TEST']], $this->flw->charges[0]['payload']['subaccounts'], 'into the SACCO\'s own subaccount');
        $this->assertSame(0, $this->balance($cid, 'momo.clearing'), 'a request posts nothing');

        $this->assertSame('pending', $svc->settle($r->tx_ref)->status, 'no answer yet');
        $this->flw->customerAnswers($r->tx_ref, 50000, 'UGX');
        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $r->tx_ref, 'id' => 1, 'status' => 'successful']], ['verif-hash' => 'test-hash'])->assertOk();
        $svc->settle($r->tx_ref);
        $this->assertSame(0, $svc->poll($cid, 0), 'the poll finds nothing left');
        $row = DB::table('momo_requests')->find($r->id);
        $this->assertSame('successful', $row->status);
        $tx = SaccoTransaction::find($row->sacco_transaction_id);
        $this->assertSame(['deposit', 'momo', 'posted', 50_000], [$tx->kind, $tx->channel, $tx->status, $tx->amount_minor]);
        $this->assertSame(1, SaccoTransaction::where('company_id', $cid)->where('channel', 'momo')->count(), 'settled twice, one receipt');
        $this->assertSame(1, SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'txn:'.$tx->uuid)->count(), 'one posting');
        $this->assertSame(50_000, $this->balance($cid, 'momo.clearing'));
        $this->assertSame(60_000, $this->balanceOf((int) $account->id));

        $declined = $svc->request($company, ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 7_000, 'phone' => '0772123000'], $teller);
        $this->flw->customerAnswers($declined->tx_ref, 7000, 'UGX', 'failed');
        $this->assertSame('failed', $svc->settle($declined->tx_ref)->status);
        $short = $svc->request($company, ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 9_000], $teller);
        $this->flw->customerAnswers($short->tx_ref, 8999, 'UGX');
        $this->assertSame('failed', $svc->settle($short->tx_ref)->status, 'less than asked: nothing recorded');
        $this->assertSame(1, SaccoTransaction::where('company_id', $cid)->where('channel', 'momo')->count());
        $this->assertSame(50_000, $this->balance($cid, 'momo.clearing'));

        // A teller can never claim a MoMo receipt by hand.
        $this->assertSame('channel_not_available', $this->errorCode(fn () => app(SaccoTransactions::class)->record($company,
            ['kind' => 'deposit', 'channel' => 'momo', 'savings_account_id' => $account->id, 'amount_minor' => 1_000], $teller)));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_repayment_shares_hold_and_off(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $company->forceFill(['momo_subaccount_id' => 'RS_TEST'])->saveQuietly();
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 2_000_000, $owner);
        $loan = $this->activeLoan($company, $member, 300_000, 3);
        $svc = app(SaccoCollections::class);

        $rep = $svc->request($company, ['purpose' => 'sacco_repayment', 'loan_id' => $loan->id, 'amount_minor' => 50_000], $teller);
        $this->flw->customerAnswers($rep->tx_ref, 50000, 'UGX');
        $tx = SaccoTransaction::find($svc->settle($rep->tx_ref)->sacco_transaction_id);
        $this->assertSame(['loan_repayment', 'momo'], [$tx->kind, $tx->channel]);
        $this->assertSame(250_000, SaccoLoan::find($loan->id)->principal_outstanding_minor);

        $par = (int) \App\Models\SaccoSetting::for($cid)->share_par_minor;
        $this->assertSame('not_whole_shares', $this->errorCode(fn () => $svc->request($company, ['purpose' => 'sacco_shares', 'member_id' => $member->id, 'amount_minor' => $par + 1], $teller)));
        $sh = $svc->request($company, ['purpose' => 'sacco_shares', 'member_id' => $member->id, 'amount_minor' => 2 * $par], $teller);
        $this->flw->customerAnswers($sh->tx_ref, 2 * $par, 'UGX');
        $this->assertSame('share_purchase', SaccoTransaction::find($svc->settle($sh->tx_ref)->sacco_transaction_id)->kind);

        // The account closes while the prompt waits: the money is held in 2150, not refused or lost.
        $dep = $svc->request($company, ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 5_000], $teller);
        DB::table('sacco_savings_accounts')->where('id', $account->id)->update(['status' => 'closed']);
        $this->flw->customerAnswers($dep->tx_ref, 5000, 'UGX');
        $held = SaccoTransaction::find($svc->settle($dep->tx_ref)->sacco_transaction_id);
        $this->assertSame(['held', 'account_closed'], [$held->status, $held->hold_reason]);
        $this->assertSame(5_000, $this->balance($cid, 'suspense.receipts'));
        $this->assertSame(50_000 + 2 * $par + 5_000, $this->balance($cid, 'momo.clearing'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        config(['sacco.collections.driver' => 'off']);
        $this->assertSame('collections_off', $this->errorCode(fn () => $svc->request($company, ['purpose' => 'sacco_repayment', 'loan_id' => $loan->id, 'amount_minor' => 1_000], $teller)));
        $this->assertSame(0, count(array_filter($this->flw->charges, fn ($c) => $c['payload']['amount'] === '1000')), 'off: nothing sent');
    }

    public function test_http_teller_member_permissions_and_isolation(): void
    {
        [$a, $ownerA] = $this->loanSacco();
        [$b, $ownerB] = $this->loanSacco('office', 'Other SACCO');
        $a->forceFill(['momo_subaccount_id' => 'RS_A'])->saveQuietly();
        $member = $this->loanMember($a, $ownerA);
        $other = $this->loanMember($a, $ownerA);
        $account = $this->loanSavings($a, $member, 10_000, $ownerA);
        $otherAccount = $this->loanSavings($a, $other, 10_000, $ownerA);
        $teller = $this->as($this->saccoStaff($a, 'sacco_teller'));
        $auditor = $this->as($this->saccoStaff($a, 'sacco_auditor'));
        $hb = $this->as($this->saccoStaff($b, 'sacco_manager'));
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 20_000, 'phone' => '0772555111'];

        $this->postJson('/api/v1/sacco/collections', ['amount_minor' => 20000.5] + $body, $teller)->assertStatus(422);
        $this->postJson('/api/v1/sacco/collections', $body, $auditor)->assertStatus(403);
        $id = $this->postJson('/api/v1/sacco/collections', $body, $teller)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/sacco/collections', $body, $teller)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/sacco/collections', ['amount_minor' => 21_000] + $body, $teller)->assertStatus(422)->assertJsonPath('errors.code', 'idempotency_conflict');
        $this->assertCount(1, $this->flw->charges, 'a double tap sends one prompt');
        $this->getJson("/api/v1/sacco/collections/{$id}", $hb)->assertNotFound();
        $this->postJson("/api/v1/sacco/collections/{$id}/check", [], $hb)->assertNotFound();
        $this->getJson('/api/v1/sacco/collections', $hb)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/sacco/collections', ['uuid' => (string) Str::uuid()] + $body, $hb)->assertNotFound();
        $tx = DB::table('momo_requests')->find($id)->tx_ref;
        $this->flw->customerAnswers($tx, 20000, 'UGX');
        $this->postJson("/api/v1/sacco/collections/{$id}/check", [], $teller)->assertOk()->assertJsonPath('data.status', 'successful');

        $hm = $this->memberHeaders($a, $member, $ownerA);
        $mid = $this->postJson('/api/v1/sacco/me/collections', ['purpose' => 'sacco_deposit', 'savings_account_id' => $account->id, 'amount_minor' => 3_000, 'uuid' => (string) Str::uuid()], $hm)
            ->assertCreated()->assertJsonPath('data.requested_via', 'member')->json('data.id');
        $this->postJson('/api/v1/sacco/me/collections', ['purpose' => 'sacco_deposit', 'savings_account_id' => $otherAccount->id, 'amount_minor' => 3_000], $hm)->assertNotFound();
        $this->getJson("/api/v1/sacco/me/collections/{$id}", $hm)->assertOk();
        $this->flw->customerAnswers(DB::table('momo_requests')->find($mid)->tx_ref, 3000, 'UGX');
        $this->getJson("/api/v1/sacco/me/collections/{$mid}", $hm)->assertOk()->assertJsonPath('data.status', 'successful');
        $this->assertSame(33_000, $this->balanceOf((int) $account->id));
        $this->getJson('/api/v1/sacco/collections', $hm)->assertStatus(401);
        $hOther = $this->memberHeaders($a, $other, $ownerA);
        $this->getJson("/api/v1/sacco/me/collections/{$mid}", $hOther)->assertNotFound();
    }

    private function balanceOf(int $accountId): int
    {
        return (int) DB::table('sacco_savings_accounts')->where('id', $accountId)->value('balance_minor');
    }
}
