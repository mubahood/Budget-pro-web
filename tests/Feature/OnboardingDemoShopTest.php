<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use App\Services\Onboarding\DemoShopService;
use App\Services\Onboarding\RegistrationService;
use App\Services\Shop\CustomerService;
use App\Models\Customer;
use Database\Seeders\ProductTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The demo shop (POWER_PLAN §3.1): its own account, believable trading through the services, no messages, no quota, deleted after a week. */
class OnboardingDemoShopTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{user: User, company: Company} */
    private function shop(): array
    {
        (new ProductTemplateSeeder())->run();
        $r = app(RegistrationService::class)->register(['first_name' => 'Kato', 'last_name' => 'Owner', 'email' => 'k'.uniqid('', true).'@example.test',
            'password' => 'secret123', 'company_name' => 'Kato Store', 'currency' => 'KES', 'country' => 'KE', 'business_type' => 'retail'], RegistrationService::PRODUCT_BUDGET, 'shop-web');

        return ['user' => $r['user']->fresh(), 'company' => $r['company']->fresh()];
    }

    public function test_a_demo_is_a_separate_shop_with_its_own_account_and_real_looking_trading(): void
    {
        ['user' => $owner, 'company' => $real] = $this->shop();
        $svc = new DemoShopService();
        $demo = $svc->create($owner, 12);

        $this->assertTrue((bool) $demo->is_demo);
        $this->assertSame((int) $real->id, (int) $demo->demo_parent_id);
        $this->assertSame('KES', $demo->currency);
        $this->assertSame((int) $real->id, (int) $owner->fresh()->company_id, 'the real account (and its phone) never moves');
        $demoUser = (new DemoShopService())->demoUser($demo);
        $this->assertSame('demo+'.$owner->id, $demoUser->username);
        $this->assertSame((int) $demo->id, (int) $demoUser->company_id);
        $this->assertNull($demoUser->email);
        $this->assertSame((int) $owner->id, (int) (new DemoShopService())->parentUserFor($demoUser)->id);
        $this->assertSame('active', $demo->accessState());

        $cid = (int) $demo->id;
        $this->assertGreaterThanOrEqual(15, DB::table('stock_items')->where('company_id', $cid)->count());
        $sales = DB::table('sale_records')->where('company_id', $cid);
        $this->assertGreaterThan(15, (clone $sales)->count());
        $this->assertGreaterThanOrEqual(8, (clone $sales)->distinct()->count('sale_date'), 'spread over the days');
        $this->assertLessThan(now()->subDays(8)->toDateString(), (string) (clone $sales)->min('sale_date'));
        $first = (clone $sales)->orderBy('id')->first();
        $this->assertSame(substr((string) $first->sale_date, 0, 10), \Illuminate\Support\Carbon::parse($first->created_at)->setTimezone('Africa/Nairobi')->toDateString(), 'times match the dates');
        $this->assertSame(0, DB::table('sale_records')->where('company_id', $real->id)->count(), 'nothing lands in the real shop');
        $this->assertSame(2, DB::table('sale_returns')->where('company_id', $cid)->count());
        $this->assertSame(2, DB::table('goods_receipts')->where('company_id', $cid)->count());
        $this->assertSame(1, DB::table('suppliers')->where('company_id', $cid)->count());
        $this->assertSame(5, DB::table('customers')->where('company_id', $cid)->count());
        $this->assertSame(0, DB::table('onboarding_events')->where('company_id', $cid)->count(), 'demo trading writes no onboarding events');
        $this->assertSame(1, DB::table('onboarding_events')->where('company_id', $real->id)->where('event', 'demo_created')->count());

        // The balances agree with budget-pro's own customer service.
        foreach (Customer::withoutGlobalScopes()->where('company_id', $cid)->get() as $c) {
            $this->assertEqualsWithDelta((float) DB::table('sale_records')->where('customer_id', $c->id)->whereNull('voided_at')->sum('balance'), app(CustomerService::class)->balance($c), 0.01);
        }

        // Asking again gives the same demo.
        $this->assertSame($cid, (int) (new DemoShopService())->create($owner, 12)->id);
    }

    public function test_demo_shops_are_outside_quotas_and_messaging(): void
    {
        ['user' => $owner] = $this->shop();
        $demo = (new DemoShopService())->create($owner, 2);
        $this->assertNull((new Quotas())->limit($demo, 'products'));
        $id = app(Messenger::class)->send('+254700000001', 'Hello', ['whatsapp'], ['company_id' => $demo->id, 'purpose' => 'receipt']);
        $row = DB::table('message_log')->find($id);
        $this->assertSame('skipped', $row->status);
        $this->assertSame('demo shop', $row->error);
    }

    public function test_only_owners_make_demos_and_a_demo_cannot_make_one(): void
    {
        ['user' => $owner, 'company' => $real] = $this->shop();
        $cashier = new User();
        $cashier->forceFill(['name' => 'C', 'first_name' => 'C', 'username' => 'c'.uniqid(), 'password' => bcrypt('x'), 'status' => 'Active', 'company_id' => $real->id])->save();
        DB::table('company_members')->insert(['company_id' => $real->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        \App\Services\Team\Permissions::flush();
        $this->assertFalse((new DemoShopService())->allowed($cashier->fresh()));
        try {
            (new DemoShopService())->create($cashier->fresh(), 2);
            $this->fail('a cashier cannot make a demo');
        } catch (BusinessRuleException $e) {
            $this->assertSame('forbidden', $e->errorCode());
        }

        $demo = (new DemoShopService())->create($owner, 2);
        $this->expectException(BusinessRuleException::class);
        (new DemoShopService())->create((new DemoShopService())->demoUser($demo), 2);
    }

    public function test_the_hourly_job_deletes_week_old_demos_and_never_a_real_shop(): void
    {
        ['user' => $owner, 'company' => $real] = $this->shop();
        $svc = new DemoShopService();
        $demo = $svc->create($owner, 2);
        $demoUserId = (int) $svc->demoUser($demo)->id;

        Artisan::call('onboarding:purge-demos');
        $this->assertNotNull(Company::withoutGlobalScopes()->find($demo->id), 'a fresh demo stays');

        DB::table('companies')->where('id', $demo->id)->update(['created_at' => now()->subDays(DemoShopService::KEEP_DAYS + 1)]);
        DB::table('companies')->where('id', $real->id)->update(['created_at' => now()->subDays(30)]);
        Artisan::call('onboarding:purge-demos');
        $this->assertNull(Company::withoutGlobalScopes()->find($demo->id));
        $this->assertSame(0, DB::table('sale_records')->where('company_id', $demo->id)->count());
        $this->assertNull(DB::table('admin_users')->find($demoUserId));
        $this->assertNotNull(Company::withoutGlobalScopes()->find($real->id));
        $this->assertNotNull(DB::table('admin_users')->find($owner->id));

        $this->expectException(BusinessRuleException::class);
        $svc->purge($real);
    }
}
