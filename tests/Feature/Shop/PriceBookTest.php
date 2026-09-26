<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialPeriod;
use App\Models\LabelQueueItem;
use App\Models\PriceChange;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\PriceBookService;
use App\Services\Shop\ShelfLabelService;
use App\Support\NicePrice;
use App\Support\StoreFeatures;
use Database\Seeders\ProductTemplateSeeder;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 2, the shelf (SUPERMARKET_PLAN.md B1, B5, B6): price book, nice prices, label queue. */
class PriceBookTest extends AdminTestCase
{
    private array $t;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $this->sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;
    }

    private function product(array $attrs = []): StockItem
    {
        return StockItem::create($attrs + ['company_id' => $this->t['company']->id, 'created_by_id' => $this->t['user']->id, 'stock_sub_category_id' => $this->sub,
            'name' => 'Item '.uniqid(), 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => 1000, 'original_quantity' => 10])->fresh();
    }

    private function features(array $features): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function changes(): \Illuminate\Support\Collection
    {
        return PriceChange::query()->where('company_id', $this->t['company']->id)->orderBy('id')->get();
    }

    private function labels(): \Illuminate\Support\Collection
    {
        return LabelQueueItem::query()->where('company_id', $this->t['company']->id)->orderBy('id')->get();
    }

    public function test_off_a_price_edit_logs_nothing_and_queues_nothing(): void
    {
        $p = $this->product();
        $p->update(['selling_price' => 1200, 'buying_price' => 700]);
        $this->assertSame(1200.0, (float) $p->fresh()->selling_price);
        $this->assertCount(0, $this->changes());
        $this->assertCount(0, $this->labels());
    }

    public function test_on_every_model_price_change_is_logged_with_who_and_old_new(): void
    {
        $this->features(['price_book' => true]);
        $this->actingAs($this->t['user'], 'admin');
        $p = $this->product();
        $this->assertCount(0, $this->changes(), 'creating a product is not a price change');
        $p->update(['selling_price' => 1200, 'buying_price' => 700]);
        $p->update(['name' => 'Renamed']); // no price change, no row
        $rows = $this->changes();
        $this->assertCount(2, $rows);
        $sell = $rows->firstWhere('field', 'selling');
        $this->assertSame([1000.0, 1200.0, $this->t['user']->id], [(float) $sell->old, (float) $sell->new, (int) $sell->created_by]);
        $this->assertNotNull($sell->applied_at);
        $this->assertSame(700.0, (float) $rows->firstWhere('field', 'buying')->new);
        $this->assertCount(0, $this->labels(), 'shelf labels are a separate feature');

        $history = (new PriceBookService)->history($this->t['company']->id, $p->id, ['selling']);
        $this->assertCount(1, $history);
        $this->assertSame('Tenant Company', $history[0]['who']);
    }

    public function test_change_now_saves_through_the_model_with_a_reason(): void
    {
        $this->features(['price_book' => true, 'shelf_labels' => true]);
        $p = $this->product();
        $this->assertCount(1, $this->labels(), 'a new product joins the label queue');
        (new PriceBookService)->changeNow($this->t['company']->id, $p->id, 'selling', 1500, 'Supplier rise', $this->t['user']->id);
        $this->assertSame(1500.0, (float) $p->fresh()->selling_price);
        $row = $this->changes()->sole();
        $this->assertSame(['Supplier rise', 1000.0, 1500.0], [$row->reason, (float) $row->old, (float) $row->new]);
        $labels = $this->labels();
        $this->assertCount(1, $labels, 'one waiting label per product');
        $this->assertSame('price_change', $labels[0]->reason);

        $this->expectException(BusinessRuleException::class);
        (new PriceBookService)->changeNow($this->t['company']->id, $p->id, 'selling', 1500);
    }

    public function test_schedule_apply_due_and_cancel(): void
    {
        $this->features(['price_book' => true, 'shelf_labels' => true]);
        $p = $this->product();
        (new ShelfLabelService)->markPrinted($this->t['company']->id);
        $book = new PriceBookService;
        $cid = $this->t['company']->id;

        $due = $book->schedule($cid, $p->id, 'selling', 1100, now()->addHour(), 'Monday prices', $this->t['user']->id);
        $gone = $book->schedule($cid, $p->id, 'selling', 900, now()->addHours(2));
        $this->assertSame(1000.0, (float) $p->fresh()->selling_price, 'nothing changes before its time');
        $this->assertSame(0, $book->applyDue($cid));
        $this->assertCount(2, $book->scheduled($cid, $p->id));

        $book->cancel($cid, $gone->id);
        $this->assertCount(1, $book->scheduled($cid, $p->id));

        $this->assertSame(1, $book->applyDue($cid, now()->addHours(3)));
        $this->assertSame(1100.0, (float) $p->fresh()->selling_price);
        $due->refresh();
        $this->assertNotNull($due->applied_at);
        $this->assertSame(1000.0, (float) $due->old);
        $this->assertCount(2, $this->changes(), 'the applied row is the log entry, not a second one');
        $this->assertNull($gone->fresh()->applied_at);
        $this->assertSame('price_change', (new ShelfLabelService)->pending($cid)[0]['reason']);
        $this->assertSame(0, $book->applyDue($cid, now()->addHours(3)), 'applied once only');

        $this->expectException(BusinessRuleException::class);
        $book->schedule($cid, $p->id, 'selling', 1300, now()->subMinute());
    }

    public function test_the_command_applies_due_changes(): void
    {
        $p = $this->product();
        PriceChange::create(['company_id' => $this->t['company']->id, 'stock_item_id' => $p->id, 'field' => 'buying', 'new' => 650, 'starts_at' => now()->subMinute()]);
        $this->artisan('prices:apply-due', ['--company' => $this->t['company']->id])->assertSuccessful();
        $this->assertSame(650.0, (float) $p->fresh()->buying_price);
    }

    public function test_a_failing_log_never_fails_the_save(): void
    {
        $this->features(['price_book' => true, 'shelf_labels' => true]);
        $p = $this->product();
        PriceChange::creating(fn () => throw new \RuntimeException('boom'));
        try {
            $p->update(['selling_price' => 1300]);
        } finally {
            PriceChange::flushEventListeners();
        }
        $this->assertSame(1300.0, (float) $p->fresh()->selling_price);
        $this->assertCount(0, $this->changes());
    }

    public function test_other_shops_cannot_schedule_or_cancel(): void
    {
        $p = $this->product();
        $other = $this->makeTenant('company')['company']->id;
        $this->expectException(BusinessRuleException::class);
        (new PriceBookService)->schedule($other, $p->id, 'selling', 5, now()->addDay());
    }

    public function test_nice_prices_keep_the_template_results_and_suggest(): void
    {
        $this->assertSame([180, 3500, 40], [ProductTemplateSeeder::nice(178.6), ProductTemplateSeeder::nice(3493), ProductTemplateSeeder::nice(38)]);
        $this->assertSame([0.25, 1.4, 12.3, 145.0], [ProductTemplateSeeder::niceCents(0.274), ProductTemplateSeeder::niceCents(1.37), ProductTemplateSeeder::niceCents(12.3), ProductTemplateSeeder::niceCents(143)]);
        $this->assertSame([3450.0, 3950.0], NicePrice::suggest(3420, 'UGX'));
        $this->assertSame([4.4, 4.5, 4.99], NicePrice::suggest(4.37, 'USD'));
        $this->assertSame(1000.0, NicePrice::fromCost(750, 25, 'UGX'));
        $this->assertGreaterThanOrEqual(1333.34, NicePrice::fromCost(1000, 25, 'UGX'));
    }

    public function test_unit_price_for_shelf_labels(): void
    {
        $this->assertSame(['price' => 2500.0, 'per' => 'kg'], ShelfLabelService::unitPrice(5000, 'unit', null, 'Sugar 2kg'));
        $this->assertSame(['price' => 400.0, 'per' => '100 g'], ShelfLabelService::unitPrice(2000, 'unit', null, 'Tea 500 g'));
        $this->assertSame(['price' => 1000.0, 'per' => 'L'], ShelfLabelService::unitPrice(6000, 'unit', null, 'Soda 6 x 1L'));
        $this->assertSame(['price' => 4500.0, 'per' => 'kg'], ShelfLabelService::unitPrice(4500, 'weight', 'kg', 'Beef'));
        $this->assertNull(ShelfLabelService::unitPrice(1000, 'unit', 'pcs', 'Pen'));
    }
}
