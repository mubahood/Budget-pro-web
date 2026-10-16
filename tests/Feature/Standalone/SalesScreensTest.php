<?php

namespace Tests\Feature\Standalone;

use App\Models\StandaloneLicence;
use App\Models\StandaloneProduct;
use App\Notifications\StandaloneDownloadReady;
use App\Support\BuyerLink;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Feature\Standalone\Concerns\SellsStandalone;

/**
 * Platform › Standalone products and Standalone licences (docs/STANDALONE_EDITION_PLAN.md §11):
 * platform admins only, 404 on a standalone copy; prices set with no deploy; offline issue emails the
 * buyer; the licence actions; the buyer link staff copy for WhatsApp.
 */
class SalesScreensTest extends AdminTestCase
{
    use RunsAsStandalone, SellsStandalone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSigningKey();
        Notification::fake();
    }

    public function test_only_platform_admins_reach_the_screens_and_a_standalone_copy_has_none(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $owner = $this->makeTenant('company')['user'];
        $licence = $this->licence($this->product(['name' => 'Supermarket']));

        $this->asAdmin($admin)->get('/standalone-products')->assertOk()->assertSee('Supermarket')->assertSee('Add a product');
        $this->asAdmin($admin)->get('/standalone-licences')->assertOk()->assertSee('Kato Hardware')->assertSee('Issue a licence')->assertSee('Recent orders');
        foreach (['get' => ['/standalone-products', '/standalone-licences', "/standalone-licences/{$licence->id}/licence.json"],
            'post' => ['/standalone-products', '/standalone-licences', "/standalone-licences/{$licence->id}/revoke"]] as $method => $urls) {
            foreach ($urls as $url) {
                $this->asAdmin($owner)->{$method}($url)->assertForbidden();
            }
        }
        $this->assertSame('active', $licence->fresh()->status);

        $this->asStandalone();
        $this->asAdmin($admin)->get('/standalone-products')->assertNotFound();
        $this->asAdmin($admin)->get('/standalone-licences')->assertNotFound();
        $this->asAdmin($admin)->post("/standalone-licences/{$licence->id}/copy-link")->assertNotFound();
    }

    public function test_a_platform_admin_sets_prices_and_puts_a_product_on_sale(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $this->asAdmin($admin)->post('/standalone-products', [
            'name' => 'Supermarket chain', 'price_ugx' => '3750000', 'price_usd' => '1000', 'renewal_price_ugx' => '800000',
            'updates_months' => 12, 'max_users' => '', 'max_locations' => '', 'max_devices' => '', 'white_label' => '1',
        ])->assertRedirect();
        $product = StandaloneProduct::query()->where('slug', 'supermarket-chain')->sole();
        $this->assertSame(['3750000.00', '1000.00', '800000.00', null, true, false], [$product->price_ugx, $product->price_usd, $product->renewal_price_ugx, $product->renewal_price_usd, $product->white_label, $product->is_active]);
        $this->assertEquals(['max_users' => null, 'max_locations' => null, 'max_devices' => null], $product->limits);

        $this->asAdmin($admin)->post("/standalone-products/{$product->id}/toggle")->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);
        $this->asAdmin($admin)->post("/standalone-products/{$product->id}", ['name' => 'Supermarket chain', 'slug' => 'supermarket-chain',
            'price_ugx' => '4000000', 'updates_months' => 6, 'max_locations' => '3', 'is_active' => '1'])->assertRedirect();
        $this->assertSame(['4000000.00', null, 6, 3], [$product->fresh()->price_ugx, $product->fresh()->price_usd, $product->fresh()->updates_months, $product->fresh()->limits['max_locations']]);
        $this->asAdmin($admin)->get("/standalone-products?edit={$product->id}")->assertOk()->assertSee('Edit Supermarket chain');
    }

    public function test_offline_issue_emails_the_buyer_and_the_licence_actions_work(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $product = $this->product(['updates_months' => 6]);

        $this->asAdmin($admin)->post('/standalone-licences', ['product_id' => $product->id, 'business' => 'Mukwano Stores', 'name' => 'Peter',
            'email' => 'peter@mukwano.test', 'phone' => '+256700111222', 'domain' => 'shop.mukwano.test', 'reference' => 'BANK-77'])->assertRedirect();
        $licence = StandaloneLicence::query()->where('email', 'peter@mukwano.test')->sole();
        $this->assertSame(['offline', 'BANK-77', ['shop.mukwano.test'], now()->addMonthsNoOverflow(6)->toDateString()], [$licence->source, $licence->reference, $licence->domains, $licence->updates_until->toDateString()]);
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n, $c, $to) => $n->reason === 'issued' && $to->routes['mail'] === 'peter@mukwano.test');

        $act = fn (string $action, array $data = []) => $this->asAdmin($admin)->post("/standalone-licences/{$licence->id}/{$action}", $data)->assertRedirect();
        $act('send-link');
        Notification::assertSentOnDemandTimes(StandaloneDownloadReady::class, 2);
        $act('renew');
        $this->assertSame(now()->addMonthsNoOverflow(6)->addMonthsNoOverflow(6)->toDateString(), $licence->fresh()->updates_until->toDateString());
        $act('domain', ['domain' => 'pos.mukwano.test']);
        $act('domain', ['domain' => 'tills.mukwano.test']);
        $this->assertSame('tills.mukwano.test', $licence->fresh()->domain(), 'staff move it at any time');
        $act('domain', ['domain' => 'https://bad/path']);
        $this->assertSame('tills.mukwano.test', $licence->fresh()->domain());
        $act('revoke');
        $this->assertSame('revoked', $licence->fresh()->status);
        $act('reinstate');
        $this->assertSame('active', $licence->fresh()->status);

        $document = $this->asAdmin($admin)->get("/standalone-licences/{$licence->id}/licence.json")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="licence.json"')->json();
        $this->assertTrue(app(Verifier::class)->verifiesDocument($document));
        $this->assertSame(['tills.mukwano.test'], $document['licence']['domains']);

        // Live has no SMTP yet: staff copy a fresh link and send it by WhatsApp.
        $this->asAdmin($admin)->post("/standalone-licences/{$licence->id}/copy-link")->assertRedirect()->assertSessionHas('buyer_link');
        $url = session('buyer_link')['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue(BuyerLink::valid($licence->id, $q['expires'], $q['signature']));
        $this->asAdmin($admin)->get('/standalone-licences?q='.$licence->id)->assertOk()->assertSee('Buyer link')->assertSee($url);
    }
}
