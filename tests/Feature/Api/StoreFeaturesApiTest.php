<?php

namespace Tests\Feature\Api;

use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phone parity for settings: store features (GET/PUT store-features, `store` in auth/me and the company),
 * the onboarding payload additions, the currency change, and token expiry / the app-version gate.
 */
class StoreFeaturesApiTest extends ApiTestCase
{
    private function member(array $t, string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $t['company_id']])->save();
        CompanyMember::create(['company_id' => $t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['user_id' => $u->id, 'h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    public function test_store_features_read_and_update_with_permissions_and_isolation(): void
    {
        $this->getJson('/api/v1/store-features')->assertStatus(401);
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $h = $this->auth($a['token']);

        $r = $this->getJson('/api/v1/store-features', $h)->assertOk()->json('data');
        $this->assertFalse($r['mode']);
        $this->assertFalse($r['features']['promotions']);
        $this->assertSame([1000, 2000, 5000, 10000, 20000, 50000], $r['settings']['note_buttons'], 'note buttons resolved from UGX');
        $this->assertArrayHasKey('pack_barcodes', $r['labels']);

        $u = $this->putJson('/api/v1/store-features', ['mode' => true, 'features' => ['offline_till' => true, 'promotions' => false], 'settings' => ['cash_rounding' => '50', 'age_min' => 21]], $h)
            ->assertOk()->json('data');
        $this->assertTrue($u['mode']);
        $this->assertTrue($u['features']['approvals'], 'mode turns the rest on');
        $this->assertFalse($u['features']['promotions']);
        $this->assertTrue($u['features']['offline_till']);
        $this->assertSame(50, $u['settings']['cash_rounding']);
        $this->assertSame(21, $u['settings']['age_min']);

        $this->putJson('/api/v1/store-features', ['settings' => ['scale_format' => 'volume']], $h)->assertStatus(422);
        $this->putJson('/api/v1/store-features', ['features' => ['teleport' => true]], $h)->assertStatus(422)->assertJsonPath('errors.code', 'unknown_feature');

        $cashier = $this->member($a, 'cashier');
        $this->getJson('/api/v1/store-features', $cashier['h'])->assertOk()->assertJsonPath('data.mode', true);
        $this->putJson('/api/v1/store-features', ['mode' => false], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'manage_settings');

        // The other shop is untouched; the block rides in auth/me and the company.
        $this->getJson('/api/v1/store-features', $this->auth($b['token']))->assertOk()->assertJsonPath('data.mode', false);
        $me = $this->getJson('/api/v1/auth/me', $h)->assertOk();
        $me->assertJsonPath('data.store.mode', true)->assertJsonPath('data.company.store.settings.age_min', 21);
        $this->assertNotNull($me->json('data.expires_at'));
        $this->assertFalse($me->json('data.company.currency_locked'));
    }

    public function test_login_refresh_logout_and_requests_without_app_version(): void
    {
        $t = $this->registerTenant();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $t['email'], 'password' => $t['password']])->assertOk();
        $this->assertNotNull($login->json('data.expires_at'));
        $token = $login->json('data.token');

        Log::spy();
        $this->getJson('/api/v1/auth/me', $this->auth($token))->assertOk(); // no X-App-Version: still served
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => $msg === 'API call without X-App-Version')->once();
        config(['mobile.min_version' => '2.0.0']);
        $this->getJson('/api/v1/auth/me', $this->auth($token) + ['X-App-Version' => '1.0.0'])->assertStatus(426)->assertJsonPath('errors.code', 'upgrade_required');

        $refresh = $this->postJson('/api/v1/auth/refresh', [], $this->auth($token))->assertOk();
        $this->assertNotNull($refresh->json('data.expires_at'));
        $this->assertNotSame($token, $refresh->json('data.token'));
        $this->getJson('/api/v1/auth/me', $this->auth($token))->assertStatus(401);
        $new = $refresh->json('data.token');
        $this->postJson('/api/v1/auth/logout', [], $this->auth($new))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->auth($new))->assertStatus(401);
    }

    public function test_onboarding_payload_carries_type_details_sales_and_quota(): void
    {
        $t = $this->registerTenant(['business_type' => 'supermarket']);
        $h = $this->auth($t['token']);
        $d = $this->getJson('/api/v1/onboarding', $h)->assertOk()->json('data');
        $this->assertIsString($d['presets']['business_types']['supermarket'], 'older apps read the plain label');
        $this->assertSame('Supermarket / mini-mart', $d['presets']['business_type_details']['supermarket']['label']);
        $this->assertSame('fa-cart-shopping', $d['presets']['business_type_details']['supermarket']['icon']);
        $this->assertSame(['shop', 'finance'], $d['presets']['business_type_details']['supermarket']['modules']);
        $this->assertFalse($d['has_sales']);
        $this->assertNull($d['first_sale_at']);
        $this->assertSame(0, $d['quota']['used']);
        $this->assertArrayHasKey('max', $d['quota']);

        $pack = $this->getJson('/api/v1/onboarding/templates?business_type=pharmacy', $h)->assertOk()->json('data');
        $this->assertSame('pharmacy', $pack['business_type']);
        if ($pack['items'] !== []) {
            $this->assertArrayHasKey('sub_category', $pack['items'][0]);
            $this->assertArrayHasKey('category', $pack['items'][0]);
        }
        $this->getJson('/api/v1/onboarding/templates?business_type=spaceship', $h)->assertStatus(422);
        $preview = $this->postJson('/api/v1/onboarding/import', ['csv' => "name,selling_price\nSoap,2000\n,5", 'dry_run' => true], $h)->assertOk()->json('data');
        $this->assertSame(1, $preview['count']);
        $this->assertNotEmpty($preview['errors']);
    }

    public function test_currency_change_is_owner_only_with_password(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->postJson('/api/v1/company/currency/preview', ['to' => 'KES', 'mode' => 'relabel'], $h)->assertOk()->assertJsonPath('data.to', 'KES');
        $this->postJson('/api/v1/company/currency', ['to' => 'KES', 'mode' => 'relabel', 'password' => 'wrong'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'wrong_password');
        $this->postJson('/api/v1/company/currency', ['to' => 'KES', 'mode' => 'convert', 'password' => $t['password']], $h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_rate');
        $this->postJson('/api/v1/company/currency', ['to' => 'KES', 'mode' => 'relabel', 'password' => $t['password']], $h)->assertOk()->assertJsonPath('data.company.currency', 'KES');
        $this->assertSame('KES', DB::table('companies')->where('id', $t['company_id'])->value('currency'));

        $manager = $this->member($t, 'manager');
        $this->postJson('/api/v1/company/currency', ['to' => 'UGX', 'mode' => 'relabel', 'password' => 'secret123'], $manager['h'])->assertStatus(403);
    }
}
