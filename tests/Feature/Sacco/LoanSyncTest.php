<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.16 sync rules (04 §9, AK-23): a phone may start a loan draft (its terms copied from the product on
 * the server), pushed again it is the same draft; it may not submit, approve or pay out (offline_not_allowed); the
 * loan products come down as a pull-only snapshot.
 */
class LoanSyncTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_a_phone_drafts_and_never_submits(): void
    {
        [$company, $owner] = $this->loanSacco();
        $member = $this->loanMember($company, $owner);
        $officer = $this->loanStaffOf($company)['officer'];
        $device = (string) Str::uuid();
        $h = ['Authorization' => 'Bearer '.$officer->createToken('p')->plainTextToken, 'Accept' => 'application/json'];
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Field phone', 'platform' => 'android'], $h)->assertOk();
        $h += ['X-Device-Id' => $device];
        $product = SaccoLoanProduct::where('company_id', $company->id)->where('code', 'ORD')->firstOrFail();
        $uuid = (string) Str::uuid();
        $op = fn (array $data, string $action = 'upsert') => ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [['op_uuid' => (string) Str::uuid(), 'table' => 'sacco_loans',
            'action' => $action, 'uuid' => $uuid, 'data' => $data]]]]];
        $draft = ['member_uuid' => $member->uuid, 'product_uuid' => $product->uuid, 'applied_minor' => 300_000, 'term' => 6, 'purpose' => 'Seeds', 'status' => 'draft'];
        $r = $this->postJson('/api/v1/sync/push', $op($draft), $h)->assertOk()->json('data.results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));
        $loan = SaccoLoan::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(['draft', 'phone', 300_000, (int) $member->id], [$loan->status, $loan->source, $loan->applied_minor, (int) $loan->member_id]);
        $this->assertSame($product->rate_bp, $loan->loanTerms()->rateBp, 'the server copied the product\'s terms');
        $this->postJson('/api/v1/sync/push', $op($draft), $h)->assertOk();
        $this->assertSame(1, SaccoLoan::where('uuid', $uuid)->count(), 'the same draft');
        $submitted = $this->postJson('/api/v1/sync/push', $op(['status' => 'submitted'] + $draft), $h)->assertOk()->json('data.results.0.ops.0.code');
        $this->assertSame('offline_not_allowed', $submitted);
        $this->assertSame('validation', $this->postJson('/api/v1/sync/push', $op(['applied_minor' => 10.5] + $draft), $h)->json('data.results.0.ops.0.code'));
        $snap = $this->getJson('/api/v1/sync/pull?table=sacco_loan_products&since_seq=0', $h)->assertOk()->json('data');
        $this->assertTrue($snap['snapshot']);
        $this->assertContains('ORD', array_column($snap['rows'], 'code'));
    }
}
