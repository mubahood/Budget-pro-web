<?php

namespace Tests\Feature\Api;

use App\Models\StockItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Plan C2/C3/C4 (P3-2, P3-8): wizard state, template packs, CSV import, checklist, modules. */
class OnboardingTest extends ApiTestCase
{
    public function test_wizard_business_templates_money_and_checklist(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $h = $this->auth($t['token']);

        $s = $this->getJson('/api/v1/onboarding', $h)->assertOk();
        $s->assertJsonPath('data.state.step', 'business')->assertJsonPath('data.checklist.percent', 0);
        $this->assertArrayHasKey('KE', $s->json('data.presets.countries'));

        $this->putJson('/api/v1/onboarding/business', ['name' => 'Mama Rose Shop', 'business_type' => 'retail', 'country' => 'KE', 'seconds' => 40], $h)->assertOk()
            ->assertJsonPath('data.company.currency', 'KES')->assertJsonPath('data.company.timezone', 'Africa/Nairobi')
            ->assertJsonPath('data.company.modules', ['shop', 'finance'])->assertJsonPath('data.state.step', 'products');
        // Back to Uganda so the pack has prices in the shop's currency.
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail', 'country' => 'UG'], $h)->assertOk()->assertJsonPath('data.company.currency', 'UGX');

        $pack = $this->getJson('/api/v1/onboarding/templates', $h)->assertOk()->json('data');
        $this->assertCount(40, $pack['items']);
        $sugar = collect($pack['items'])->firstWhere('name', 'Sugar 1kg');
        $this->assertSame(5000, $sugar['selling_price']);
        $soda = collect($pack['items'])->firstWhere('name', 'Soda 500ml');

        $r = $this->postJson('/api/v1/onboarding/templates/apply', ['items' => [
            ['key' => $sugar['key'], 'opening_stock' => 20],
            ['key' => $soda['key'], 'selling_price' => 1600, 'opening_stock' => 48],
        ]], $h)->assertStatus(201);
        $this->assertSame(2, $r->json('data.created'));
        $p = StockItem::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('name', 'Soda 500ml')->first();
        $this->assertSame('1600.00', (string) $p->selling_price);
        $this->assertEquals(48, (float) $p->current_quantity);
        // Applying again does not duplicate.
        $this->postJson('/api/v1/onboarding/templates/apply', ['items' => [['key' => $sugar['key']]]], $h)->assertStatus(201)->assertJsonPath('data.created', 0);

        $this->putJson('/api/v1/onboarding/money', ['payment_methods' => ['cash', 'mobile_money', 'credit'], 'momo_providers' => ['mtn_momo', 'mpesa'], 'opening_float' => 20000,
            'receipt_channels' => ['whatsapp', 'print']], $h)->assertOk()
            ->assertJsonPath('data.company.payment_methods.momo', ['mtn_momo'])->assertJsonPath('data.state.step', 'team');
        $this->postJson('/api/v1/onboarding/steps/team', ['skipped' => true, 'seconds' => 3], $h)->assertOk()->assertJsonPath('data.state.step', 'first_sale');

        $check = $this->getJson('/api/v1/onboarding', $h)->json('data.checklist');
        $this->assertSame(['add_products' => true, 'first_sale' => false, 'invite_staff' => false, 'set_up_momo' => true, 'whatsapp_receipts' => true],
            collect($check['items'])->pluck('done', 'key')->all());
        $this->assertSame(60, $check['percent']);
        $this->postJson('/api/v1/onboarding/steps/first_sale', [], $h)->assertOk()->assertJsonPath('data.state.step', 'done');
        $this->assertNotNull($this->getJson('/api/v1/onboarding', $h)->json('data.state.completed_at'));
        $this->assertSame(40, $this->getJson('/api/v1/onboarding', $h)->json('data.state.step_seconds.business'));
    }

    public function test_csv_import_previews_rejects_bad_rows_and_imports(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $csv = "Product;Category;Price;Cost;Qty;Unit\nOmo 500g;Detergent;4 000;3400;12;pcs\nBad row;Detergent;abc;;;\n;Detergent;100;;;\n";
        $preview = $this->postJson('/api/v1/onboarding/import', ['csv' => $csv, 'dry_run' => true], $h)->assertOk()->json('data');
        $this->assertSame(1, $preview['count']);
        $this->assertEquals(4000, $preview['rows'][0]['selling_price']);
        $this->assertSame([3, 4], array_column($preview['errors'], 'row'));
        $this->postJson('/api/v1/onboarding/import', ['csv' => $csv], $h)->assertStatus(422)->assertJsonPath('errors.code', 'import_errors');

        $file = UploadedFile::fake()->createWithContent('products.csv', "\xEF\xBB\xBFname,category,selling_price,buying_price,opening_stock,barcode\nOmo 500g,Detergent,4000,3400,12,6001234\nJik 750ml,Detergent,6500,5200,6,\n");
        $this->post('/api/v1/onboarding/import', ['file' => $file], $h + ['Accept' => 'application/json'])->assertStatus(201)->assertJsonPath('data.created', 2);
        $this->assertSame('6001234', StockItem::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('name', 'Omo 500g')->value('barcode'));
        $this->assertSame(1, DB::table('stock_categories')->where('company_id', $t['company_id'])->where('name', 'Detergent')->count());

        $this->postJson('/api/v1/onboarding/import', ['csv' => "price\n100\n"], $h)->assertStatus(422)->assertJsonPath('errors.code', 'missing_columns');
    }

    public function test_modules_and_permissions(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['shop', 'poultry']], $h)->assertOk()->assertJsonPath('data.modules', ['shop', 'poultry']);
        $this->getJson('/api/v1/auth/me', $h)->assertJsonPath('data.company.modules', ['shop', 'poultry']);
        $this->putJson('/api/v1/company/modules', ['modules' => ['games']], $h)->assertStatus(422);

        // A cashier can read the setup but not change it or add products.
        $invite = $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'phone' => '0772818001'], $h)->json('data.link');
        $token = $this->postJson('/api/v1/invites/'.basename($invite).'/accept', ['first_name' => 'C', 'last_name' => 'A', 'password' => 'secret123'])->json('data.token');
        $ch = $this->auth($token);
        $this->getJson('/api/v1/onboarding', $ch)->assertOk();
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail', 'country' => 'UG'], $ch)->assertStatus(403);
        $this->postJson('/api/v1/onboarding/templates/apply', ['items' => [['key' => 1]]], $ch)->assertStatus(403);
    }
}
