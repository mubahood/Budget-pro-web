<?php

namespace Tests\Feature\Shop;

use App\Models\FinancialPeriod;
use App\Models\FiscalSetting;
use App\Models\FiscalSubmission;
use App\Models\HeldCart;
use App\Models\LabelQueueItem;
use App\Models\PriceChange;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturnItem;
use App\Models\SaleRecord;
use App\Models\SaleReturn;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\SyncBatch;
use App\Scopes\CompanyScope;
use App\Services\Fiscal\FiscalAdapter;
use App\Services\Fiscal\FiscalRegistry;
use App\Services\Fiscal\FiscalResult;
use App\Services\Fiscal\FiscalService;
use App\Services\Shop\SaleService;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Tenancy that cannot be forgotten (roadmap 02 §4, P0-4): the models that had no CompanyScope now narrow to the
 * signed-in session's company and to the company of an API request, while the scheduler's sweeps (no user, no
 * request) still see every company's rows.
 */
class TenantScopeSweepsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        FiscalRegistry::bind('efris', null);
        parent::tearDown();
    }

    /** @return array{user: \App\Models\User, company: \App\Models\Company, item: int} */
    private function shop(): array
    {
        $t = $this->makeTenant('company');
        $cid = (int) $t['company']->id;
        $t['company']->forceFill(['timezone' => 'Africa/Kampala'])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food'])->id;
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;
        $t['item'] = (int) StockItem::create(['company_id' => $cid, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat, 'stock_sub_category_id' => $sub,
            'name' => 'Sugar '.uniqid(), 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => 1000, 'original_quantity' => 100])->id;

        return $t;
    }

    public function test_the_price_book_sweep_applies_every_companys_due_changes_with_nobody_signed_in(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        foreach ([$a, $b] as $t) {
            PriceChange::create(['company_id' => $t['company']->id, 'stock_item_id' => $t['item'], 'field' => 'buying', 'new' => 650, 'starts_at' => now()->subMinute()]);
        }
        $this->assertNull(Auth::guard('admin')->user());
        $this->assertNull(CompanyScope::currentCompanyId(), 'the scheduler has no user and no request company');

        Artisan::call('prices:apply-due');

        $this->assertSame(650.0, (float) StockItem::withoutGlobalScopes()->find($a['item'])->buying_price);
        $this->assertSame(650.0, (float) StockItem::withoutGlobalScopes()->find($b['item'])->buying_price);
        $this->assertSame(2, PriceChange::query()->withoutGlobalScope(CompanyScope::class)->whereIn('company_id', [$a['company']->id, $b['company']->id])->whereNotNull('applied_at')->count());
    }

    public function test_the_fiscal_sweep_sends_every_companys_due_receipts_with_nobody_signed_in(): void
    {
        $adapter = new class implements FiscalAdapter
        {
            /** @var array<int, int> company ids sent */
            public array $sent = [];

            public function key(): string
            {
                return 'efris';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function country(): ?string
            {
                return null;
            }

            public function configFields(): array
            {
                return ['tin' => ['TIN', 'text', true]];
            }

            public function remote(): bool
            {
                return true;
            }

            public function test(array $config, string $environment): FiscalResult
            {
                return FiscalResult::ok('ok');
            }

            public function submit(SaleRecord $sale, array $config, string $environment): FiscalResult
            {
                $this->sent[] = (int) $sale->company_id;

                return FiscalResult::ok('FN-'.$sale->id, 'V'.$sale->id);
            }

            public function supportsCreditNotes(): bool
            {
                return false;
            }

            public function creditNote(SaleReturn $return, SaleRecord $sale, array $original, array $config, string $environment): FiscalResult
            {
                return FiscalResult::fail('no');
            }
        };
        FiscalRegistry::bind('efris', $adapter);

        $companies = [];
        foreach ([$this->shop(), $this->shop()] as $t) {
            $cid = (int) $t['company']->id;
            StoreFeatures::update($t['company'], ['features' => ['fiscal' => true]]);
            (new FiscalService())->saveSettings($cid, 'efris', ['tin' => '100'.$cid], 'sandbox', true, $t['user']->id);
            (new SaleService())->checkout($cid, $t['user']->id, ['items' => [['stock_item_id' => $t['item'], 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 1000]], 'payments_explicit' => true]);
            $companies[] = $cid;
        }
        $this->assertSame(2, FiscalSubmission::query()->withoutGlobalScope(CompanyScope::class)->whereIn('company_id', $companies)->where('status', 'pending')->count());
        $this->assertNull(CompanyScope::currentCompanyId());

        Artisan::call('fiscal:submit-due');

        $this->assertEqualsCanonicalizing($companies, $adapter->sent, 'both shops\' receipts went out in one sweep');
        $this->assertSame(2, FiscalSubmission::query()->withoutGlobalScope(CompanyScope::class)->whereIn('company_id', $companies)->where('status', 'sent')->count());
    }

    /** One row of every newly scoped model for the shop. */
    private function rowsFor(array $t): void
    {
        $cid = (int) $t['company']->id;
        HeldCart::create(['company_id' => $cid, 'user_id' => $t['user']->id, 'label' => 'Cart', 'lines' => [['q' => 1]], 'total' => 1000, 'line_count' => 1]);
        PriceChange::create(['company_id' => $cid, 'stock_item_id' => $t['item'], 'field' => 'selling', 'new' => 1200, 'starts_at' => now()->addDay()]);
        LabelQueueItem::create(['company_id' => $cid, 'stock_item_id' => $t['item'], 'reason' => 'price_change', 'created_at' => now()]);
        FiscalSetting::create(['company_id' => $cid, 'adapter' => 'manual', 'is_active' => false]);
        $sale = (int) (new SaleService())->checkout($cid, $t['user']->id, ['items' => [['stock_item_id' => $t['item'], 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1000]], 'payments_explicit' => true])['sale']->id;
        FiscalSubmission::create(['company_id' => $cid, 'sale_record_id' => $sale, 'adapter' => 'manual', 'status' => 'pending']);
        $po = PurchaseOrder::withoutGlobalScopes()->create(['company_id' => $cid, 'number' => 'PO-'.uniqid(), 'order_date' => now()->toDateString()]);
        PurchaseOrderItem::create(['company_id' => $cid, 'purchase_order_id' => $po->id, 'stock_item_id' => $t['item'], 'quantity' => 2]);
        $ret = DB::table('purchase_returns')->insertGetId(['company_id' => $cid, 'number' => 'PRT-'.uniqid(), 'returned_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        PurchaseReturnItem::create(['company_id' => $cid, 'purchase_return_id' => $ret, 'stock_item_id' => $t['item'], 'quantity' => 1, 'unit_cost' => 500]);
        SyncBatch::create(['company_id' => $cid, 'batch_uuid' => (string) Str::uuid(), 'status' => 'applied']);
    }

    public function test_the_newly_scoped_models_narrow_to_the_session_and_to_an_api_requests_company(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        $this->rowsFor($a);
        $this->rowsFor($b);
        $both = [(int) $a['company']->id, (int) $b['company']->id];
        $models = [HeldCart::class, PriceChange::class, LabelQueueItem::class, FiscalSetting::class, FiscalSubmission::class,
            PurchaseOrderItem::class, PurchaseReturnItem::class, SyncBatch::class];
        $companiesSeen = fn (string $model) => $model::query()->whereIn('company_id', $both)->distinct()->orderBy('company_id')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

        // Nobody signed in (console, scheduler, queue): no narrowing.
        foreach ($models as $model) {
            $this->assertSame($both, $companiesSeen($model), "{$model} with nobody signed in");
        }

        // An API request for A (EnsureApiTenant runs the request inside forRequest).
        CompanyScope::forRequest($both[0], function () use ($models, $companiesSeen, $both) {
            foreach ($models as $model) {
                $this->assertSame([$both[0]], $companiesSeen($model), "{$model} inside A's API request");
            }
            // The relation follows too: A's order lines only.
            $this->assertSame([$both[0]], PurchaseOrder::withoutGlobalScopes()->whereIn('company_id', $both)->get()->flatMap->items->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        });
        $this->assertNull(CompanyScope::requestCompanyId(), 'forgotten when the request is done');

        // B signed in to the classic or portal screens (the `admin` guard).
        $this->actingAs($b['user'], 'admin');
        foreach ($models as $model) {
            $this->assertSame([$both[1]], $companiesSeen($model), "{$model} for B's session");
        }
    }

    public function test_the_request_company_is_cleared_even_when_the_request_throws(): void
    {
        try {
            CompanyScope::forRequest(123, function () {
                $this->assertSame(123, CompanyScope::currentCompanyId());
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull(CompanyScope::requestCompanyId());
        $this->assertNull(CompanyScope::currentCompanyId());

        // Nested (an API call made while another is handled, e.g. a sync job in tests): the outer company comes back.
        CompanyScope::forRequest(1, function () {
            CompanyScope::forRequest(2, fn () => $this->assertSame(2, CompanyScope::currentCompanyId()));
            $this->assertSame(1, CompanyScope::currentCompanyId());
        });
    }
}
