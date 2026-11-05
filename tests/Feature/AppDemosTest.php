<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Onboarding\AppDemoBuilder;
use App\Services\Onboarding\DemoShopService;
use App\Services\Onboarding\PublicDemo;
use App\Services\Ops\TenantDataService;
use App\Services\Shop\CurrencyChangeService;
use App\Services\Team\TeamService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Each family app's own demo account (PublicDemo::spec, config demo.apps): its own company with only its own modules, its
 * own sign-in, healed up to today at any hour without a row twice, every visitor lock on it, the phones' `app`, and the
 * jobs at minutes the host's scheduler can reach.
 */
class AppDemosTest extends TestCase
{
    use DatabaseTransactions;

    private const MODULES = ['shelfie' => ['shop', 'finance'], 'kuku-tally' => ['poultry', 'finance'], 'pledge-and-budget' => ['budget', 'finance']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.days' => 2, 'demo.enabled' => true]);
        foreach (PublicDemo::accounts() as $account) {
            DB::table('admin_users')->where('username', PublicDemo::spec($account)['username'])->update(['username' => 'demo-local-'.uniqid()]);
        }
    }

    /** @return array<string, int> what the account's main screen counts */
    private function rows(int $cid): array
    {
        return collect(['sale_records', 'stock_items', 'poultry_daily_records', 'poultry_sales', 'poultry_batches', 'budget_programs', 'contribution_records', 'contribution_payments'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $cid)->count()])->all();
    }

    /** @return array<string, int> today's figures, in the account's own time zone */
    private function today(int $cid): array
    {
        $date = now('Africa/Kampala')->toDateString();

        return [
            'sales' => DB::table('sale_records')->where('company_id', $cid)->where('sale_date', $date)->count(),
            'eggs' => (int) DB::table('poultry_daily_records')->where('company_id', $cid)->where('date', $date)->selectRaw('COALESCE(SUM(eggs_trays * 30 + eggs_loose), 0) AS n')->value('n'),
            'egg_sales' => DB::table('poultry_sales')->where('company_id', $cid)->where('date', $date)->count(),
            'payments' => DB::table('contribution_payments')->where('company_id', $cid)->where('paid_on', $date)->count(),
            'pledges' => DB::table('contribution_records')->where('company_id', $cid)->where('created_at', '>=', now('Africa/Kampala')->startOfDay()->utc())->count(),
        ];
    }

    private function assertAlive(string $account, int $cid, string $when): void
    {
        $t = $this->today($cid);
        match ($account) {
            'shelfie' => $this->assertGreaterThan(0, $t['sales'], "{$when}: sales today"),
            'kuku-tally' => [$this->assertGreaterThan(0, $t['eggs'], "{$when}: eggs today"), $this->assertGreaterThan(0, $t['egg_sales'], "{$when}: eggs sold today")],
            'pledge-and-budget' => [$this->assertGreaterThan(0, $t['payments'], "{$when}: payments today"), $this->assertGreaterThan(0, $t['pledges'], "{$when}: a pledge today")],
        };
    }

    public function test_each_app_has_its_own_company_with_only_its_own_modules_and_data(): void
    {
        $ids = [];
        foreach (self::MODULES as $account => $modules) {
            $this->assertSame('built', app(PublicDemo::class)->run(false, $account)['action'], $account);
            $company = PublicDemo::company($account);
            $this->assertNotNull($company, $account);
            $ids[$account] = (int) $company->id;
            $this->assertSame($modules, $company->enabled_modules, $account);
            $this->assertSame($account, PublicDemo::accountOf($company));
            $this->assertSame($account, PublicDemo::accountOf((int) $company->id));
            $this->assertTrue((bool) $company->is_demo);
            $this->assertNull($company->demo_parent_id);
            $this->assertSame('UGX', $company->currency);
            $this->assertTrue(Hash::check(PublicDemo::spec($account)['password'], PublicDemo::owner($account)->password));
            $this->assertSame('owner', DB::table('company_members')->where('company_id', $company->id)->where('user_id', PublicDemo::owner($account)->id)->value('role'));
            $this->assertGreaterThan(1, DB::table('admin_users')->where('company_id', $company->id)->count(), "{$account} has a team");
            $this->assertAlive($account, (int) $company->id, 'on the build');
        }
        $this->assertCount(3, array_unique($ids));
        $this->assertSame('Kireka Family Mart', PublicDemo::company('shelfie')->name);
        $this->assertSame('Nsubuga Poultry Farm', PublicDemo::company('kuku-tally')->name);
        $this->assertNull(PublicDemo::company(), "Budget Pro's account is its own: none of these");

        $shelfie = $this->rows($ids['shelfie']);
        $this->assertGreaterThan(20, $shelfie['stock_items']);
        $this->assertSame(0, $shelfie['poultry_batches'] + $shelfie['budget_programs'] + $shelfie['contribution_records'], 'a shop only');
        $this->assertSame(0, DB::table('stock_items')->where('company_id', $ids['shelfie'])->whereIn('name', ['Butter Croissant', 'Wireless Headphones', 'Whole Milk 2L'])->count(), 'not Fresh Corner Market\'s catalogue');

        $farm = $this->rows($ids['kuku-tally']);
        $this->assertGreaterThan(100, $farm['poultry_daily_records']);
        $this->assertSame(0, $farm['stock_items'] + $farm['sale_records'] + $farm['budget_programs'], 'a farm only');
        $this->assertGreaterThan(0, DB::table('financial_records')->where('company_id', $ids['kuku-tally'])->count(), "its costs are in its Finance");
        $layers = DB::table('poultry_batches')->where('company_id', $ids['kuku-tally'])->where('name', 'Layers House 1')->first();
        $dead = DB::table('poultry_daily_records')->where('batch_id', $layers->id)->sum('mortality') + DB::table('poultry_mortality_events')->where('batch_id', $layers->id)->sum('count');
        $this->assertSame(305, $layers->start_count - (int) $dead);

        $events = $this->rows($ids['pledge-and-budget']);
        $this->assertSame(3, $events['budget_programs']);
        $this->assertGreaterThan(50, $events['contribution_records']);
        $this->assertSame(0, $events['stock_items'] + $events['sale_records'] + $events['poultry_batches'], 'events only');
        $chair = PublicDemo::owner('pledge-and-budget')->id;
        foreach (DB::table('budget_programs')->where('company_id', $ids['pledge-and-budget'])->pluck('id') as $id) {
            $this->assertSame('chair', DB::table('budget_program_members')->where('budget_program_id', $id)->where('user_id', $chair)->value('role'));
        }
    }

    /** Before the tills open, in the afternoon, and on the next day: never empty, and a second heal adds nothing. */
    public function test_the_heal_keeps_today_alive_at_any_hour_and_adds_nothing_twice(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 03:10', 'Africa/Kampala')->utc());
        foreach (array_keys(self::MODULES) as $account) {
            app(PublicDemo::class)->run(false, $account);
            $cid = (int) PublicDemo::company($account)->id;
            $this->assertAlive($account, $cid, '03:10, built');

            $before = $this->rows($cid);
            $this->assertSame('healed', app(PublicDemo::class)->run(false, $account)['action']);
            $this->assertSame($before, $this->rows($cid), "{$account}: a heal in the same minute adds nothing");

            $this->travelTo(Carbon::parse('2026-11-06 01:20', 'Africa/Kampala')->utc());
            $this->assertSame('healed', app(PublicDemo::class)->run(false, $account)['action']);
            $this->assertAlive($account, $cid, 'the next day, before the doors open');
            $before = $this->rows($cid);
            app(PublicDemo::class)->run(false, $account);
            $this->assertSame($before, $this->rows($cid), "{$account}: and again");

            $this->travelTo(Carbon::parse('2026-11-06 15:00', 'Africa/Kampala')->utc());
            $this->assertSame('healed', app(PublicDemo::class)->run(false, $account)['action']);
            $this->assertAlive($account, $cid, 'that afternoon');
            if ($account === 'pledge-and-budget') {
                $this->assertGreaterThanOrEqual(3, $this->today($cid)['payments'], 'a payment for each round of the day');
                $this->assertSame(1, DB::table('contribution_records')->where('company_id', $cid)->where('created_at', '>=', now('Africa/Kampala')->startOfDay()->utc())->count(), 'one new pledge a day');
            }
            $this->travelTo(Carbon::parse('2026-11-05 03:10', 'Africa/Kampala')->utc());
        }
    }

    public function test_the_heal_puts_back_what_a_visitor_changed(): void
    {
        foreach (array_keys(self::MODULES) as $account) {
            app(PublicDemo::class)->run(false, $account);
            $company = PublicDemo::company($account);
            $owner = PublicDemo::owner($account);
            $staff = PublicDemo::roles($account);
            $other = array_values(array_filter($staff, fn ($r) => (int) $r['user_id'] !== (int) $owner->id))[0];
            $role = array_search($other, $staff, true);
            $owner->forceFill(['password' => Hash::make('someone-elses')])->save();
            DB::table('companies')->where('id', $company->id)->update(['name' => 'Renamed by a visitor', 'enabled_modules' => json_encode(['shop', 'poultry', 'budget', 'finance'])]);
            $accountRole = (string) DB::table('company_members')->where('company_id', $company->id)->where('user_id', $other['user_id'])->value('role');
            DB::table('company_members')->where('company_id', $company->id)->where('user_id', $other['user_id'])->update(['role' => 'viewer' === $accountRole ? 'cashier' : 'viewer']);

            $result = app(PublicDemo::class)->run(false, $account);

            $this->assertSame('healed', $result['action']);
            $fresh = Company::withoutGlobalScopes()->find($company->id);
            $this->assertSame($company->name, $fresh->name);
            $this->assertSame(self::MODULES[$account], $fresh->enabled_modules);
            $this->assertTrue(Hash::check(PublicDemo::spec($account)['password'], $owner->fresh()->password));
            $this->assertSame($accountRole, DB::table('company_members')->where('company_id', $company->id)->where('user_id', $other['user_id'])->value('role'));
        }
    }

    public function test_every_lock_covers_them_and_speaks_their_words(): void
    {
        $expect = ['shelfie' => 'demo shop', 'kuku-tally' => 'demo farm', 'pledge-and-budget' => 'demo event'];
        foreach ($expect as $account => $name) {
            app(PublicDemo::class)->run(false, $account);
            $company = PublicDemo::company($account);
            $owner = PublicDemo::owner($account);
            $staff = \App\Models\User::withoutGlobalScopes()->where('company_id', $company->id)->where('id', '!=', $owner->id)->first();
            $this->assertTrue(PublicDemo::isDemoCompany($company));
            $this->assertTrue(PublicDemo::isDemoUser($owner));
            $this->assertTrue(PublicDemo::isDemoUser($staff), 'each role opens in one click, so each is shared');

            foreach ([
                fn () => app(CurrencyChangeService::class)->change($company, $owner, 'EUR', 'relabel', null, 'x'),
                fn () => app(TeamService::class)->createMember($company, $owner, 'Visitor Friend', null, '0772000111', 'password123', 'manager'),
                fn () => app(TeamService::class)->setPassword($company, $owner, $owner, 'password123'),
                fn () => app(TeamService::class)->setPassword($company, $owner, $staff, 'password123'),
                fn () => app(TenantDataService::class)->requestDeletion($company, $owner, 'x'),
                fn () => PublicDemo::guard($company, 'paying for a plan'),
            ] as $i => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$account}: attempt {$i} should be refused");
                } catch (BusinessRuleException $e) {
                    $this->assertSame('demo_locked', $e->errorCode(), "{$account}: attempt {$i}");
                    $message = $e->getMessage();
                }
            }
            try {
                PublicDemo::guard($company, 'deleting the shop');
            } catch (BusinessRuleException $e) {
                $message = $e->getMessage();
            }
            $this->assertStringContainsString($name, $message);
            if ($account !== 'shelfie') {
                $this->assertStringNotContainsStringIgnoringCase('shop', $message, 'a farm or an event is never called a shop');
            }

            // Nothing leaves: Messenger skips every is_demo company, and the private demo clean-up never touches it.
            $this->assertSame(1, (int) DB::table('companies')->where('id', $company->id)->value('is_demo'));
            DB::table('companies')->where('id', $company->id)->update(['created_at' => now()->subDays(30)]);
            (new DemoShopService())->purgeExpired();
            $this->assertNotNull(Company::withoutGlobalScopes()->find($company->id));
        }
    }

    public function test_a_role_opens_only_that_accounts_own_people(): void
    {
        app(PublicDemo::class)->run(false, 'kuku-tally');
        app(PublicDemo::class)->run(false, 'pledge-and-budget');
        $farm = PublicDemo::company('kuku-tally');

        $roles = PublicDemo::roles('kuku-tally');
        $this->assertSame(['owner', 'manager', 'stock_keeper', 'accountant'], array_keys($roles));
        foreach ($roles as $role => $r) {
            $u = PublicDemo::roleUser('kuku-tally', $role);
            $this->assertSame((int) $farm->id, (int) $u->company_id);
            $this->assertSame($role, DB::table('company_members')->where('company_id', $farm->id)->where('user_id', $u->id)->value('role'));
        }
        $this->assertNull(PublicDemo::roleUser('kuku-tally', 'cashier'), 'no such role here');
        $this->assertNull(PublicDemo::roleUser('kuku-tally', 'viewer'), "the committee's, not the farm's");
        $this->assertNull(PublicDemo::roleUser('kuku-tally', (string) PublicDemo::owner('pledge-and-budget')->id));
        $this->assertNull(PublicDemo::roleUser('kuku-tally', PublicDemo::spec('pledge-and-budget')['username']));
    }

    public function test_the_phones_keep_budget_pros_account_and_may_name_their_own(): void
    {
        $this->postJson('/api/v1/demo/session', ['app' => 'kuku-tally'])->assertStatus(409)->assertJsonPath('errors.code', 'demo_rebuilding');
        app(PublicDemo::class)->run(false, 'kuku-tally');
        $this->postJson('/api/v1/demo/session', ['app' => 'kuku-tally', 'device_name' => 'android'])->assertOk()->assertJsonPath('data.demo', true)->assertJsonPath('data.app', 'kuku-tally')
            ->assertJsonPath('data.company.id', (int) PublicDemo::company('kuku-tally')->id);
        $this->postJson('/api/v1/demo/session', ['app' => 'no-such-app'])->assertStatus(422)->assertJsonPath('errors.code', 'demo_unknown_app');
        $this->postJson('/api/v1/demo/session', ['app' => 'akiba-ledger'])->assertStatus(422);
        // No `app`: exactly as before, whatever else exists.
        $this->postJson('/api/v1/demo/session')->assertStatus(409);
        $response = $this->postJson('/api/v1/demo/session', ['app' => 'kuku-tally']);
        $this->assertStringNotContainsString(PublicDemo::spec('kuku-tally')['password'], $response->getContent());
    }

    public function test_at_most_one_due_account_is_rebuilt_per_run(): void
    {
        $demo = app(PublicDemo::class);
        $first = $demo->runAll(false, 'kuku-tally');
        $this->assertSame('built', $first['kuku-tally']['action']);
        $demo->run(false, 'pledge-and-budget');

        $this->travel(73)->hours();
        $results = $demo->runAll(false, 'kuku-tally') + $demo->run(false, 'pledge-and-budget', true);
        $this->assertSame('rebuilt', $results['kuku-tally']['action']);
        $this->assertSame('healed', $results['action'], 'held for the next hour');
        $this->assertSame(AppDemoBuilder::BUILD, PublicDemo::company('pledge-and-budget')->onboarding_state['public_demo']['snapshot']['build']);
    }

    public function test_the_command_never_prints_a_password_and_refuses_an_unknown_account(): void
    {
        $this->assertSame(1, Artisan::call('demo:public', ['--app' => 'no-such']));
        $this->assertSame(0, Artisan::call('demo:public', ['--app' => 'kuku-tally']));
        $out = Artisan::output();
        $this->assertStringContainsString('kuku-tally: built', $out);
        $this->assertStringNotContainsString(PublicDemo::spec('kuku-tally')['password'], $out);
    }

    public function test_every_demo_job_runs_at_a_minute_the_hosts_scheduler_reaches(): void
    {
        $this->app->forgetInstance(Schedule::class);
        $minutes = [];
        foreach (app(Schedule::class)->events() as $event) {
            $name = (string) ($event->description ?? '');
            if (in_array($name, ['demo:public', 'sacco:demo', 'rentals:demo'], true)) {
                $minutes[$name] = explode(' ', $event->expression)[0];
            }
        }
        $this->assertSame(['demo:public' => '40', 'sacco:demo' => '20', 'rentals:demo' => '40'], $minutes);
    }
}
