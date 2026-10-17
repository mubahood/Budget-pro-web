<?php

namespace Tests\Feature;

use App\Services\Onboarding\DemoPledgeExtras;
use App\Services\Onboarding\FamilyDemoBuilder;
use App\Services\Onboarding\PublicDemo;
use App\Services\Onboarding\PublicDemoBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RunsAsStandalone;
use Tests\TestCase;

/**
 * The public demo with every module on: the shop as it was, plus the Mukono farm and the Brian & Grace
 * wedding (in the shop's dollars), added once on a build, kept up by the heal without a row twice, and
 * the phones' "Try the live demo" sign-in (POST /api/v1/demo/session).
 */
class PublicDemoFamilyTest extends TestCase
{
    use DatabaseTransactions, RunsAsStandalone;

    private const FARM_TABLES = ['poultry_batches', 'poultry_daily_records', 'poultry_sales', 'poultry_expenses', 'poultry_feed_stock', 'poultry_customers',
        'poultry_vaccination_events', 'poultry_mortality_events', 'poultry_health_events', 'poultry_egg_transactions', 'poultry_feed_types'];

    private const WEDDING_TABLES = ['budget_programs', 'budget_item_categories', 'budget_items', 'contribution_records'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.days' => 2, 'demo.enabled' => true, 'demo.password' => 'demo2026', 'demo.email' => 'demo@schooldynamics.ug']);
        DB::table('admin_users')->where('username', PublicDemo::USERNAME)->update(['username' => 'public-demo-local-'.uniqid()]);
    }

    /** @return array<string, int> rows per table for the demo company */
    private function counts(int $cid): array
    {
        $extras = array_filter(['contribution_payments', 'budget_program_members'], fn ($t) => Schema::hasTable($t));

        return collect(array_merge(self::FARM_TABLES, self::WEDDING_TABLES, $extras))->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $cid)->count()])->all();
    }

    public function test_a_build_has_every_module_the_shop_as_before_and_the_farm_and_the_wedding_once(): void
    {
        $this->assertSame('built', app(PublicDemo::class)->run()['action']);
        $company = PublicDemo::company();
        $cid = (int) $company->id;

        $this->assertSame(['shop', 'poultry', 'budget', 'finance'], $company->enabled_modules);
        $this->assertSame('USD', $company->currency);
        $this->assertSame(45, DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', 0)->count(), 'the shop as it was');

        // The farm: one laying house of 305 hens, today's card written, 38 weeks old.
        $layers = DB::table('poultry_batches')->where('company_id', $cid)->where('name', PublicDemoBuilder::LAYERS)->get();
        $this->assertCount(1, $layers);
        $layers = $layers->first();
        $dead = DB::table('poultry_daily_records')->where('batch_id', $layers->id)->sum('mortality') + DB::table('poultry_mortality_events')->where('batch_id', $layers->id)->sum('count');
        $this->assertSame(305, $layers->start_count - (int) $dead);
        $today = now($company->timezone ?: 'UTC')->toDateString();
        $this->assertSame(1, DB::table('poultry_daily_records')->where('batch_id', $layers->id)->where('date', $today)->count(), "today's card");
        $this->assertEquals(0.9, $layers->cost_per_chick, 'UGX 3,600 a chick in dollars');
        $this->assertEquals(3.0, DB::table('poultry_daily_records')->where('batch_id', $layers->id)->where('date', $today)->value('egg_unit_price'), 'a tray at $3');
        $this->assertSame(0, DB::table('financial_records')->where('company_id', $cid)->where('description', 'like', '%Layers Mash%')->count(), "the farm's costs stay in the farm book");
        $this->assertSame(0, DB::table('poultry_daily_records')->where('company_id', $cid)->where('created_at', '>', now()->addMinute())->count(), 'nothing dated in the future');

        // The wedding: 60 pledges in whole dollars, eleven weeks away.
        $program = DB::table('budget_programs')->where('company_id', $cid)->where('name', FamilyDemoBuilder::WEDDING_NAME)->first();
        $this->assertNotNull($program);
        $this->assertSame(60, DB::table('contribution_records')->where('budget_program_id', $program->id)->count());
        $pledged = array_sum(array_map(fn ($p) => (int) round($p[2] / 4000), FamilyDemoBuilder::WEDDING_PLEDGES));
        $this->assertSame($pledged, (int) DB::table('contribution_records')->where('budget_program_id', $program->id)->sum('amount'));
        $this->assertSame(now($company->timezone ?: 'UTC')->addDays(77)->toDateString(), substr((string) $program->deadline, 0, 10));
        $this->assertSame(1, DB::table('budget_programs')->where('company_id', $cid)->count());

        // The pledge features of roadmap 02 §6, once their tables exist: phones, payments on their days, the committee.
        if (DemoPledgeExtras::phonesReady()) {
            $this->assertSame(0, DB::table('contribution_records')->where('budget_program_id', $program->id)->whereNull('phone')->count(), 'a phone on every pledge');
        }
        if (DemoPledgeExtras::paymentsReady()) {
            $paid = (float) DB::table('contribution_records')->where('budget_program_id', $program->id)->sum('paid_amount');
            $this->assertGreaterThan(0, $paid);
            $this->assertEquals($paid, (float) DB::table('contribution_payments')->where('budget_program_id', $program->id)->where('is_deleted', 0)->sum('amount'), 'paid = the history');
            $this->assertGreaterThan(DB::table('contribution_records')->where('budget_program_id', $program->id)->where('paid_amount', '>', 0)->count(),
                DB::table('contribution_payments')->where('budget_program_id', $program->id)->count(), 'some pledges paid in two goes');
            $this->assertGreaterThan(1, DB::table('contribution_payments')->where('budget_program_id', $program->id)->distinct()->count('paid_on'), 'paid on different days');
        }
        if (Schema::hasTable('budget_program_members')) {
            $roles = DB::table('budget_program_members')->where('budget_program_id', $program->id)->pluck('role', 'user_id')->all();
            $this->assertSame('chair', $roles[PublicDemo::owner()->id] ?? null, 'the demo account chairs it');
            $this->assertContains('treasurer', $roles);
        }

        $snap = $company->onboarding_state['public_demo']['snapshot'];
        $this->assertSame((int) $layers->id, $snap['farm']['layers']);
        $this->assertSame((int) $program->id, $snap['wedding']);
    }

    public function test_the_heal_adds_no_duplicates_and_puts_back_a_deleted_farm_or_wedding(): void
    {
        app(PublicDemo::class)->run();
        $cid = (int) PublicDemo::company()->id;
        $before = $this->counts($cid);

        $this->assertSame('healed', app(PublicDemo::class)->run()['action']);
        $this->assertSame($before, $this->counts($cid), 'a heal adds nothing that is already there');

        // A visitor switches the laying house off and deletes the wedding outright.
        DB::table('poultry_batches')->where('company_id', $cid)->where('name', PublicDemoBuilder::LAYERS)->update(['is_deleted' => 1]);
        DB::table('contribution_records')->where('company_id', $cid)->delete();
        DB::table('budget_programs')->where('company_id', $cid)->delete();

        $result = app(PublicDemo::class)->run();
        $this->assertSame('healed', $result['action']);
        $this->assertContains('farm batch', $result['repairs']);
        $this->assertContains('wedding', $result['repairs']);
        $this->assertSame(0, (int) DB::table('poultry_batches')->where('company_id', $cid)->where('name', PublicDemoBuilder::LAYERS)->value('is_deleted'));
        $this->assertSame($before, $this->counts($cid), 'the same rows again, once');
        $wedding = DB::table('budget_programs')->where('company_id', $cid)->value('id');
        $this->assertSame((int) $wedding, PublicDemo::company()->onboarding_state['public_demo']['snapshot']['wedding']);
    }

    public function test_a_demo_built_as_a_shop_only_gets_every_module_the_farm_and_the_wedding_on_its_next_heal(): void
    {
        app(PublicDemo::class)->run();
        $company = PublicDemo::company();
        $cid = (int) $company->id;
        $before = $this->counts($cid);
        foreach (array_merge(self::WEDDING_TABLES, array_reverse(self::FARM_TABLES)) as $t) {
            DB::table($t)->where('company_id', $cid)->delete();
        }
        $state = $company->onboarding_state;
        unset($state['public_demo']['snapshot']['farm'], $state['public_demo']['snapshot']['wedding']);
        $state['public_demo']['snapshot']['company']['enabled_modules'] = ['shop', 'finance'];
        DB::table('companies')->where('id', $cid)->update(['enabled_modules' => json_encode(['shop', 'finance']), 'onboarding_state' => json_encode($state)]);

        $result = app(PublicDemo::class)->run();

        $this->assertSame('healed', $result['action']);
        $company = PublicDemo::company();
        $this->assertSame(['shop', 'poultry', 'budget', 'finance'], $company->enabled_modules);
        $this->assertSame(['shop', 'poultry', 'budget', 'finance'], $company->onboarding_state['public_demo']['snapshot']['company']['enabled_modules']);
        $this->assertSame($before, $this->counts($cid));
    }

    public function test_the_farm_gets_its_missing_days_up_to_today_once(): void
    {
        app(PublicDemo::class)->run();
        $company = PublicDemo::company();
        $cid = (int) $company->id;
        $layers = (int) DB::table('poultry_batches')->where('company_id', $cid)->where('name', PublicDemoBuilder::LAYERS)->value('id');
        $tz = $company->timezone ?: 'UTC';
        DB::table('poultry_daily_records')->where('batch_id', $layers)->where('date', '>=', now($tz)->subDays(2)->toDateString())->delete();
        $owner = PublicDemo::owner();

        $this->assertSame(3, (new FamilyDemoBuilder())->topUpFarm($company, $owner, $layers));
        $this->assertSame(0, (new FamilyDemoBuilder())->topUpFarm($company, $owner, $layers), 'never a day twice');
        $card = DB::table('poultry_daily_records')->where('batch_id', $layers)->where('date', now($tz)->toDateString())->first();
        $this->assertEqualsWithDelta(78 * 305 / 100, $card->eggs_trays * 30 + $card->eggs_loose, 4, 'about 78% of 305 hens');
    }

    public function test_the_pledge_extras_wait_for_their_tables_and_phones_are_ugandan_mobiles(): void
    {
        $this->assertMatchesRegularExpression('/^\+2567[0-8]\d{7}$/', DemoPledgeExtras::phoneFor('Okello James'));
        $this->assertSame(DemoPledgeExtras::phoneFor('Okello James'), DemoPledgeExtras::phoneFor('Okello James'));
        $this->assertSame([], (new DemoPledgeExtras())->apply(0, 0), 'no event, nothing to do');
    }

    public function test_the_phone_signs_into_the_live_demo_without_its_password_and_the_demo_locks_hold(): void
    {
        $this->postJson('/api/v1/demo/session')->assertStatus(409)->assertJsonPath('errors.code', 'demo_rebuilding');

        app(PublicDemo::class)->run();
        $res = $this->postJson('/api/v1/demo/session', ['device_name' => 'android'])->assertOk()
            ->assertJsonPath('data.demo', true)->assertJsonPath('data.company.id', (int) PublicDemo::company()->id);
        $this->assertStringNotContainsString('demo2026', $res->getContent(), 'the password is never sent');
        $token = $res->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'demo2026', 'password' => 'Another-pass-99', 'password_confirmation' => 'Another-pass-99'])
            ->assertStatus(422);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('demo2026', PublicDemo::owner()->fresh()->password));
        $expires = DB::table('personal_access_tokens')->where('tokenable_id', PublicDemo::owner()->id)->orderByDesc('id')->value('expires_at');
        $this->assertLessThanOrEqual(now()->addHours(12)->addMinute()->timestamp, strtotime((string) $expires), 'a short-lived token');

        config(['demo.enabled' => false]);
        $this->postJson('/api/v1/demo/session')->assertNotFound();
        config(['demo.enabled' => true]);
        $this->asStandalone();
        $this->postJson('/api/v1/demo/session')->assertStatus(404);
    }
}
