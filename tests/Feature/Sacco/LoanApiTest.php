<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoMemberLogin;
use App\Models\User;
use App\Services\Sacco\Members\MemberLogin;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.16 (04 §13.2 P2 rows): the loan pipeline over HTTP, money only as whole minor units, each write's
 * permission (403), refusals with their codes (422), another SACCO's ids answering 404 on every loan route, a member
 * seeing only their own loans, and the guarantor's public consent by link and code.
 */
class LoanApiTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return array<string, string> */
    private function as(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_the_pipeline_permissions_and_refusals(): void
    {
        [$company, $owner] = $this->loanSacco();
        $staff = $this->loanStaffOf($company);
        $officer = $this->as($staff['officer']);
        $manager = $this->as($staff['manager']);
        $treasurer = $this->as($this->loanTreasurer($company));
        $teller = $this->as($this->saccoStaff($company, 'sacco_teller'));
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 2_000_000, $owner);
        $product = SaccoLoanProduct::where('company_id', $company->id)->where('code', 'ORD')->firstOrFail();

        $this->postJson("/api/v1/sacco/loan-products/{$product->id}/preview", ['amount_minor' => 1_000_000, 'term' => 12], $teller)->assertOk()
            ->assertJsonPath('data.instalment_minor', 94_560)->assertJsonPath('data.early_settlement_fee_minor', 0);
        $this->postJson('/api/v1/sacco/loan-products', ['preset' => 'pdm'], $officer)->assertStatus(403);
        $this->postJson('/api/v1/sacco/loans', ['member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 1500.5, 'term' => 12], $officer)->assertStatus(422);
        $this->postJson('/api/v1/sacco/loans', ['member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 1_000_000, 'term' => 12], $teller)->assertStatus(403);
        $id = $this->postJson('/api/v1/sacco/loans', ['member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 1_000_000, 'term' => 12,
            'disbursement_channel' => 'savings', 'submit' => true], $officer)->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
        $this->postJson("/api/v1/sacco/loans/{$id}/appraise", ['monthly_income_minor' => 3_000_000, 'monthly_expenses_minor' => 1_000_000], $officer)->assertOk()->assertJsonPath('data.status', 'appraised');
        $this->postJson("/api/v1/sacco/loans/{$id}/request-decision", [], $officer)->assertStatus(202);
        $this->postJson("/api/v1/sacco/loans/{$id}/decide", ['decision' => 'approve'], $officer)->assertStatus(403);
        $this->postJson("/api/v1/sacco/loans/{$id}/decide", ['decision' => 'approve'], $manager)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/sacco/loans/{$id}/sign", ['witness_name' => 'Okello'], $officer)->assertStatus(422)->assertJsonPath('errors.code', 'contract_required');
        $docs = $this->postJson("/api/v1/sacco/loans/{$id}/contract", [], $officer)->assertCreated()->json('data');
        $this->get("/api/v1/sacco/loans/{$id}/documents/{$docs['contract_id']}", $officer)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/sacco/loans/{$id}/sign", ['witness_name' => 'Okello Peter'], $officer)->assertOk()->assertJsonPath('data.status', 'signed');
        $approval = $this->postJson("/api/v1/sacco/loans/{$id}/disburse", ['channel' => 'savings'], $treasurer)->assertStatus(202)->json('data.approval.id');
        $this->postJson("/api/v1/sacco/approvals/{$approval}/approve", [], $manager)->assertOk();
        $this->getJson("/api/v1/sacco/loans/{$id}", $teller)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.principal_outstanding_minor', 1_000_000);
        $this->getJson("/api/v1/sacco/loans/{$id}/schedule", $teller)->assertOk()->assertJsonCount(12, 'data.lines');
        $this->postJson("/api/v1/sacco/loans/{$id}/repayments", ['amount_minor' => 100_000, 'channel' => 'cash', 'uuid' => (string) Str::uuid()], $officer)->assertCreated();
        $this->postJson("/api/v1/sacco/loans/{$id}/repayments", ['amount_minor' => '100.5', 'channel' => 'cash'], $teller)->assertStatus(422);
        $this->getJson("/api/v1/sacco/loans/{$id}/statement", $teller)->assertOk()->assertJsonPath('data.owed_minor', 900_000);
        $this->getJson("/api/v1/sacco/loans/{$id}/settlement-quote", $teller)->assertOk()->assertJsonPath('data.principal_minor', 900_000)->assertJsonPath('data.early_settlement_fee_minor', 0);
        $this->getJson("/api/v1/sacco/loans/{$id}/repayments", $teller)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sacco/loans?status=active', $teller)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/sacco/loans/{$id}/write-off", ['reason' => 'x'], $teller)->assertStatus(403);
        $this->postJson("/api/v1/sacco/loans/{$id}/votes", ['vote' => 'approve'], $teller)->assertStatus(403);
    }

    public function test_tenant_isolation_member_tokens_and_the_public_consent_page(): void
    {
        [$a, $ownerA] = $this->loanSacco();
        [$b, $ownerB] = $this->loanSacco();
        $memberA = $this->loanMember($a, $ownerA, 400, ['email' => 'borrower_'.uniqid().'@example.com']);
        $this->loanSavings($a, $memberA, 1_000_000, $ownerA);
        $loan = $this->activeLoan($a, $memberA, 500_000, 6, ['channel' => 'savings']);
        $guarantorA = $this->loanMember($a, $ownerA);
        $this->loanSavings($a, $guarantorA, 1_000_000, $ownerA);
        $hb = $this->as($ownerB);
        $id = $loan->id;
        $doc = $loan->documents()->value('id');
        foreach (["loans/{$id}", "loans/{$id}/schedule", "loans/{$id}/statement", "loans/{$id}/settlement-quote", "loans/{$id}/guarantors", "loans/{$id}/collateral",
            "loans/{$id}/repayments", "loans/{$id}/documents/{$doc}"] as $path) {
            $this->getJson("/api/v1/sacco/{$path}", $hb)->assertNotFound();
        }
        foreach (["loans/{$id}/repayments" => ['amount_minor' => 1000, 'channel' => 'cash'], "loans/{$id}/write-off" => ['reason' => 'x'], "loans/{$id}/guarantors" => ['member_id' => $guarantorA->id, 'guaranteed_minor' => 1000],
            "loans/{$id}/collateral" => ['type' => 'chattel', 'description' => 'x', 'value_minor' => 1], "loans/{$id}/reschedule" => ['term' => 6]] as $path => $body) {
            $this->postJson("/api/v1/sacco/{$path}", $body, $hb)->assertNotFound();
        }
        $this->putJson("/api/v1/sacco/loans/{$id}", ['term' => 6], $hb)->assertNotFound();
        $this->getJson('/api/v1/sacco/loans', $hb)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(500_000, SaccoLoan::find($id)->principal_outstanding_minor);

        app(MemberLogin::class)->enable($a, (int) $memberA->id, $ownerA, $memberA->email, null, 'member-pass-1');
        $login = SaccoMemberLogin::where('member_id', $memberA->id)->firstOrFail();
        $plain = Str::random(48);
        $pat = \Laravel\Sanctum\PersonalAccessToken::forceCreate(['tokenable_type' => SaccoMemberLogin::class, 'tokenable_id' => $login->id, 'name' => 'm',
            'token' => hash('sha256', $plain), 'abilities' => ['sacco:member'], 'expires_at' => now()->addDay()]);
        $hm = ['Authorization' => 'Bearer '.$pat->id.'|'.$plain, 'Accept' => 'application/json'];
        $this->getJson('/api/v1/sacco/me/loans', $hm)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->assertContains('ORD', array_column($this->getJson('/api/v1/sacco/me/loan-products', $hm)->assertOk()->json('data'), 'code'));
        $this->getJson("/api/v1/sacco/me/loans/{$id}", $hm)->assertOk()->assertJsonCount(6, 'data.schedule');
        $otherLoan = $this->activeLoan($a, $guarantorA, 100_000, 3, ['channel' => 'savings']);
        $this->getJson("/api/v1/sacco/me/loans/{$otherLoan->id}", $hm)->assertNotFound();
        $this->getJson("/api/v1/sacco/loans/{$id}", $hm)->assertStatus(401);

        // A guarantor consents on the public page: notice, fresh code, consent.
        $memberC = $this->loanMember($a, $ownerA);
        $this->loanSavings($a, $memberC, 50_000, $ownerA);
        $draft = $this->application($a, $memberC, 100_000, 3, $ownerA);
        $g = $this->postJson("/api/v1/sacco/loans/{$draft->id}/guarantors", ['member_id' => $guarantorA->id, 'guaranteed_minor' => 50_000], $this->as($ownerA))->assertCreated()->json('data');
        $token = Str::afterLast($g['consent_link'], '/');
        $this->getJson("/api/v1/sacco/consent/{$token}")->assertOk()->assertJsonPath('data.guaranteed_minor', 50_000)->assertJsonMissingPath('data.borrower_last_name');
        $this->postJson("/api/v1/sacco/consent/{$token}/code")->assertOk();
        $code = cache()->get('otp_test_sacco_guarantee:'.$g['id']);
        $this->postJson("/api/v1/sacco/consent/{$token}/consent", ['code' => '000000x'])->assertStatus(422);
        $this->postJson("/api/v1/sacco/consent/{$token}/consent", ['code' => $code])->assertOk()->assertJsonPath('data.status', 'consented');
        $this->getJson("/api/v1/sacco/consent/{$token}")->assertNotFound();
        $otp = \Illuminate\Support\Facades\DB::table('message_log')->where('to', $guarantorA->phone_e164)->where('purpose', 'otp')->latest('id')->value('body');
        $this->assertStringNotContainsString('Budget Pro', (string) $otp, 'the code comes in the SACCO\'s name');
    }
}
