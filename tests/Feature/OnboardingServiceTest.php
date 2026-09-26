<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\RegistrationService;
use App\Services\Shop\SaleService;
use Database\Seeders\ProductTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** POWER_PLAN §3.1: onboarding state, checklist v2 on real signals, priced template packs, events. */
class OnboardingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private OnboardingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OnboardingService();
        (new ProductTemplateSeeder())->run();
    }

    /** @return array{user: User, company: Company} a shop signed up on the web (no phone-app token) */
    private function shop(string $currency = 'UGX', string $type = 'retail', string $country = 'UG'): array
    {
        $r = app(RegistrationService::class)->register(['first_name' => 'Rose', 'last_name' => 'Owner', 'email' => 'o'.uniqid('', true).'@example.test',
            'password' => 'secret123', 'company_name' => 'Mama Rose Shop', 'currency' => $currency, 'country' => $country, 'business_type' => $type], RegistrationService::PRODUCT_BUDGET, 'shop-web');

        return ['user' => $r['user']->fresh(), 'company' => $r['company']->fresh()];
    }

    public function test_state_writes_keep_unknown_keys_and_add_the_new_ones(): void
    {
        ['company' => $c] = $this->shop();
        $c->onboarding_state = ['step' => 'products', 'legacy' => true, 'from_phone' => 'x'];
        $c->saveQuietly();

        $this->svc->markStep($c, 'products', false, 12);
        $this->svc->dismissChecklist($c);
        $this->svc->remember($c, ['tour_seen' => true]);

        $raw = json_decode((string) DB::table('companies')->where('id', $c->id)->value('onboarding_state'), true);
        $this->assertTrue($raw['legacy'], 'legacy survives a step');
        $this->assertSame('x', $raw['from_phone'], 'keys written by the phone survive');
        $this->assertTrue($raw['dismissed_checklist']);
        $this->assertSame(12, $raw['step_seconds']['products']);
        $state = $this->svc->state($c->fresh());
        $this->assertSame('money', $state['step']);
        $this->assertTrue($state['tour_seen']);
        $this->assertSame(OnboardingService::STATE_VERSION, $state['version']);
        $this->assertArrayHasKey('app_prompted_at', $state);
        $this->assertArrayHasKey('channel', $state);
    }

    public function test_packs_are_priced_in_kes_tzs_and_rwf_and_new_packs_exist(): void
    {
        ['company' => $ke] = $this->shop('KES', 'retail', 'KE');
        $sugar = collect($this->svc->templates($ke)['items'])->firstWhere('name', 'Sugar 1kg');
        $this->assertSame(180, $sugar['selling_price']);
        $this->assertLessThan($sugar['selling_price'], $sugar['buying_price']);
        $this->assertSame([], collect($this->svc->templates($ke)['items'])->filter(fn ($i) => ! $i['selling_price'])->pluck('name')->all(), 'every KES item has a price');

        ['company' => $tz] = $this->shop('TZS', 'poultry', 'TZ');
        $pack = $this->svc->templates($tz);
        $this->assertGreaterThanOrEqual(15, count($pack['items']));
        $this->assertNotNull(collect($pack['items'])->firstWhere('name', 'Layers mash 70kg')['selling_price']);

        foreach (['other', 'salon'] as $type) {
            $this->assertNotEmpty($this->svc->templates($tz, $type)['items'], "{$type} pack");
        }
        $this->assertSame(1950, ProductTemplateSeeder::prices(['x', 'c', 's', 'pcs', 5000, 4400], ['RWF' => 0.39])['RWF']['sell']);
        $this->assertSame(40, ProductTemplateSeeder::nice(38.2));
    }

    public function test_apply_templates_refuses_unpriced_rows_and_marks_only_when_something_was_created(): void
    {
        ['user' => $u, 'company' => $c] = $this->shop('USD'); // no USD prices in the packs
        $items = collect($this->svc->templates($c, 'retail')['items'])->take(3)->values();
        try {
            $this->svc->applyTemplates($c, $u, $items->map(fn ($i) => ['key' => $i['key']])->all());
            $this->fail('unpriced rows must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('prices_required', $e->errorCode());
            $this->assertStringContainsString($items[0]['name'], $e->getMessage());
        }
        $this->assertSame(0, StockItem::withoutGlobalScopes()->where('company_id', $c->id)->count());
        $this->assertNotContains('products', $this->svc->state($c->fresh())['completed_steps']);

        $picks = $items->map(fn ($i) => ['key' => $i['key'], 'selling_price' => '2,500', 'opening_stock' => 4])->all();
        $this->assertSame(3, $this->svc->applyTemplates($c->fresh(), $u, $picks)['created']);
        $this->assertContains('products', $this->svc->state($c->fresh())['completed_steps']);
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'template_applied'));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'first_product'));

        // Everything already there: nothing created, no second "template_applied".
        $this->assertSame(0, $this->svc->applyTemplates($c->fresh(), $u, $picks)['created']);
        $this->assertSame(1, DB::table('onboarding_events')->where('company_id', $c->id)->where('event', 'template_applied')->count());
    }

    public function test_save_money_leaves_receipt_channels_unticked(): void
    {
        ['company' => $c] = $this->shop();
        $this->svc->saveMoney($c, ['payment_methods' => ['cash', 'mobile_money']]);
        $this->assertSame([], $c->fresh()->receipt_channels);
        $this->assertFalse(collect($this->svc->checklist($c->fresh())['items'])->firstWhere('key', 'whatsapp_receipts')['done']);
        $this->assertFalse(collect($this->svc->checklist($c->fresh())['items'])->firstWhere('key', 'set_up_momo')['done']);
    }

    public function test_checklist_v2_follows_real_signals(): void
    {
        ['user' => $u, 'company' => $c] = $this->shop();
        $v2 = fn () => collect($this->svc->checklistV2($c->fresh())['items'])->pluck('done', 'key')->all();
        $this->assertSame(['products' => false, 'prices' => false, 'first_sale' => false, 'staff' => false, 'momo' => false, 'receipts' => false, 'phone_app' => false, 'plan' => false], $v2());

        $this->svc->createProducts($c, $u, [
            ['name' => 'Soap', 'category' => 'Home', 'sub_category' => 'Home', 'unit' => 'pcs', 'selling_price' => 0, 'buying_price' => 0, 'opening_stock' => 5, 'barcode' => null, 'sku' => null],
            ['name' => 'Salt', 'category' => 'Food', 'sub_category' => 'Food', 'unit' => 'pcs', 'selling_price' => 1000, 'buying_price' => 800, 'opening_stock' => 5, 'barcode' => null, 'sku' => null],
        ]);
        $this->assertTrue($v2()['products']);
        $this->assertFalse($v2()['prices'], 'a product without a price');
        StockItem::withoutGlobalScopes()->where('company_id', $c->id)->where('name', 'Soap')->first()->forceFill(['selling_price' => 2000])->save();
        $this->assertTrue($v2()['prices']);

        DB::table('invites')->insert(['company_id' => $c->id, 'token_hash' => hash('sha256', 'x'.uniqid()), 'role' => 'cashier', 'invited_by_id' => $u->id, 'status' => 'pending',
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($v2()['staff'], 'a pending invite is not a team member');
        DB::table('invites')->where('company_id', $c->id)->update(['status' => 'accepted']);
        $this->assertTrue($v2()['staff']);

        $c->forceFill(['momo_subaccount_id' => 'RS_TEST'])->saveQuietly();
        DB::table('message_log')->insert(['company_id' => $c->id, 'channel' => 'whatsapp', 'to' => '+256700000001', 'purpose' => 'receipt', 'body' => 'x', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($v2()['receipts'], 'a failed send is not a receipt sent');
        DB::table('message_log')->where('company_id', $c->id)->update(['status' => 'sent']);
        $u->createToken('phone');
        DB::table('subscriptions')->where('company_id', $c->id)->update(['status' => 'active', 'provider' => 'flutterwave']);

        $all = $v2();
        unset($all['first_sale']);
        $this->assertSame([true], array_values(array_unique($all)));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'invite_accepted'));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'momo_ready'));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'app_login'));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'invite_sent'));
        $this->svc->checklistV2($c->fresh());
        $this->assertSame(1, DB::table('onboarding_events')->where('company_id', $c->id)->where('event', 'momo_ready')->count(), 'milestones are written once');
    }

    public function test_first_sale_is_noted_with_time_to_first_sale_and_closes_the_wizard(): void
    {
        ['user' => $u, 'company' => $c] = $this->shop();
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'signed_up'));
        $this->assertSame('web', DB::table('onboarding_events')->where('company_id', $c->id)->where('event', 'signed_up')->value('channel'));
        $this->svc->createProducts($c, $u, [['name' => 'Salt', 'category' => 'Food', 'sub_category' => 'Food', 'unit' => 'pcs', 'selling_price' => 1000, 'buying_price' => 800, 'opening_stock' => 5, 'barcode' => null, 'sku' => null]]);
        $this->svc->markStep($c->fresh(), 'business');
        $this->assertTrue($this->svc->needsSetup($c->fresh()));

        $p = StockItem::withoutGlobalScopes()->where('company_id', $c->id)->first();
        (new SaleService())->checkout((int) $c->id, (int) $u->id, ['items' => [['stock_item_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'payments_explicit' => true]);

        // At an earlier step the first sale is ticked but the wizard stays where it was.
        $this->svc->checklistV2($c->fresh());
        $state = $this->svc->state($c->fresh());
        $this->assertContains('first_sale', $state['completed_steps']);
        $this->assertSame('products', $state['step']);
        $meta = json_decode((string) DB::table('onboarding_events')->where('company_id', $c->id)->where('event', 'first_sale')->value('meta'), true);
        $this->assertIsInt($meta['seconds_to_first_sale']);
        $this->assertSame($meta['seconds_to_first_sale'], OnboardingEvents::timeToFirstSale((int) $c->id));

        // At the first-sale step, noting it finishes setup.
        ['user' => $u2, 'company' => $c2] = $this->shop();
        foreach (['business', 'products', 'money', 'team'] as $s) {
            $this->svc->markStep($c2->fresh(), $s);
        }
        $this->svc->createProducts($c2->fresh(), $u2, [['name' => 'Salt', 'category' => 'Food', 'sub_category' => 'Food', 'unit' => 'pcs', 'selling_price' => 1000, 'buying_price' => 800, 'opening_stock' => 5, 'barcode' => null, 'sku' => null]]);
        $p2 = StockItem::withoutGlobalScopes()->where('company_id', $c2->id)->first();
        (new SaleService())->checkout((int) $c2->id, (int) $u2->id, ['items' => [['stock_item_id' => $p2->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'payments_explicit' => true]);
        $this->assertTrue($this->svc->noteFirstSale($c2->fresh()));
        $this->assertFalse($this->svc->noteFirstSale($c2->fresh()), 'once');
        $this->assertSame('done', $this->svc->state($c2->fresh())['step']);
        $this->assertFalse($this->svc->needsSetup($c2->fresh()));
    }

    public function test_skipping_the_wizard_stops_needs_setup(): void
    {
        ['company' => $c] = $this->shop();
        $this->assertTrue($this->svc->needsSetup($c));
        $this->svc->skipWizard($c);
        $this->assertFalse($this->svc->needsSetup($c->fresh()));
        $this->assertTrue(OnboardingEvents::has((int) $c->id, 'wizard_skipped'));
        $this->assertSame('business', $this->svc->state($c->fresh())['step'], 'the phone can still resume the wizard');
    }
}
