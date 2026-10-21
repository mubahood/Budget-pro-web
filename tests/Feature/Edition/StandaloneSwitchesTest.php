<?php

namespace Tests\Feature\Edition;

use App\Console\Commands\StandaloneHeartbeat;
use App\Services\Onboarding\PublicDemo;
use App\Services\Ops\TenantDataService;
use App\Support\MasterPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAsStandalone;
use Tests\TestCase;

/** What a standalone copy switches off whatever its .env says, and its scheduler (docs/STANDALONE_EDITION_PLAN.md §5.4, §16). */
class StandaloneSwitchesTest extends TestCase
{
    use DatabaseTransactions, RunsAsStandalone;

    private const SAAS_ONLY_TASKS = ['billing:reconcile', 'onboarding:purge-demos', 'demo:public', 'ops backup', 'ops drill', 'ops purge', 'tracking:backfill-location-names'];

    private const BOTH = ['fiscal:submit-due', 'prices:apply-due', 'customers:adopt-named', 'shop:product-stats', 'saas:hourly', 'queue:work', 'api:prune-keys'];

    public function test_master_password_public_demo_and_legacy_api_are_off_on_standalone(): void
    {
        $master = 'a-long-master-password-2026';
        config(['saas.master_password_hash' => \Illuminate\Support\Facades\Hash::make($master), 'saas.master_password_allowed_ips' => ['127.0.0.1'],
            'demo.enabled' => true, 'mobile.legacy_enabled' => true]);
        $this->assertTrue(MasterPassword::matches($master));
        $this->assertTrue(PublicDemo::enabled());
        $user = $this->shop()['user']; // its own shop, so a freshly migrated database works too

        $this->asStandalone();
        $this->assertFalse(MasterPassword::matches($master));
        $this->assertFalse(MasterPassword::configured());
        $this->assertFalse(PublicDemo::enabled());
        $this->assertSame(['action' => 'off'], app(PublicDemo::class)->run());

        $this->post('/auth/login', ['username' => $user->username, 'password' => $master]);
        $this->assertGuest('admin');
        $this->getJson('/api/mobile/dashboard?logged_in_user_id='.$user->id)->assertStatus(426)->assertJsonPath('errors.code', 'upgrade_required');
    }

    public function test_the_scheduler_runs_the_shop_jobs_on_both_and_the_saas_jobs_only_on_the_saas(): void
    {
        $saas = $this->scheduled();
        foreach (array_merge(self::SAAS_ONLY_TASKS, self::BOTH) as $task) {
            $this->assertContains($task, $saas);
        }
        $this->assertNotContains('standalone:heartbeat', $saas);

        $this->asStandalone();
        $standalone = $this->scheduled();
        foreach (self::BOTH as $task) {
            $this->assertContains($task, $standalone);
        }
        foreach (self::SAAS_ONLY_TASKS as $task) {
            $this->assertNotContains($task, $standalone);
        }
        $this->assertContains('standalone:heartbeat', $standalone);

        Cache::forget(StandaloneHeartbeat::KEY);
        Artisan::call('standalone:heartbeat');
        $this->assertNotNull($beat = Cache::get(StandaloneHeartbeat::KEY));
        $this->assertTrue(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($beat)) < 60);
        // saas:hourly on standalone: notices and debt reminders, never the billing Lifecycle (both faked: no messages leave).
        $this->mock(\App\Services\Billing\Lifecycle::class, fn ($m) => $m->shouldNotReceive('run'));
        $this->mock(\App\Services\Notifications\ScheduledNotifications::class, fn ($m) => $m->shouldReceive('run')->once()->andReturn([]));
        $this->mock(\App\Services\Engage\DebtReminders::class, fn ($m) => $m->shouldReceive('run')->once()->andReturn(0));
        $this->assertSame(0, Artisan::call('saas:hourly'));
        $this->assertStringContainsString('standalone', Artisan::output());
    }

    public function test_a_deletion_request_never_runs_by_itself_on_standalone(): void
    {
        $company = $this->shop()['company'];
        $id = DB::table('data_requests')->insertGetId(['company_id' => $company->id, 'requested_by_id' => (int) $company->owner_id, 'kind' => 'delete', 'status' => 'scheduled', 'purge_after' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        $this->asStandalone();
        $this->assertSame(0, app(TenantDataService::class)->purgeDue());
        $this->assertSame('scheduled', DB::table('data_requests')->where('id', $id)->value('status'));
        $this->assertTrue(DB::table('companies')->where('id', $company->id)->exists());
    }

    public function test_a_lapsed_subscription_never_closes_a_standalone_shop(): void
    {
        $company = $this->shop('Lapsed')['company'];
        $company->subscription->forceFill(['status' => 'expired', 'ends_at' => now()->subMonths(2), 'trial_ends_at' => now()->subMonths(2)])->save();
        $this->assertSame('expired', $company->fresh()->accessState());

        $this->asStandalone();
        $this->assertSame('active', $company->fresh()->accessState()); // a licence, not a subscription (S3)
        $this->assertSame('active', \App\Services\Billing\Entitlements::for($company->fresh())['state']);
    }

    /** @return array{user: \App\Models\User, company: \App\Models\Company} a new shop and its owner, registered as on the SaaS */
    private function shop(string $name = 'Switches'): array
    {
        return app(\App\Services\Onboarding\RegistrationService::class)->register(['first_name' => $name, 'last_name' => 'Owner',
            'email' => strtolower($name).'_'.uniqid('', true).'@example.test', 'password' => 'secret123', 'company_name' => $name.' Shop', 'currency' => 'UGX'], 'budget', 'test');
    }

    /** @return list<string> the scheduled tasks' names, built for the current edition */
    private function scheduled(): array
    {
        $this->app->forgetInstance(Schedule::class);

        return array_map(fn ($e) => (string) ($e->description ?? $e->command), app(Schedule::class)->events());
    }
}
