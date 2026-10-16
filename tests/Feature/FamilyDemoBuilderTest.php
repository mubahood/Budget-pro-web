<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\FamilyDemoBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The family demo accounts (demo:family): built through the models, with the figures the phone pictures show. */
class FamilyDemoBuilderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_farm_has_the_figures_kuku_tally_shows(): void
    {
        $r = (new FamilyDemoBuilder())->build(['farm'])['farm'];
        $cid = $r['company_id'];
        $owner = User::withoutGlobalScopes()->where('email', 'nsubuga.demo@local.test')->firstOrFail();
        $company = Company::withoutGlobalScopes()->findOrFail($cid);

        $this->assertTrue(Hash::check('secret123', $owner->password));
        $this->assertSame((int) $owner->id, (int) $company->owner_id);
        $this->assertSame(['poultry', 'finance'], $company->enabled_modules);
        $this->assertSame('poultry', $company->business_type);

        $layers = DB::table('poultry_batches')->where('company_id', $cid)->where('name', 'Layers House 1')->first();
        $deaths = DB::table('poultry_daily_records')->where('batch_id', $layers->id)->sum('mortality')
            + DB::table('poultry_mortality_events')->where('batch_id', $layers->id)->sum('count');
        $this->assertSame(305, $layers->start_count - (int) $deaths, 'hens alive');
        $today = DB::table('poultry_daily_records')->where('batch_id', $layers->id)->where('date', now('Africa/Kampala')->toDateString())->first();
        $this->assertSame(238, $today->eggs_trays * 30 + $today->eggs_loose, 'eggs today: 78% of 305');
        $this->assertSame(38, intdiv(now('Africa/Kampala')->startOfDay()->diffInDays($layers->acquired_date), 7), 'weeks old');

        $bought = (float) DB::table('poultry_feed_stock')->where('company_id', $cid)->where('direction', 'in')->sum('qty_kg');
        $eaten = (float) DB::table('poultry_daily_records')->where('company_id', $cid)->sum('feed_kg');
        $this->assertEquals(190, $bought - $eaten, 'feed in stock (about five days)');
        $this->assertSame(14732000.0, (float) DB::table('poultry_sales')->where('company_id', $cid)->sum('total'));
        $this->assertSame(206000.0, (float) DB::table('poultry_sales')->where('company_id', $cid)->sum(DB::raw('total - amount_paid')), 'customers owe');
        $this->assertSame(1, DB::table('poultry_vaccination_events')->where('company_id', $cid)->where('done', 0)->count(), 'one vaccine due');
        $this->assertSame($r['summary']['farm']['farm_expenses_recorded'], (int) DB::table('poultry_expenses')->where('company_id', $cid)->sum('amount'));
        $this->assertSame(64, DB::table('financial_records')->where('company_id', $cid)->count(), 'every farm expense mirrored to the ledger');

        // Batch profit, as Kuku Tally works it out: income − running costs − chicks.
        $profit = fn (object $b) => (float) DB::table('poultry_sales')->where('batch_id', $b->id)->sum('total')
            - (float) DB::table('poultry_expenses')->where('batch_id', $b->id)->sum('amount') - $b->start_count * $b->cost_per_chick;
        $this->assertSame(1028500.0, $profit($layers));
        $this->assertSame(614000.0, $profit(DB::table('poultry_batches')->where('company_id', $cid)->where('name', 'Broilers Batch 2')->first()));
    }

    public function test_the_wedding_has_the_figures_pledge_and_budget_shows_and_a_rebuild_starts_clean(): void
    {
        $builder = new FamilyDemoBuilder();
        $first = $builder->build(['wedding'])['wedding']['company_id'];
        $cid = $builder->build(['wedding'])['wedding']['company_id'];

        $this->assertNull(Company::withoutGlobalScopes()->find($first), 'the old account is gone');
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'brian.demo@local.test')->count());
        $p = DB::table('budget_programs')->where('company_id', $cid)->first();
        $this->assertSame('Brian & Grace Wedding', $p->name);
        $this->assertSame(now('Africa/Kampala')->addDays(77)->toDateString(), $p->deadline, 'eleven weeks to the day');
        $this->assertSame([18400000, 11250000, 7150000, 20500000, 8510000],
            [(int) $p->total_expected, (int) $p->total_collected, (int) $p->total_in_pledge, (int) $p->budget_total, (int) $p->budget_spent]);
        $this->assertSame(60, DB::table('contribution_records')->where('company_id', $cid)->count());
        $this->assertSame(15, DB::table('contribution_records')->where('company_id', $cid)->where('paid_amount', 0)->count());
        $this->assertSame(10, DB::table('budget_item_categories')->where('company_id', $cid)->count());
        $treasurer = User::withoutGlobalScopes()->where('email', 'doreen.demo@local.test')->firstOrFail();
        $this->assertSame(60, DB::table('contribution_records')->where('company_id', $cid)->where('treasurer_id', $treasurer->id)->count());
    }

    public function test_the_command_refuses_outside_a_developer_machine(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('demo:family', ['--only' => ['farm']]));
        $this->assertStringContainsString('developer machine only', Artisan::output());
        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'nsubuga.demo@local.test')->exists());
    }
}
