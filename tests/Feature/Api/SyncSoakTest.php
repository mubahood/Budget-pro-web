<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;

/** Runs the soak command briefly in CI (B11); run it longer by hand against a throwaway tenant. */
class SyncSoakTest extends ApiTestCase
{
    public function test_short_soak_keeps_every_invariant(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S', 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        foreach (['Cola', 'Fanta', 'Water'] as $n) {
            $this->postJson('/api/v1/stock-items', ['name' => $n, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 5], $h)->assertStatus(201);
        }
        $code = Artisan::call('sync:soak', ['--company' => $t['company_id'], '--seconds' => 4, '--devices' => 3, '--seed' => 42]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('All invariants hold.', $out);
        $this->assertStringContainsString('duplicate sales: 0', $out);
    }
}
