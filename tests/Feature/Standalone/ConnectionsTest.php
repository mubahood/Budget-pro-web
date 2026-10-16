<?php

namespace Tests\Feature\Standalone;

use App\Services\FlutterwaveService;
use App\Support\DataCrypt;
use App\Support\InstallSettings;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Api\ApiTestCase;
use Tests\Feature\Api\FakeFlutterwaveService;

/**
 * Settings › Connections, the kernel half (docs/STANDALONE_EDITION_PLAN.md §13): the buyer's keys are
 * stored encrypted, fill the runtime config on standalone only, and mobile money then runs on the
 * buyer's own Flutterwave account with no subaccount and no platform fee. Flutterwave is faked.
 */
class ConnectionsTest extends ApiTestCase
{
    use RunsAsStandalone;

    public function test_values_are_stored_encrypted_and_an_empty_value_removes_the_key(): void
    {
        InstallSettings::set('sms.api_key', '  atsk_secret_123  ', 7);

        $raw = DB::table('install_settings')->where('key', 'sms.api_key')->first();
        $this->assertNotSame('atsk_secret_123', $raw->value);
        $this->assertStringNotContainsString('atsk_secret_123', (string) $raw->value);
        $this->assertSame('atsk_secret_123', DataCrypt::decrypt($raw->value), 'the shared data key, so the web app reads it too');
        $this->assertSame(7, (int) $raw->updated_by);
        $this->assertSame('atsk_secret_123', InstallSettings::get('sms.api_key'));

        InstallSettings::set('sms.api_key', 'atsk_new');
        $this->assertSame(1, DB::table('install_settings')->where('key', 'sms.api_key')->count());
        $this->assertSame('atsk_new', InstallSettings::get('sms.api_key'));

        InstallSettings::set('sms.api_key', '');
        $this->assertFalse(InstallSettings::has('sms.api_key'));

        DB::table('install_settings')->insert(['key' => 'sms.username', 'value' => 'not-encrypted', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(InstallSettings::get('sms.username'), 'a value that no longer decrypts reads as not set');

        $this->expectException(\InvalidArgumentException::class);
        InstallSettings::set('app.key', 'nope');
    }

    public function test_the_overlay_fills_mail_messaging_and_flutterwave_on_standalone_only(): void
    {
        $this->storeAll();
        $before = [config('mail.default'), config('messaging.sms.driver'), config('flutterwave.secret_key')];

        InstallSettings::overlay(); // SaaS: our keys stay
        $this->assertSame($before, [config('mail.default'), config('messaging.sms.driver'), config('flutterwave.secret_key')]);
        $this->assertSame([], InstallSettings::readyChannels());

        $this->asStandalone();
        config(['flutterwave.momo.platform_fee_percent' => 2.5]);
        InstallSettings::overlay();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.freshmart.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mailpass', config('mail.mailers.smtp.password'));
        $this->assertSame('shop@freshmart.test', config('mail.from.address'));
        $this->assertSame('Fresh Mart', config('mail.from.name'));
        $this->assertSame('mail', config('messaging.mail.driver'));
        $this->assertSame('africastalking', config('messaging.sms.driver'));
        $this->assertSame('freshmart', config('messaging.sms.africastalking.username'));
        $this->assertSame('FRESHMART', config('messaging.sms.africastalking.sender_id'));
        $this->assertSame('meta', config('messaging.whatsapp.driver'));
        $this->assertSame('1234567890', config('messaging.whatsapp.meta.phone_number_id'));
        $this->assertSame('fm_receipt', config('messaging.whatsapp.meta.receipt_template'));
        $this->assertSame('FLWSECK-buyer', config('flutterwave.secret_key'));
        $this->assertSame('FLWPUBK-buyer', config('flutterwave.public_key'));
        $this->assertSame('buyer-hash', config('flutterwave.secret_hash'));
        $this->assertSame(0.0, config('flutterwave.momo.platform_fee_percent'), 'no platform fee on a buyer\'s copy');
        $this->assertSame(['mail', 'sms', 'whatsapp'], InstallSettings::readyChannels());
    }

    public function test_mobile_money_on_standalone_uses_the_buyers_keys_with_no_subaccount_and_no_fee(): void
    {
        $flw = new FakeFlutterwaveService;
        $this->app->instance(FlutterwaveService::class, $flw);
        $t = $this->registerTenant(['currency' => 'UGX']); // as SaaS: a standalone copy refuses another company
        $h = $this->auth($t['token']);
        $sale = $this->unpaidSale($h);
        $this->asStandalone();
        config(['flutterwave.secret_key' => null]);

        $this->postJson("/api/v1/sales/{$sale}/momo-request", ['phone' => '0772800900', 'network' => 'MTN'], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'momo_not_set_up');
        $this->putJson('/api/v1/company/momo', ['phone' => '0772 111 000', 'network' => 'MTN'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'momo_direct');
        $this->assertSame([], $flw->subaccounts, 'no subaccount on a buyer\'s copy');

        $this->storeAll();
        InstallSettings::overlay();
        $this->getJson('/api/v1/company/engagement', $h)->assertOk()->assertJsonPath('data.momo.ready', true);
        $this->postJson("/api/v1/sales/{$sale}/momo-request", ['phone' => '0772800900', 'network' => 'MTN'], $h)->assertStatus(201);

        $payload = $flw->charges[0]['payload'];
        $this->assertArrayNotHasKey('subaccounts', $payload, 'the money lands straight in the buyer\'s Flutterwave balance');
        $this->assertSame(5000, (int) $payload['amount']);
        $this->assertSame(0.0, config('flutterwave.momo.platform_fee_percent'));

        // The real client authenticates with the buyer's secret key (built, never sent).
        config(['flutterwave.allow_network_in_tests' => true]);
        $client = (new class extends FlutterwaveService
        {
            public function authorization(): string
            {
                return (string) $this->client()->getConfig('headers')['Authorization'];
            }
        })->authorization();
        $this->assertSame('Bearer FLWSECK-buyer', $client);
    }

    private function unpaidSale(array $h): int
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', ['name' => 'Sugar', 'stock_sub_category_id' => $sub, 'selling_price' => 5000, 'buying_price' => 4400, 'original_quantity' => 50], $h)->json('data.id');
        $cust = $this->postJson('/api/v1/customers', ['name' => 'Grace', 'phone' => '0772 800 900', 'credit_limit' => 100000], $h)->json('data.id');

        return (int) $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [], 'items' => [['stock_item_id' => $item, 'quantity' => 1]]], $h)->json('data.id');
    }

    private function storeAll(): void
    {
        foreach ([
            'mail.host' => 'mail.freshmart.test', 'mail.port' => '465', 'mail.encryption' => 'ssl', 'mail.username' => 'shop@freshmart.test',
            'mail.password' => 'mailpass', 'mail.from_address' => 'shop@freshmart.test', 'mail.from_name' => 'Fresh Mart',
            'sms.username' => 'freshmart', 'sms.api_key' => 'atsk_x', 'sms.sender_id' => 'FRESHMART',
            'whatsapp.token' => 'EAAG-token', 'whatsapp.phone_number_id' => '1234567890', 'whatsapp.receipt_template' => 'fm_receipt',
            'flutterwave.public_key' => 'FLWPUBK-buyer', 'flutterwave.secret_key' => 'FLWSECK-buyer', 'flutterwave.encryption_key' => 'enc', 'flutterwave.secret_hash' => 'buyer-hash',
        ] as $key => $value) {
            InstallSettings::set($key, $value);
        }
    }
}
