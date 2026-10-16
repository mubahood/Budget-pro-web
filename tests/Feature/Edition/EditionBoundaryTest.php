<?php

namespace Tests\Feature\Edition;

use App\Models\User;
use App\Services\FlutterwaveService;
use App\Services\Onboarding\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Feature\Api\FakeFlutterwaveService;

/**
 * The edition boundary (docs/STANDALONE_EDITION_PLAN.md §5): every SaaS-only surface is a 404 on a
 * standalone copy and answers as before on the SaaS; what a standalone copy keeps keeps working.
 * One business per copy: SingleBusinessTest. Switches and the scheduler: StandaloneSwitchesTest.
 */
class EditionBoundaryTest extends AdminTestCase
{
    use RunsAsStandalone;

    /** Guest requests: [method, uri]. On the SaaS none of them is a 404 (payment/callback: its own page). */
    private const GUEST = [
        ['GET', '/auth/register'], ['POST', '/auth/register'], ['GET', '/billing'], ['GET', '/subscription-expired'], ['GET', '/companies'],
        ['GET', '/api/v1/plans'], ['POST', '/api/v1/auth/register'], ['POST', '/api/auth/register'], ['GET', '/api/v1/subscription'],
        ['POST', '/api/v1/pingpin/auth/register'], ['POST', '/api/v1/pingpin/auth/login'], ['GET', '/api/v1/pingpin/plans'],
        ['POST', '/api/v1/pingpin/webhooks/flutterwave'], ['GET', '/api/v1/pingpin/organisations'],
    ];

    /** Signed in as the shop's owner (API) and as a platform admin (classic). */
    private const OWNER_API = ['/api/v1/subscription', '/api/v1/subscription/quote', '/api/v1/pingpin/organisations'];

    private const PLATFORM = ['/companies', '/plans', '/subscriptions', '/pingpin-plans', '/product-templates', '/gens', '/billing'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.legacy_enabled' => false]); // the legacy handlers may exit(); retired, they answer 426 on the SaaS
        $this->app->instance(FlutterwaveService::class, new FakeFlutterwaveService()); // never the network
    }

    public function test_saas_only_surfaces_are_404_on_standalone_and_answer_on_the_saas(): void
    {
        [$owner, , $token] = $this->shop();
        $platform = $this->platformAdmin();

        foreach (self::GUEST as [$method, $uri]) {
            $this->assertNotSame(404, $this->call($method, $uri)->status(), "SaaS {$method} {$uri}");
        }
        $this->get('/payment/callback?tx_ref=nope')->assertSee('could not find this payment');
        foreach (self::OWNER_API as $uri) {
            $this->assertNotSame(404, $this->api('GET', $uri, $token)->status(), "SaaS owner {$uri}");
        }
        foreach (self::PLATFORM as $uri) {
            $this->assertSame(200, $this->actingAs($platform, 'admin')->get($uri)->status(), "SaaS platform {$uri}");
        }

        $this->asStandalone();
        $this->app['auth']->forgetGuards();
        foreach (self::GUEST as [$method, $uri]) {
            $this->call($method, $uri)->assertNotFound();
        }
        $this->get('/payment/callback?tx_ref=nope')->assertNotFound()->assertDontSee('could not find this payment');
        foreach (self::OWNER_API as $uri) {
            $this->api('GET', $uri, $token)->assertNotFound();
        }
        foreach (array_merge(self::PLATFORM, ['/subscription-expired']) as $uri) {
            $this->actingAs($platform, 'admin')->get($uri)->assertNotFound();
        }

        // What both editions keep: the shop's own API and screens.
        $this->api('GET', '/api/v1/auth/me', $token)->assertOk()->assertJsonPath('data.edition', 'standalone');
        $this->actingAs($owner, 'admin')->get('/stock-items')->assertOk();
    }

    public function test_the_webhook_settles_mobile_money_on_both_editions_and_subscriptions_only_on_the_saas(): void
    {
        $hash = ['verif-hash' => (string) config('flutterwave.secret_hash')];
        $momo = ['data' => ['tx_ref' => 'MOMO-'.uniqid(), 'id' => 1, 'status' => 'successful']];
        $plan = ['data' => ['tx_ref' => 'BP-'.uniqid(), 'id' => 2, 'status' => 'successful']];

        $this->postJson('/api/v1/webhooks/flutterwave', $momo, $hash)->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/v1/webhooks/flutterwave', $plan, $hash)->assertOk()->assertJsonPath('status', 'ok'); // SaaS: looked up (not found)

        $this->asStandalone();
        $this->postJson('/api/v1/webhooks/flutterwave', $momo, $hash)->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/v1/webhooks/flutterwave', $plan, $hash)->assertOk()->assertJsonPath('status', 'ignored');
        $this->postJson('/api/v1/webhooks/flutterwave', $momo, ['verif-hash' => 'WRONG'])->assertStatus(401);
    }

    public function test_app_version_and_profile_say_which_edition_this_is(): void
    {
        [, , $token] = $this->shop();
        $this->getJson('/api/v1/app/version')->assertOk()->assertJsonPath('data.edition', 'saas')->assertJsonPath('data.registration_open', true)
            ->assertJsonPath('data.name', config('app.name'))->assertJsonPath('data.version', \App\Support\Edition::version());
        $this->api('GET', '/api/v1/auth/me', $token)->assertOk()->assertJsonPath('data.edition', 'saas');

        $this->asStandalone();
        $this->getJson('/api/v1/app/version')->assertOk()->assertJsonPath('data.edition', 'standalone')->assertJsonPath('data.registration_open', false)
            ->assertJsonStructure(['data' => ['min_version', 'latest_version', 'store_url', 'edition', 'registration_open', 'name', 'version']]);
        $this->api('GET', '/api/v1/auth/me', $token)->assertOk()->assertJsonPath('data.entitlements.state', 'active');
    }

    public function test_a_copy_that_is_not_installed_or_installed_as_the_other_edition_does_not_open(): void
    {
        $this->useInstallMarker(null); // SaaS with no marker: normal
        $this->get('/auth/login')->assertOk();
        $this->getJson('/api/v1/app/version')->assertOk();

        $this->asStandalone(installed: false);
        $this->get('/auth/login')->assertRedirect('http://localhost/install.php');
        $this->getJson('/api/v1/app/version')->assertStatus(503)->assertJsonPath('error', 'not_installed');

        $this->useInstallMarker(['edition' => 'saas']); // a SaaS database opened as standalone
        $this->get('/auth/login')->assertStatus(503);
        $this->getJson('/api/v1/app/version')->assertStatus(503)->assertJsonPath('error', 'edition_mismatch');

        $this->asSaas()->useInstallMarker(['edition' => 'standalone']); // a standalone database opened as SaaS
        $this->get('/companies')->assertStatus(503);
        $this->getJson('/api/v1/plans')->assertStatus(503)->assertJsonPath('error', 'edition_mismatch');
    }

    /** @return array{0: User, 1: \App\Models\Company, 2: string} an owner with the classic owner role and an API token */
    private function shop(): array
    {
        $r = app(RegistrationService::class)->register(['first_name' => 'Edition', 'last_name' => 'Owner', 'email' => 'edition_'.uniqid('', true).'@example.test',
            'password' => 'secret123', 'company_name' => 'Edition Shop', 'currency' => 'UGX'], RegistrationService::PRODUCT_BUDGET, 'test');
        $user = $r['user']->fresh();
        DB::table('admin_role_users')->where('user_id', $user->id)->delete();
        DB::table('admin_role_users')->insert(['role_id' => DB::table('admin_roles')->where('slug', 'company')->value('id'), 'user_id' => $user->id]);

        return [$user, $r['company']->fresh(), $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    private function platformAdmin(): User
    {
        return $this->makeTenant('admin')['user'];
    }

    private function api(string $method, string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, [], ['Authorization' => 'Bearer '.$token]);
    }
}
