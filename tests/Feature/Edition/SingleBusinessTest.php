<?php

namespace Tests\Feature\Edition;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Billing\Entitlements;
use App\Services\Onboarding\DemoShopService;
use App\Services\Onboarding\RegistrationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAsStandalone;
use Tests\TestCase;

/**
 * Exactly one business per standalone copy (docs/STANDALONE_EDITION_PLAN.md §6, R3): the installer's
 * registration creates it on the lifetime Standalone plan, and every other path to a company is refused.
 * The raw-SQL layer (triggers): SingleBusinessTriggersTest.
 */
class SingleBusinessTest extends TestCase
{
    use DatabaseTransactions, RunsAsStandalone;

    public function test_the_installer_creates_the_only_business_on_the_lifetime_standalone_plan(): void
    {
        $this->withNoCompanies();
        $this->asStandalone();
        $this->seed(DatabaseSeeder::class); // standalone: only StandaloneReferenceSeeder

        $this->assertFalse(DB::table('plans')->whereIn('slug', ['starter', 'business', 'enterprise'])->exists(), 'no SaaS plans');
        $this->assertFalse(DB::table('admin_menu')->whereIn('uri', ['billing', 'plans', 'subscriptions', 'companies'])->exists(), 'SaaS-only classic menus are left out');
        $this->assertTrue(DB::table('admin_roles')->where('slug', 'company')->exists());

        $r = app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, RegistrationService::CHANNEL_STANDALONE);
        $company = $r['company']->fresh();
        $subscription = $company->subscription;

        $this->assertSame(1, (int) $company->standalone_slot);
        $this->assertSame('standalone', $subscription->plan->slug);
        $this->assertSame('lifetime', $subscription->plan->interval);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertTrue($subscription->ends_at->isAfter(now()->addYears(50)));
        $this->assertSame('active', $company->accessState());
        $this->assertEmpty((array) ($company->onboarding_state ?? []), 'the owner meets the /welcome wizard');
        $limits = Entitlements::for($company)['limits'];
        $this->assertNull($limits['max_locations']);
        $this->assertNull($limits['storage_mb']);
        $this->assertTrue((bool) Entitlements::for($company)['features']['multi_location']);

        // Installing twice is refused: the copy already has its business.
        $this->assertRefused('one_business', fn () => app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, RegistrationService::CHANNEL_STANDALONE));
    }

    public function test_every_other_path_to_a_second_company_is_refused(): void
    {
        $owner = app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, 'test')['user']->fresh();
        $this->asStandalone();

        // The model (any code path that saves a new Company).
        try {
            $c = new Company();
            $c->owner_id = $owner->id;
            $c->name = 'Second business';
            $c->save();
            $this->fail('A second company was saved on a standalone copy.');
        } catch (\DomainException $e) {
            $this->assertSame(Company::ONE_BUSINESS, $e->getMessage());
        }

        // Registration from any channel but the installer's, and the installer's once a company exists.
        foreach (['api', 'shop-web', 'web', 'legacy-mobile'] as $channel) {
            $this->assertRefused('registration_closed', fn () => app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, $channel));
        }
        $this->assertRefused('registration_closed', fn () => app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_PINGPIN, RegistrationService::CHANNEL_STANDALONE));
        $this->assertRefused('one_business', fn () => app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, RegistrationService::CHANNEL_STANDALONE));

        // The phone app's and the classic sign-up (404 before any of this runs).
        $this->postJson('/api/v1/auth/register', $this->owner())->assertNotFound();
        $this->post('/auth/register', $this->owner())->assertNotFound();

        // Private demo shops.
        $demos = app(DemoShopService::class);
        $this->assertFalse($demos->allowed($owner));
        $this->assertRefused('one_business', fn () => $demos->create($owner));

        $this->assertSame(0, Company::withoutGlobalScopes()->where('name', 'Second business')->count());
    }

    public function test_the_saas_never_uses_the_installer_channel(): void
    {
        $this->assertRefused('wrong_edition', fn () => app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, RegistrationService::CHANNEL_STANDALONE));
        $company = app(RegistrationService::class)->register($this->owner(), RegistrationService::PRODUCT_BUDGET, 'test')['company']->fresh();
        $this->assertNull($company->standalone_slot);
        $this->assertSame('trialing', $company->subscription->status);
    }

    private function owner(): array
    {
        return ['first_name' => 'Only', 'last_name' => 'Owner', 'email' => 'only_'.uniqid('', true).'@example.test', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'company_name' => 'The One Shop', 'currency' => 'UGX'];
    }

    private function assertRefused(string $code, \Closure $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected the refusal {$code}.");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }
}
