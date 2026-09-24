<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Shop\StockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Property-based check of the stock ledger invariant (plan §A2):
 *   current_quantity == original_quantity + Σ quantity_delta   (always)
 *   current_quantity >= 0 unless the product allows negative stock
 * over hundreds of random inbound/outbound movements and reversals.
 */
class StockMovementPropertyTest extends TestCase
{
    use DatabaseTransactions;

    /** @dataProvider seeds */
    public function test_ledger_invariant_holds_for_random_movements(int $seed): void
    {
        mt_srand($seed);
        $company = Company::factory()->create(['name' => 'Prop Co '.$seed, 'status' => 'Active']);
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'prop_'.$seed.'_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $user->id;
        $company->save();
        Auth::login($user);
        FinancialPeriod::create(['company_id' => $company->id, 'name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear(), 'status' => 'Active']);
        $cat = StockCategory::create(['company_id' => $company->id, 'name' => 'C']);
        $sub = StockSubCategory::create(['company_id' => $company->id, 'stock_category_id' => $cat->id, 'name' => 'S', 'measurement_unit' => 'kg']);
        $original = mt_rand(0, 50) + mt_rand(0, 999) / 1000;
        $item = StockItem::create([
            'company_id' => $company->id, 'created_by_id' => $user->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Random', 'sku' => 'RND-'.$seed.uniqid(), 'buying_price' => 7, 'selling_price' => 9, 'original_quantity' => $original,
        ]);

        $service = new StockService();
        $types = StockService::types();
        $recorded = [];
        $sumDelta = 0.0;

        for ($i = 0; $i < 150; $i++) {
            $current = (float) $item->fresh()->current_quantity;
            $this->assertEqualsWithDelta($original + $sumDelta, $current, 0.0005, "invariant broken at step {$i}");
            $this->assertGreaterThanOrEqual(0, $current);

            $roll = mt_rand(0, 9);
            if ($roll < 2 && $recorded) {
                // reverse a random earlier movement
                $target = $recorded[array_rand($recorded)];
                $before = (float) $item->fresh()->current_quantity;
                try {
                    $contra = $service->reverse($target, 'prop', $user->id);
                    $this->assertTrue($contra->is_reversal);
                    $this->assertEqualsWithDelta(-1 * (float) $target->quantity_delta, (float) $contra->quantity_delta, 0.0005, 'a reversal is the exact opposite delta');
                    $sumDelta = round($sumDelta + ((float) $item->fresh()->current_quantity - $before), 3);
                    unset($recorded[$target->id]);
                } catch (BusinessRuleException $e) {
                    // Undoing a receipt after the goods were sold would go negative: refused, nothing changes.
                    $this->assertSame('insufficient_stock', $e->errorCode());
                    $this->assertTrue(StockService::isInbound($target->type));
                    $this->assertEqualsWithDelta($before, (float) $item->fresh()->current_quantity, 0.0005);
                }

                continue;
            }

            $type = $types[mt_rand(0, count($types) - 1)];
            $qty = mt_rand(1, 20) + mt_rand(0, 999) / 1000;
            $inbound = StockService::isInbound($type);
            try {
                $record = $service->record(['stock_item_id' => $item->id, 'type' => $type, 'quantity' => $qty, 'created_by_id' => $user->id]);
                $delta = round(($inbound ? 1 : -1) * round($qty, 3), 3);
                $this->assertEqualsWithDelta($delta, (float) $record->quantity_delta, 0.0005);
                $this->assertGreaterThan(0, (float) $record->quantity, 'stored quantity is always positive');
                $sumDelta = round($sumDelta + $delta, 3);
                $recorded[$record->id] = $record;
            } catch (BusinessRuleException $e) {
                $this->assertSame('insufficient_stock', $e->errorCode());
                $this->assertFalse($inbound, 'inbound movements never fail on stock');
                $this->assertLessThan(round($qty, 3), $current + 0.0005, 'rejection only when it would go negative');
            }
        }

        $ledgerSum = (float) StockRecord::withoutGlobalScopes()->where('stock_item_id', $item->id)->sum('quantity_delta');
        $this->assertEqualsWithDelta($sumDelta, $ledgerSum, 0.0005);
        $this->assertEqualsWithDelta($original + $ledgerSum, (float) $item->fresh()->current_quantity, 0.0005);
    }

    public static function seeds(): array
    {
        return [[1], [42], [2026], [7], [99]];
    }
}
