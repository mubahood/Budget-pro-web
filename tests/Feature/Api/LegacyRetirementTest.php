<?php

namespace Tests\Feature\Api;

use App\Console\Commands\LegacyStatus;
use App\Http\Middleware\LegacyApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plan B10 step 4 (P4-8): legacy calls are counted, can be retired with an update message, old new-apps are told to update. */
class LegacyRetirementTest extends ApiTestCase
{
    public function test_legacy_calls_are_counted_and_retired_routes_ask_for_an_update(): void
    {
        $t = $this->registerTenant();
        $mw = new LegacyApi();
        $request = Request::create('/api/mobile/budget-programs', 'GET', ['logged_in_user_id' => $t['user_id']]);
        $passed = false;
        $mw->handle($request, function () use (&$passed) {
            $passed = true;

            return response()->json(['code' => 1]);
        });
        $mw->handle($request, fn () => response()->json(['code' => 1]));
        $this->assertTrue($passed, 'enabled: the old app keeps working');
        $this->assertSame(2, (int) DB::table('legacy_calls')->where('company_id', $t['company_id'])->sum('calls'));

        config(['mobile.legacy_enabled' => false]);
        $this->getJson('/api/mobile/dashboard?logged_in_user_id='.$t['user_id'])->assertStatus(426)
            ->assertJsonPath('code', 0)->assertJsonPath('errors.code', 'upgrade_required');
        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'x'])->assertStatus(426);
        $this->assertSame(3, (int) DB::table('legacy_calls')->where('company_id', $t['company_id'])->sum('calls'));
    }

    public function test_retirement_status_follows_which_app_each_shop_uses(): void
    {
        $old = $this->registerTenant();
        $new = $this->registerTenant();
        DB::table('legacy_calls')->insert(['day' => now()->toDateString(), 'route' => 'GET api/{model}', 'company_id' => $old['company_id'], 'user_id' => $old['user_id'], 'calls' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/devices/register', ['device_id' => (string) Str::uuid()], $this->auth($new['token']))->assertOk();
        $before = LegacyStatus::status();
        $this->assertGreaterThan(0, $before['legacy_companies']);
        $this->assertFalse($before['ready']);

        // The old shop installs the new app: it no longer counts as "old app only".
        $this->postJson('/api/v1/devices/register', ['device_id' => (string) Str::uuid()], $this->auth($old['token']))->assertOk();
        $after = LegacyStatus::status();
        $this->assertSame($before['legacy_companies'] - 1, $after['legacy_companies']);
        $this->artisan('legacy:status')->assertSuccessful();
    }

    public function test_apps_below_the_minimum_version_are_told_to_update(): void
    {
        config(['mobile.min_version' => '2.0.0']);
        $this->getJson('/api/v1/plans', ['X-App-Version' => '1.9.3'])->assertStatus(426)->assertJsonPath('errors.code', 'upgrade_required')->assertJsonPath('errors.min_version', '2.0.0');
        $this->getJson('/api/v1/plans', ['X-App-Version' => '2.0.0'])->assertOk()->assertHeader('X-Min-App-Version', '2.0.0');
        $this->getJson('/api/v1/plans')->assertOk();
        $this->getJson('/api/v1/app/version')->assertOk()->assertJsonPath('data.min_version', '2.0.0');
    }

    public function test_the_new_app_no_longer_needs_legacy_routes_for_reports_and_people(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $members = $this->getJson('/api/v1/members', $h)->assertOk()->json('data');
        $this->assertSame('owner', $members[0]['role']);
        $this->assertArrayNotHasKey('password', $members[0]);
        $r = $this->postJson('/api/v1/financial-reports', ['type' => 'Financial', 'period_type' => 'Month', 'do_generate' => 'No'], $h)->assertStatus(201)->json('data');
        $this->assertSame((int) $t['user_id'], (int) $r['user_id']);
        $this->getJson('/api/v1/financial-reports', $h)->assertOk()->assertJsonPath('data.0.id', $r['id']);
    }
}
