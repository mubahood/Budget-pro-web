<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Middleware\EnsureApiModule;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Scopes\CompanyScope;
use App\Services\Auth\OtpService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\Lifecycle;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use App\Support\Rules\CompanyRules;
use App\Support\ShopMenu;
use App\Support\SupportContact;
use Database\Seeders\EstateLedgerPlanSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Estate Ledger's module plumbing (docs/estate-ledger/04-architecture.md §2, KR-04, KR-05, KR-20): `rentals` is a module of its
 * own that stands alone (no shop, no Income & expenses, no moving in or out), on its own plan line counted in units,
 * with its own roles, viewer and menu, and its refusals name Estate Ledger only. Family and SACCO accounts keep their own
 * tests (CompanyRulesSaccoTest, PlanLineTest), which still pass unchanged in what they check.
 */
class RentalsPlumbingTest extends ApiTestCase
{
    private const TEN = ['rentals_view', 'rentals_manage', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords',
        'rentals_expenses', 'rentals_maintenance', 'rentals_reports', 'rentals_settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(EstateLedgerPlanSeeder::class);
    }

    private function company(array $t): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($t['company_id']);
    }

    private function estateLedger(): string
    {
        return CompanyRules::EXCLUSIVE_MESSAGES['rentals'];
    }

    public function test_the_module_is_declared_wherever_the_sacco_one_is(): void
    {
        $this->assertSame('Rentals and tenants', config('onboarding.modules.rentals'));
        $this->assertSame([], config('onboarding.module_paths.rentals'), 'no classic screens');
        $this->assertSame(['label' => 'Letting agency or landlord', 'modules' => ['rentals'], 'group' => 'Something else', 'icon' => 'fa-key',
            'hint' => 'Properties, tenants, rent and landlords'], config('onboarding.business_types.rentals'));
        $this->assertContains('rentals', EnsureApiModule::MODULES);
        $this->assertContains('rentals', CompanyRules::CORE_MODULES);
        $this->assertSame(['sacco', 'rentals'], CompanyRules::EXCLUSIVE_MODULES);
        $this->assertArrayNotHasKey('rentals', CompanyRules::familyBusinessTypes(), 'only Estate Ledger signs it up');
        $this->assertContains('estate-ledger', SupportContact::APPS);
        $this->assertContains('rental_photo', FileController::PURPOSES);
        $this->assertContains('rental_document', FileController::PURPOSES);
        $this->assertSame(['rentals'], CompanyRules::signUpModules(['rentals']));
        $this->assertSame('rentals', CompanyRules::businessTypeFor(['rentals'], 'kiosk'));
        $this->assertSame(['shop', 'finance', 'budget', 'poultry'], (new Company())->modules(), 'an account that never chose never gets the rental books');
        $this->assertFalse(Entitlements::DEFAULT_FEATURES['rentals_portal']);
        $this->assertFalse(Entitlements::DEFAULT_FEATURES['rentals_sms']);

        // Every Estate Ledger model is scoped to its company (TenantModelsTest scans app/Models the same way).
        $models = glob(app_path('Models/Rental*.php'));
        $this->assertNotEmpty($models);
        foreach ($models as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $this->assertArrayHasKey(CompanyScope::class, (new $class())->getGlobalScopes(), $class);
        }
    }

    public function test_a_rentals_sign_up_gets_the_rental_books_alone_on_the_estate_ledger_trial(): void
    {
        // What the Estate Ledger app sends.
        $t = $this->registerTenant(['modules' => ['rentals'], 'business_type' => 'retail']);
        $c = $this->company($t);
        $this->assertSame(['rentals'], $c->enabled_modules, 'no Income & expenses for an Estate Ledger account');
        $this->assertSame(['rentals'], $c->modules());
        $this->assertSame('rentals', $c->business_type);
        $this->assertSame(PlanLine::ESTATE_LEDGER, PlanLine::for($c));

        $sub = Subscription::where('company_id', $c->id)->first();
        $this->assertSame('estate-ledger-trial', $sub->plan->slug);
        $this->assertSame('trialing', $sub->status);
        $this->assertSame(30, (int) round(now()->diffInHours($sub->trial_ends_at) / 24), 'the Estate Ledger trial runs 30 days');

        // The business type alone says the same.
        $this->assertSame(['rentals'], $this->company($this->registerTenant(['business_type' => 'rentals']))->modules());
    }

    public function test_the_rental_books_never_sit_beside_another_module_and_the_refusal_names_estate_ledger(): void
    {
        $base = ['first_name' => 'A', 'last_name' => 'B', 'password' => 'secret123', 'company_name' => 'X', 'currency' => 'UGX'];
        foreach ([['rentals', 'shop'], ['rentals', 'finance']] as $i => $modules) {
            $this->postJson('/api/v1/auth/register', $base + ['email' => "kr{$i}_".uniqid().'@example.com', 'modules' => $modules])
                ->assertStatus(422)->assertJsonPath('errors.modules.0', $this->estateLedger());
        }

        // An Estate Ledger account cannot add a shop, nor become one.
        $rentals = $this->registerTenant(['modules' => ['rentals']]);
        $h = $this->auth($rentals['token']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['rentals', 'shop']], $h)
            ->assertStatus(422)->assertJsonPath('errors.modules.0', $this->estateLedger());
        $this->putJson('/api/v1/company', ['enabled_modules' => ['rentals', 'budget']], $h)
            ->assertStatus(422)->assertJsonPath('errors.enabled_modules.0', $this->estateLedger());
        $this->putJson('/api/v1/company/modules', ['modules' => ['shop']], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'module_exclusive')->assertJsonPath('message', $this->estateLedger());
        $this->assertSame(['rentals'], $this->company($rentals)->modules(), 'nothing changed');

        // A shop cannot take the rental books in.
        $shop = $this->registerTenant(['business_type' => 'retail']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['rentals']], $this->auth($shop['token']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'module_exclusive')->assertJsonPath('message', $this->estateLedger());
        $this->assertSame(['shop', 'finance'], $this->company($shop)->modules());

        // Whoever writes the model (the portal, the classic admin) meets the same rule, in the same words.
        foreach ([[$this->company($shop), ['shop', 'finance', 'rentals']], [$this->company($rentals), ['rentals', 'shop']]] as [$model, $modules]) {
            $model->enabled_modules = $modules;
            try {
                $model->save();
                $this->fail('the rental books stand alone');
            } catch (BusinessRuleException $e) {
                $this->assertSame('module_exclusive', $e->errorCode());
                $this->assertSame($this->estateLedger(), $e->getMessage());
            }
        }

        // Estate Ledger's words name no other app, and a SACCO is never told about Estate Ledger (each hears its own app first).
        foreach (['SACCO', 'Akiba', 'Budget Pro', 'Shelfie', 'Kuku', 'Pledge'] as $other) {
            $this->assertStringNotContainsString($other, $this->estateLedger());
        }
        $this->assertSame(CompanyRules::EXCLUSIVE_MESSAGE, CompanyRules::exclusiveMessage(['sacco', 'rentals'], ['sacco']));
        $this->assertSame($this->estateLedger(), CompanyRules::exclusiveMessage(['rentals', 'sacco'], ['rentals']));
    }

    public function test_estate_ledger_plans_are_their_own_line_counted_in_units(): void
    {
        $t = $this->registerTenant(['modules' => ['rentals']]);
        $company = $this->company($t);
        $shop = $this->company($this->registerTenant(['business_type' => 'retail']));

        // The seeder: five plans, idempotent, never overwriting an edited price; a year is 10 months.
        Plan::where('slug', 'estate-ledger-starter')->update(['price_ugx' => 40000]);
        $this->seed(EstateLedgerPlanSeeder::class);
        $this->assertSame(5, Plan::where('slug', 'like', 'estate-ledger-%')->count());
        $this->assertEquals(40000, (float) Plan::where('slug', 'estate-ledger-starter')->value('price_ugx'), 'a deploy keeps an edited price');
        foreach (EstateLedgerPlanSeeder::plans() as $p) {
            $this->assertSame(PlanLine::ESTATE_LEDGER, $p['features']['line'], $p['slug']);
            $this->assertFalse($p['features']['rentals_sms'], $p['slug']);
            $this->assertSame($p['slug'] !== 'estate-ledger-free', $p['features']['rentals_portal'], $p['slug']);
            if (isset($p['price_ugx_annual'])) {
                $this->assertSame($p['price_ugx'] * 10, $p['price_ugx_annual'], $p['slug']);
            }
        }

        // An Estate Ledger account sees and buys only estate-ledger-* plans; the family's list never shows them.
        $slugs = collect($this->getJson('/api/v1/plans', $this->auth($t['token']))->assertOk()->json('data'))->pluck('slug')->all();
        $this->assertSame(['estate-ledger-free', 'estate-ledger-starter', 'estate-ledger-growth', 'estate-ledger-agency'], $slugs);
        $this->assertSame($slugs, collect($this->getJson('/api/v1/plans?line=estate-ledger')->json('data'))->pluck('slug')->all(), 'the Estate Ledger site asks by line');
        $this->assertNotContains('estate-ledger-free', collect($this->getJson('/api/v1/plans')->json('data'))->pluck('slug'));

        foreach ([[Plan::where('slug', 'business')->firstOrFail(), $company, 'This plan is not for Estate Ledger accounts. Choose an Estate Ledger plan.'],
            [Plan::where('slug', 'estate-ledger-growth')->firstOrFail(), $shop, 'This plan is for Estate Ledger accounts. Choose one of your app\'s plans.']] as [$plan, $buyer, $words]) {
            try {
                PlanLine::assertFor($plan, $buyer);
                $this->fail('a plan of another line is refused');
            } catch (BusinessRuleException $e) {
                $this->assertSame('plan_line_mismatch', $e->errorCode());
                $this->assertSame($words, $e->getMessage());
            }
        }
        $this->assertSame('Estate Ledger Growth (Estate Ledger)', PlanLine::adminLabel(Plan::where('slug', 'estate-ledger-growth')->firstOrFail()));

        // Units that are not inactive count against max_units, on the Estate Ledger line only.
        $landlord = DB::table('rental_landlords')->insertGetId(['company_id' => $company->id, 'name' => 'Okello', 'created_at' => now(), 'updated_at' => now()]);
        $property = DB::table('rental_properties')->insertGetId(['company_id' => $company->id, 'landlord_id' => $landlord, 'name' => 'Ntinda Court',
            'created_at' => now(), 'updated_at' => now()]);
        foreach (['available', 'reserved', 'maintenance', 'inactive'] as $i => $status) {
            DB::table('rental_units')->insert(['company_id' => $company->id, 'property_id' => $property, 'code' => 'A'.($i + 1), 'status' => $status,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $quotas = app(Quotas::class);
        $this->assertSame(3, $quotas->used($company, 'units'));
        $this->assertSame(300, $quotas->limit($company, 'units'), 'the trial allows 300 units');
        $this->assertSame(['used' => 3, 'max' => 300, 'over' => false], $quotas->usage($company)['units']);
        $this->assertArrayNotHasKey('members', $quotas->usage($company), 'no SACCO limits on an Estate Ledger account');
        $this->assertArrayNotHasKey('units', $quotas->usage($shop), 'the family usage block is unchanged');
        $this->assertSame('estate-ledger-starter', $quotas->planThatFits($company, 'units', 50)?->slug);
        $this->assertTrue($quotas->featureOn($company, 'rentals_portal'), 'the trial has the tenants\' and landlords\' sign-in');
        $this->assertFalse($quotas->featureOn($company, 'rentals_sms'));

        // A lapsed Estate Ledger account falls back to Estate Ledger Free (10 units); a shop to the family's Free plan.
        $this->assertSame('estate-ledger-free', app(Lifecycle::class)->freePlan($company)->slug);
        $this->assertSame('free', app(Lifecycle::class)->freePlan($shop)->slug);
    }

    public function test_the_three_roles_and_the_viewer(): void
    {
        $t = $this->registerTenant(['modules' => ['rentals']]);
        $company = $this->company($t);
        $cid = (int) $company->id;
        $owner = User::withoutGlobalScopes()->findOrFail($t['user_id']);

        $this->assertSame(self::TEN, config('permissions.module_permissions.rentals'));
        $roles = [
            'rentals_manager' => ['Property manager', self::TEN],
            'rentals_accountant' => ['Accountant', ['rentals_view', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords', 'rentals_expenses', 'rentals_reports']],
            'rentals_caretaker' => ['Caretaker', ['rentals_view', 'rentals_collect', 'rentals_maintenance']],
        ];
        foreach ($roles as $role => [$label, $perms]) {
            $this->assertSame(['label' => $label, 'module' => 'rentals', 'permissions' => $perms], config("permissions.roles.{$role}"));
            $this->assertSame($perms, Permissions::forRole($cid, $role), $role);
            $this->assertSame('shop_viewer', config("permissions.admin_roles.{$role}"), 'no classic screens: read-only there');
        }
        $this->assertSame(['owner', 'viewer', 'rentals_manager', 'rentals_accountant', 'rentals_caretaker'], Permissions::rolesFor($cid));

        // The owner holds the ten and the account's own permissions; nothing of a shop or a SACCO is offered.
        $available = Permissions::availableFor($cid);
        $this->assertSame([], array_values(array_diff(self::TEN, $available)));
        $this->assertNotContains('sell', $available);
        $this->assertNotContains('sacco_view', $available);

        // An Estate Ledger account's viewer reads the rental books and their reports.
        $viewer = app(TeamService::class)->createMember($company, $owner, 'Vera Viewer', 'vera_'.uniqid().'@example.com', null, 'Secret-pass-123', 'viewer');
        $this->assertSame(['view_reports', 'rentals_view', 'rentals_reports'], Permissions::of($viewer));

        // A shop is offered none of it.
        $shop = (int) $this->registerTenant(['business_type' => 'retail'])['company_id'];
        $this->assertNotContains('rentals_manager', Permissions::rolesFor($shop));
        $this->assertNotContains('rentals_view', Permissions::availableFor($shop));
        $this->assertSame(['view_reports'], Permissions::forRole($shop, 'viewer'));
    }

    public function test_the_menu_shows_the_rental_books_and_owner_mode_hides_the_agency_pages(): void
    {
        $t = $this->registerTenant(['modules' => ['rentals']]);
        $company = $this->company($t);
        $owner = User::withoutGlobalScopes()->findOrFail($t['user_id']);

        $section = collect(ShopMenu::sections())->firstWhere('key', 'rentals');
        $this->assertSame(['Rentals', 'rentals'], [$section['label'], $section['module']]);
        $this->assertSame(['rentals.overview', 'rentals.collect', 'rentals.properties', 'rentals.units', 'rentals.tenants', 'rentals.leases', 'rentals.arrears',
            'rentals.billing', 'rentals.payments', 'rentals.landlords', 'rentals.payouts', 'rentals.expenses', 'rentals.cash', 'rentals.maintenance', 'rentals.meters',
            'rentals.reports', 'rentals.settings', 'rentals.activity', 'rentals.import'], array_column($section['entries'], 'route'));

        $pages = fn () => array_column(ShopMenu::pagesFor($owner, $company), 'key');
        $agency = $pages();
        foreach ($section['entries'] as $entry) {
            $this->assertContains($entry['route'], $agency, 'the owner sees every page of an agency');
        }
        $this->assertNotContains('pos', $agency, 'no shop pages');
        $this->assertNotContains('sacco.overview', $agency);
        $this->assertFalse(ShopMenu::rentalsOwnerMode($company), 'not set up yet: agency');
        $this->assertFalse(CompanyRules::currencyLocked($company));

        // Owner mode (KR-06): no landlords to manage or pay; the rest stays. Setting the books up fixes the currency.
        DB::table('rental_settings')->insert(['company_id' => $company->id, 'mode' => 'owner', 'currency' => 'UGX', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(ShopMenu::rentalsOwnerMode((int) $company->id));
        $this->assertTrue(CompanyRules::currencyLocked($company));
        $own = $pages();
        $this->assertSame(['rentals.landlords', 'rentals.payouts'], array_values(array_diff($agency, $own)));

        // A caller that does not ask about owner mode (no callable) still shows the page.
        $landlords = collect($section['entries'])->firstWhere('route', 'rentals.landlords');
        $yes = fn () => true;
        $this->assertTrue(ShopMenu::allowed($landlords, $yes, $yes, $yes));
        $this->assertFalse(ShopMenu::allowed($landlords, $yes, $yes, $yes, null, $yes));

        // A shop never sees the section.
        $shop = $this->registerTenant(['business_type' => 'retail']);
        $this->assertNotContains('rentals.overview', array_column(ShopMenu::pagesFor(User::withoutGlobalScopes()->findOrFail($shop['user_id']), $this->company($shop)), 'key'));
    }

    public function test_a_tenants_or_landlords_code_is_keyed_apart_and_never_sent_as_another_app(): void
    {
        $this->assertSame('rentals:jane@example.com', OtpService::scoped('rentals', ' Jane@Example.com '));
        $this->assertSame('rentals', OtpService::scopeOf('rentals:jane@example.com'));
        $this->assertSame('sacco', OtpService::scopeOf('sacco:jane@example.com'));
        $this->assertNull(OtpService::scopeOf('jane@example.com'));
        $this->assertNull(OtpService::scopeOf('sacco_guarantee:12'));

        $spy = new class() extends Messenger
        {
            /** @var list<array{text: string, meta: array<string, mixed>}> */
            public array $sent = [];

            public function send(string $to, string $text, array $channels, array $meta = [], bool $now = false): int
            {
                $this->sent[] = ['text' => $text, 'meta' => $meta];

                return 0;
            }
        };
        $this->app->instance(Messenger::class, $spy);
        $otp = app(OtpService::class);

        $email = 'tenant_'.uniqid().'@example.com';
        $otp->request(OtpService::scoped('rentals', $email), 'login', null, $email, 'Ntinda Property Managers');
        $otp->request(OtpService::scoped('rentals', 'b'.$email), 'login', null, 'b'.$email);
        $otp->request('sacco:'.$email, 'login', null, $email, 'Wakiso SACCO'); // unchanged for a SACCO member

        [$agency, $plain, $sacco] = $spy->sent;
        $this->assertStringStartsWith('Your Ntinda Property Managers code is ', $agency['text']);
        $this->assertSame(['subject' => 'Ntinda Property Managers: your code', 'estate-ledger' => true], $agency['meta']['options']['mail']);
        $this->assertStringStartsWith('Your Estate Ledger code is ', $plain['text'], 'never the family\'s name');
        $this->assertSame(['subject' => 'Estate Ledger: your code', 'estate-ledger' => true], $plain['meta']['options']['mail']);
        $this->assertSame(['subject' => 'Wakiso SACCO: your code', 'akiba' => true], $sacco['meta']['options']['mail']);
        $this->assertSame(1, DB::table('otp_codes')->where('identifier', OtpService::scoped('rentals', $email))->count(), 'keyed apart from staff codes');
    }
}
