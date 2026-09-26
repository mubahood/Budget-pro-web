<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\RedirectToNewUi;
use App\Models\Subscription;

/** Classic screens send shop users to the same screen in the new interface, and only them. */
class RedirectToNewUiTest extends AdminTestCase
{
    private const NEW = 'https://new.example.test';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.new_ui_url' => self::NEW, 'saas.redirect_to_new_ui' => true]);
        $this->t = $this->makeTenant('company');
        $this->t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'business_type' => 'retail'])->saveQuietly();
        Subscription::create(['company_id' => $this->t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
    }

    public function test_the_path_map(): void
    {
        $this->assertSame('/dashboard', RedirectToNewUi::target(''));
        $this->assertSame('/products', RedirectToNewUi::target('stock-items'));
        $this->assertSame('/products?peek=15', RedirectToNewUi::target('stock-items/15/edit'));
        $this->assertSame('/sales?peek=7', RedirectToNewUi::target('sale-records/7'));
        $this->assertSame('/sell', RedirectToNewUi::target('sale-records/create'));
        $this->assertSame('/customers?new=1', RedirectToNewUi::target('customers/create'));
        $this->assertSame('/stock/reorder', RedirectToNewUi::target('reorder-suggestions'));
        $this->assertSame('/plan', RedirectToNewUi::target('billing'));
        $this->assertNull(RedirectToNewUi::target('budget-programs'), 'no such screen in the new interface');
        $this->assertNull(RedirectToNewUi::target('poultry-batches'));
    }

    public function test_a_shop_user_is_sent_to_the_same_screen(): void
    {
        $this->asAdmin($this->t['user'])->get('/stock-items')->assertRedirect(self::NEW.'/products');
        $this->asAdmin($this->t['user'])->get('/')->assertRedirect(self::NEW.'/dashboard');
        // laravel-admin's in-page (pjax) navigation gets a page that moves the whole window.
        $this->asAdmin($this->t['user'])->get('/customers', ['X-PJAX' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('window.location.href = "'.self::NEW.'/customers"', false);
    }

    public function test_what_stays_in_the_classic_screens(): void
    {
        // Screens the new interface has no equivalent for.
        $r = $this->asAdmin($this->t['user'])->get('/budget-programs');
        $this->assertFalse($r->isRedirect() && str_starts_with((string) $r->headers->get('Location'), self::NEW));
        // Background requests.
        $r = $this->asAdmin($this->t['user'])->get('/ajax/stock-items?q=x', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW), 'ajax is never redirected');
        // Platform administrators.
        $admin = $this->makeTenant('admin')['user'];
        $r = $this->actingAs($admin, 'admin')->get('/stock-items');
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW), 'platform admins stay');
        // A company without the shop module (e.g. a poultry farm).
        $farm = $this->makeTenant('company');
        $farm['company']->forceFill(['enabled_modules' => ['poultry', 'finance'], 'onboarding_state' => ['step' => 'done']])->saveQuietly();
        $r = $this->asAdmin($farm['user'])->get('/');
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW), 'non-shop companies stay');
    }

    public function test_classic_1_keeps_the_session_in_the_classic_screens(): void
    {
        $r = $this->asAdmin($this->t['user'])->get('/?classic=1');
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW));
        $r = $this->asAdmin($this->t['user'])->get('/stock-items');
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW), 'still classic for this session');
    }

    public function test_the_login_page_moves_unless_asked_to_stay_and_the_switch_turns_it_all_off(): void
    {
        $this->get('/auth/login')->assertRedirect(self::NEW.'/login');
        $this->get('/auth/login?classic=1')->assertOk();

        $this->flushSession();
        config(['saas.redirect_to_new_ui' => false]);
        $this->get('/auth/login')->assertOk();
        $r = $this->asAdmin($this->t['user'])->get('/stock-items');
        $this->assertFalse(str_starts_with((string) $r->headers->get('Location'), self::NEW));
    }
}
