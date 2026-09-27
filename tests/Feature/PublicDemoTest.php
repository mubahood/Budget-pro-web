<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\DemoShopService;
use App\Services\Onboarding\PublicDemo;
use App\Services\Shop\CurrencyChangeService;
use App\Services\Team\TeamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The public demo shop: one shared account, built through the services, healed hourly, rebuilt every 72 hours, locked where it matters. */
class PublicDemoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.days' => 3, 'demo.enabled' => true, 'demo.password' => 'demo2026', 'demo.email' => 'demo@schooldynamics.ug']);
        // Only one public demo exists at a time; a local one is set aside for the test.
        DB::table('admin_users')->where('username', PublicDemo::USERNAME)->update(['username' => 'public-demo-local-'.uniqid()]);
    }

    public function test_it_builds_a_complete_shop_and_hands_it_to_the_demo_account(): void
    {
        $result = app(PublicDemo::class)->run();

        $this->assertSame('built', $result['action']);
        $company = PublicDemo::company();
        $owner = PublicDemo::owner();
        $this->assertNotNull($company);
        $this->assertTrue((bool) $company->is_demo);
        $this->assertNull($company->demo_parent_id);
        $this->assertSame((int) $company->id, (int) $owner->company_id);
        $this->assertTrue(Hash::check('demo2026', $owner->password));
        $this->assertSame('owner', DB::table('company_members')->where('company_id', $company->id)->where('user_id', $owner->id)->value('role'));
        $this->assertTrue(PublicDemo::isDemoUser($owner));

        $cid = $company->id;
        $this->assertSame(45, DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', 0)->count());
        $this->assertGreaterThanOrEqual(40, DB::table('stock_items')->where('company_id', $cid)->where('image', 'like', 'images/demo/%')->count());
        $this->assertGreaterThan(20, DB::table('sale_records')->where('company_id', $cid)->count());
        $this->assertSame(12, DB::table('customers')->where('company_id', $cid)->count());
        $this->assertGreaterThanOrEqual(5, DB::table('promotions')->where('company_id', $cid)->count());
        $this->assertSame(6, DB::table('admin_users')->where('company_id', $cid)->count(), 'the demo account and five staff');
        $this->assertSame('manager', DB::table('company_members')->where('company_id', $cid)->where('user_id', '!=', $owner->id)->orderBy('id')->value('role'));
        $this->assertEquals(0, DB::table('stock_items')->where('company_id', $cid)->where('name', 'Wireless Headphones')->value('current_quantity'), 'sold out, on order');
        $this->assertEquals(3, DB::table('stock_items')->where('company_id', $cid)->where('name', 'Wildflower Honey 450g')->value('current_quantity'), 'below its reorder level');
        $this->assertSame(['ready' => true, 'email' => 'demo@schooldynamics.ug', 'password' => 'demo2026', 'pin' => '1234'],
            array_intersect_key(PublicDemo::status(), array_flip(['ready', 'email', 'password', 'pin'])));
    }

    public function test_the_hourly_heal_puts_back_what_visitors_changed(): void
    {
        app(PublicDemo::class)->run();
        $company = PublicDemo::company();
        $owner = PublicDemo::owner();
        $product = DB::table('stock_items')->where('company_id', $company->id)->whereNotNull('image')->first();
        $staff = DB::table('admin_users')->where('company_id', $company->id)->where('id', '!=', $owner->id)->first();

        $owner->forceFill(['password' => Hash::make('someone-elses')])->save();
        DB::table('companies')->where('id', $company->id)->update(['name' => 'Renamed by a visitor', 'negative_stock_policy' => 'block']);
        DB::table('stock_items')->where('id', $product->id)->update(['is_deleted' => 1, 'image' => null]);
        DB::table('admin_users')->where('id', $staff->id)->update(['status' => 'Inactive']);

        $result = app(PublicDemo::class)->run();

        $this->assertSame('healed', $result['action']);
        $this->assertSame((int) $company->id, $result['company_id'], 'the same shop: a heal is not a rebuild');
        $this->assertTrue(Hash::check('demo2026', PublicDemo::owner()->password));
        $this->assertSame('Fresh Corner Market', Company::withoutGlobalScopes()->find($company->id)->name);
        $this->assertSame('allow', Company::withoutGlobalScopes()->find($company->id)->negative_stock_policy);
        $this->assertSame(0, (int) DB::table('stock_items')->where('id', $product->id)->value('is_deleted'));
        $this->assertSame($product->image, DB::table('stock_items')->where('id', $product->id)->value('image'));
        $this->assertSame('Active', DB::table('admin_users')->where('id', $staff->id)->value('status'));
        $this->assertContains('password', $result['repairs']);
    }

    public function test_after_72_hours_it_is_rebuilt_beside_the_old_shop_which_is_then_deleted(): void
    {
        app(PublicDemo::class)->run();
        $old = PublicDemo::company();
        $oldStaff = DB::table('admin_users')->where('company_id', $old->id)->where('username', '!=', PublicDemo::USERNAME)->pluck('id');

        $this->travel(73)->hours();
        $result = app(PublicDemo::class)->run();

        $this->assertSame('rebuilt', $result['action']);
        $new = PublicDemo::company();
        $this->assertNotSame((int) $old->id, (int) $new->id);
        $this->assertSame((int) $new->id, (int) PublicDemo::owner()->company_id);
        $this->assertNull(Company::withoutGlobalScopes()->find($old->id), 'the old shop is gone');
        $this->assertSame(0, DB::table('admin_users')->whereIn('id', $oldStaff)->count(), 'with its staff');
        $this->assertSame(0, DB::table('sale_records')->where('company_id', $old->id)->count());
        $this->assertNotNull(DB::table('admin_users')->where('username', PublicDemo::USERNAME)->first(), 'the demo account itself stays');
    }

    public function test_visitors_cannot_change_the_currency_add_people_or_touch_the_shared_sign_in(): void
    {
        app(PublicDemo::class)->run();
        $company = PublicDemo::company();
        $owner = PublicDemo::owner();

        foreach ([
            fn () => app(CurrencyChangeService::class)->change($company, $owner, 'EUR', 'relabel', null, 'demo2026'),
            fn () => app(TeamService::class)->createMember($company, $owner, 'Visitor Friend', null, '0772000111', 'password123', 'cashier'),
            fn () => app(TeamService::class)->setPassword($company, $owner, $owner, 'password123'),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} should be refused");
            } catch (BusinessRuleException $e) {
                $this->assertSame('demo_locked', $e->errorCode());
            }
        }
        $this->assertSame('USD', $company->fresh()->currency);
    }

    public function test_the_private_demo_clean_up_never_deletes_the_public_demo_and_real_shops_are_not_demos(): void
    {
        app(PublicDemo::class)->run();
        $company = PublicDemo::company();
        DB::table('companies')->where('id', $company->id)->update(['created_at' => now()->subDays(30)]);

        (new DemoShopService())->purgeExpired();

        $this->assertNotNull(Company::withoutGlobalScopes()->find($company->id));
        $real = Company::withoutGlobalScopes()->where('is_demo', 0)->first();
        if ($real) {
            $this->assertFalse(PublicDemo::isDemoCompany($real));
            PublicDemo::guard($real, 'anything'); // no exception for a real shop
        }
        $this->assertFalse(PublicDemo::isDemoUser(User::withoutGlobalScopes()->where('company_id', '!=', $company->id)->whereNotNull('company_id')->first()));
    }
}
