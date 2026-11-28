<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoDuesPayment;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\FlutterwaveService;
use App\Services\Sacco\Payments\SaccoCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;
use Tests\Feature\Api\FakeFlutterwaveService;

/**
 * Doc 13.4: a contribution paid by mobile money. The prompt records nothing; only the provider's confirmation records the
 * payment, once however often it is settled (webhook, status check, poll); a decline records nothing; a person pays
 * their own dues only. No network: a fake provider is bound.
 */
class KinMomoDuesTest extends ApiTestCase
{
    use MakesKin;

    private FakeFlutterwaveService $flw;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
        config(['sacco.collections.driver' => 'flutterwave', 'flutterwave.secret_hash' => 'test-hash']);
        [$this->co, , $token] = $this->kinAccount();
        $this->head = ['Authorization' => 'Bearer '.$token];
    }

    private function person(string $first, ?User $user = null): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'female', 'user_id' => $user?->id, 'phone_e164' => '+256772123456'])->id;
    }

    public function test_recorded_once_on_confirmation_declines_record_nothing(): void
    {
        $memUser = $this->kinStaff($this->co, 'kin_member');
        $member = ['Authorization' => 'Bearer '.$memUser->createToken('t')->plainTextToken];
        $ann = $this->person('Ann', $memUser);
        $bob = $this->person('Bob');
        $pid = $this->postJson('/api/v1/kin/dues', ['name' => 'Roof', 'kind' => 'once', 'amount_mode' => 'any', 'starts_on' => now()->toDateString()], $this->head)
            ->assertCreated()->json('data.id');

        // Not set up: refused, nothing sent.
        $this->getJson('/api/v1/kin/dues/momo', $member)->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $ann, 'amount_minor' => 50_000], $member)->assertStatus(422)->assertJsonPath('errors.code', 'collections_off');
        $this->co->forceFill(['momo_subaccount_id' => 'RS_TEST'])->saveQuietly();
        $this->getJson('/api/v1/kin/dues/momo', $member)->assertJsonPath('data.enabled', true)->assertJsonPath('data.provider', 'flutterwave');

        // Someone else's dues: no.
        $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $bob, 'amount_minor' => 50_000], $member)->assertForbidden();

        $uuid = (string) Str::uuid();
        $out = $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $ann, 'amount_minor' => 50_000, 'uuid' => $uuid], $member)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertCount(1, $this->flw->charges);
        $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $ann, 'amount_minor' => 50_000, 'uuid' => $uuid], $member)->assertCreated()
            ->assertJsonPath('data.request_id', $out['request_id']);
        $this->assertCount(1, $this->flw->charges, 'the same uuid sends no second prompt');
        $this->assertSame(0, SaccoDuesPayment::withoutGlobalScopes()->where('programme_id', $pid)->count(), 'a prompt records nothing');
        $this->getJson("/api/v1/kin/dues/momo/{$out['request_id']}", $member)->assertOk()->assertJsonPath('data.status', 'pending');

        // Confirmed: webhook, then the status check and settle again: one payment.
        $txRef = (string) DB::table('momo_requests')->where('id', $out['request_id'])->value('tx_ref');
        $this->flw->customerAnswers($txRef, 50000, 'UGX');
        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $txRef, 'id' => 1, 'status' => 'successful']], ['verif-hash' => 'test-hash'])->assertOk();
        $this->getJson("/api/v1/kin/dues/momo/{$out['request_id']}", $member)->assertOk()->assertJsonPath('data.status', 'paid');
        app(SaccoCollections::class)->settle($txRef);
        $paid = SaccoDuesPayment::withoutGlobalScopes()->where('programme_id', $pid)->get();
        $this->assertCount(1, $paid);
        $this->assertSame([$ann, 50_000, 'mobile_money'], [(int) $paid[0]->member_id, (int) $paid[0]->amount_minor, $paid[0]->method]);
        $this->getJson("/api/v1/kin/dues/{$pid}", $this->head)->assertOk()->assertJsonPath('data.programme.collected_minor', 50_000);

        // Declined: nothing recorded.
        $r2 = $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $ann, 'amount_minor' => 20_000], $member)->assertCreated()->json('data.request_id');
        $ref2 = (string) DB::table('momo_requests')->where('id', $r2)->value('tx_ref');
        $this->flw->customerAnswers($ref2, 20000, 'UGX', 'failed');
        $this->getJson("/api/v1/kin/dues/momo/{$r2}", $member)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame(1, SaccoDuesPayment::withoutGlobalScopes()->where('programme_id', $pid)->count());

        // The head may ask for anyone; another sign-in cannot read Ann's request.
        $this->postJson("/api/v1/kin/dues/{$pid}/momo", ['member_id' => $bob, 'amount_minor' => 10_000], $this->head)->assertCreated();
        $other = ['Authorization' => 'Bearer '.$this->kinStaff($this->co, 'kin_member')->createToken('t')->plainTextToken];
        $this->getJson("/api/v1/kin/dues/momo/{$out['request_id']}", $other)->assertNotFound();
    }
}
