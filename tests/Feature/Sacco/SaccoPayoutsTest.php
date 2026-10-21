<?php

namespace Tests\Feature\Sacco;

use App\Models\Plan;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoPayout;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Payouts\FlutterwavePayouts;
use App\Services\Sacco\Payouts\PayoutRequest;
use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P3.2 (04 §11.2): the Flutterwave payout driver against Http::fake only (stray requests refused): a
 * success settles 2160 into 1150 once, a refusal reverses the sent journal, no answer is `unknown` and polled, a retry
 * reuses the key and never sends a second transfer, a duplicate reference is read as "already sent", a person settles
 * a payout the provider never answered (not the maker), the plan gate, and the payouts API with its permissions and
 * another SACCO's ids answering 404.
 */
class SaccoPayoutsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['sacco.payouts.driver' => 'flutterwave', 'flutterwave.secret_key' => 'FLWSECK-test-only', 'flutterwave.base_url' => 'https://flw.test']);
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

    /** A signed loan paid out by MoMo to $phone, approved by the manager: the payout. */
    private function momoLoan(\App\Models\Company $company, User $owner, int $amount, string $phone): SaccoPayout
    {
        $product = $this->ordinaryProduct($company);
        SaccoLoanProduct::whereKey($product->id)->update(['allowed_channels' => json_encode(['savings', 'cash', 'momo'])]);
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 3_000_000, $owner);
        $loan = $this->signedLoan($company, $member, $amount, 6, null, $product->fresh());
        $a = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => $phone]);
        (new SaccoApprovals())->approve($company, (int) $a->id, $this->loanStaffOf($company)['manager']);

        return SaccoPayout::where('subject_id', $loan->id)->latest('id')->firstOrFail();
    }

    public function test_success_failure_unknown_and_retry_with_one_key(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $this->loanStaffOf($company);
        $posts = [];
        $state = ['status' => 'NEW'];
        Http::fake(function (Request $r) use (&$posts, &$state) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/v3/transfers')) {
                $posts[] = $r->data();
                if (str_ends_with((string) $r['account_number'], '000')) {
                    return Http::response(['status' => 'error', 'message' => 'Insufficient balance in wallet'], 400);
                }
                if (str_ends_with((string) $r['account_number'], '999')) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['status' => 'success', 'data' => ['id' => 4401 + count($posts), 'reference' => $r['reference'], 'status' => 'NEW']]);
            }
            if ($r->method() === 'GET' && str_contains($r->url(), '/v3/transfers?')) {
                return Http::response(['status' => 'success', 'data' => [['id' => 9901, 'reference' => 'x', 'status' => $state['status']]]]);
            }

            return Http::response(['status' => 'success', 'data' => ['id' => 1, 'status' => $state['status']]]);
        });

        // Success: sent now, settled when the provider says so; posting the settlement twice gives one entry.
        $p = $this->momoLoan($company, $owner, 500_000, '+256772123456');
        $this->assertSame(['sent', 'flutterwave'], [$p->status, $p->provider]);
        $this->assertSame($p->idempotency_key, $posts[0]['reference'], 'the payout key is the provider reference');
        $this->assertSame('495000', $posts[0]['amount'], 'an exact decimal string, net of the fee');
        $this->assertSame(495_000, $this->balance($cid, 'payouts.in_transit'));
        $state['status'] = 'SUCCESSFUL';
        $this->assertSame(1, (new PayoutService())->poll($cid));
        $this->assertSame(0, (new PayoutService())->poll($cid), 'nothing left in flight');
        $this->assertSame('successful', $p->fresh()->status);
        $this->assertSame(['active', 0, -495_000], [\App\Models\SaccoLoan::find($p->subject_id)->status, $this->balance($cid, 'payouts.in_transit'), $this->balance($cid, 'momo.float')]);
        $this->assertSame(1, SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'payout:'.$p->id.':settled')->count());

        // Refused: the sent journal is reversed and the loan waits, signed.
        $f = $this->momoLoan($company, $owner, 300_000, '+256772123000');
        $this->assertSame('failed', $f->fresh()->status);
        $this->assertSame('signed', \App\Models\SaccoLoan::find($f->subject_id)->status);
        $this->assertNotNull($f->fresh()->failed_journal_id);
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'));

        // No answer: unknown, polled by its reference; a retry asks again with the same key and never posts a second transfer.
        $state['status'] = 'PENDING';
        $u = $this->momoLoan($company, $owner, 200_000, '+256772123999');
        $this->assertSame(['unknown', 'ref:'.$u->idempotency_key], [$u->status, $u->provider_ref]);
        $count = count($posts);
        (new PayoutService())->retry($company, (int) $u->id, $this->loanTreasurer($company));
        (new PayoutService())->retry($company, (int) $u->id, $this->loanTreasurer($company));
        $this->assertCount($count, $posts, 'retries ask, they never send again');
        $this->assertSame(['sent', '9901'], [$u->fresh()->status, $u->fresh()->provider_ref]);
        $state['status'] = 'SUCCESSFUL';
        (new PayoutService())->retry($company, (int) $u->id, $this->loanTreasurer($company));
        $this->assertSame('successful', $u->fresh()->status);
        $this->assertSame('payout_closed', $this->errorCode(fn () => (new PayoutService())->retry($company, (int) $u->id, $this->loanTreasurer($company))));
        $this->assertSame('forbidden', $this->errorCode(fn () => (new PayoutService())->retry($company, (int) $u->id, $this->saccoStaff($company, 'sacco_teller'))));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_a_duplicate_reference_is_already_sent(): void
    {
        Http::fake(['https://flw.test/v3/transfers' => Http::response(['status' => 'error', 'message' => 'Transfer with this reference already exists'], 400),
            'https://flw.test/v3/transfers?*' => Http::response(['status' => 'success', 'data' => [['id' => 77, 'status' => 'SUCCESSFUL']]])]);
        $r = (new FlutterwavePayouts())->send(new PayoutRequest('payout:key:1', '+256772000111', 'mtn', 10_000, 'UGX', 'Test SACCO loan'));
        $this->assertSame(['successful', '77'], [$r->status, $r->providerRef]);
        Http::assertSentCount(2);
    }

    public function test_settled_by_hand_by_a_second_officer_and_the_plan_gate(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        Http::fake(fn () => throw new ConnectionException('no answer'));
        $p = $this->momoLoan($company, $owner, 400_000, '+256772555123');
        $this->assertSame('unknown', $p->status);
        $manager = $this->loanStaffOf($company)['manager'];
        $this->assertSame('payout_in_flight', $this->errorCode(fn () => (new PayoutService())->resolveByHand($company, (int) $p->id, $manager, 'successful', 'MTN statement shows it')));
        DB::table('sacco_payouts')->where('id', $p->id)->update(['sent_at' => now()->subHours(25)]);
        $this->assertSame(0, (new PayoutService())->poll($cid), 'after a day it waits for a person');
        $this->assertTrue(PayoutService::present($p->fresh())['needs_person']);
        $this->assertSame('self_approval', $this->errorCode(fn () => (new PayoutService())->resolveByHand($company, (int) $p->id, $this->loanTreasurer($company), 'successful', 'MTN statement shows it')),
            'the officer who asked for the payout never settles it');
        $settled = (new PayoutService())->resolveByHand($company, (int) $p->id, $manager, 'successful', 'MTN statement shows it');
        $this->assertSame('successful', $settled->status);
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'));

        Subscription::where('company_id', $cid)->update(['plan_id' => Plan::where('slug', 'akiba-standard')->value('id')]);
        $product = $this->ordinaryProduct($company);
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 3_000_000, $owner);
        $loan = $this->signedLoan($company, $member, 100_000, 6, null, $product->fresh());
        $a = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => '+256772555124']);
        $this->assertSame('feature_not_in_plan', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $a->id, $manager)));
    }

    public function test_the_api_permissions_and_isolation(): void
    {
        [$a, $ownerA] = $this->loanSacco();
        [$b] = $this->loanSacco('office', 'Other SACCO');
        Http::fake(fn () => throw new ConnectionException('no answer'));
        $p = $this->momoLoan($a, $ownerA, 300_000, '+256772555777');
        $hb = $this->as($this->saccoStaff($b, 'sacco_manager'));
        $teller = $this->as($this->saccoStaff($a, 'sacco_teller'));
        $treasurer = $this->as($this->loanTreasurer($a));
        $this->getJson('/api/v1/sacco/payouts', $teller)->assertOk()->assertJsonPath('data.0.id', $p->id)->assertJsonPath('data.0.status', 'unknown');
        $this->getJson("/api/v1/sacco/payouts/{$p->id}", $hb)->assertNotFound();
        $this->getJson('/api/v1/sacco/payouts', $hb)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/sacco/payouts/{$p->id}/retry", [], $teller)->assertStatus(403);
        $this->postJson("/api/v1/sacco/payouts/{$p->id}/retry", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/sacco/payouts/{$p->id}/retry", [], $treasurer)->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->postJson("/api/v1/sacco/payouts/{$p->id}/resolve", ['status' => 'failed', 'note' => 'No such transfer'], $teller)->assertStatus(403);
        $this->postJson("/api/v1/sacco/payouts/{$p->id}/resolve", ['status' => 'failed', 'note' => 'No such transfer'], $this->as($this->loanStaffOf($a)['manager']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'payout_in_flight');
    }
}
