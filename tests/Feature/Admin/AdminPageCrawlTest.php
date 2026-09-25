<?php

namespace Tests\Feature\Admin;

use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Every page a shop owner can open loads without a server error, with real data behind it. */
class AdminPageCrawlTest extends AdminTestCase
{
    public function test_every_shop_page_opens(): void
    {
        $t = $this->makeTenant('company');
        $cid = $t['company']->id;
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'timezone' => 'Africa/Kampala'])->saveQuietly();
        Subscription::create(['company_id' => $cid, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Elec']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Bulbs', 'measurement_unit' => 'pcs']);
        $p = StockItem::create(['company_id' => $cid, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Bulb', 'sku' => 'B-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 50]);
        $stock = new \App\Services\Shop\StockService();
        $stock->record(['stock_item_id' => $p->id, 'type' => 'Sale', 'quantity' => 3, 'selling_price' => 1000, 'created_by_id' => $t['user']->id]);
        $stock->record(['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 1, 'created_by_id' => $t['user']->id]);
        $customer = \App\Models\Customer::create(['company_id' => $cid, 'name' => 'Amina', 'phone' => '0700000001']);
        $sale = (new \App\Services\Shop\SaleService())->checkout($cid, $t['user']->id, ['customer_id' => $customer->id, 'payments' => [['method' => 'cash', 'amount' => 1000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 2]]])['sale'];
        $item = DB::table('sale_record_items')->where('sale_record_id', $sale->id)->first();
        app(\App\Services\Shop\ReturnService::class)->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'restock' => false]], $t['user']->id, 'Faulty');

        $failures = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! in_array(\App\Http\Middleware\EnsureWebAccess::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (preg_match('/^(auth|_handle|gens?|pingpin|plans|subscriptions|companies\b|system-health|logout)/', $uri)) {
                continue;
            }
            if (preg_match_all('/\{(\w+)\??\}/', $uri, $m)) {
                $table = str_replace('-', '_', explode('/', $uri)[0]);
                if (count($m[1]) !== 1 || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }
                $id = DB::table($table)->where('company_id', $cid)->value('id');
                if ($id === null) {
                    continue;
                }
                $uri = preg_replace('/\{\w+\??\}/', (string) $id, $uri);
            }
            $status = $this->asAdmin($t['user'])->get('/'.ltrim($uri, '/'))->getStatusCode();
            if ($status >= 500) {
                $failures[] = "{$status} /{$uri}";
            }
        }
        $this->assertSame([], $failures);
    }

    /** Every admin route has the page behind it (a resource route without detail() or form() is a 500). */
    public function test_every_admin_route_has_its_page_method(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@') || ! str_starts_with($action, 'App\\Admin\\') || str_contains($action, 'AuthController')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (! method_exists($class, $method)) {
                $missing[] = "{$route->uri()}: {$class}::{$method}";

                continue;
            }
            if (str_starts_with((new \ReflectionMethod($class, $method))->getDeclaringClass()->getName(), 'Encore\\')) {
                $need = match ($method) {
                    'show' => 'detail', 'index' => 'grid', default => 'form'
                };
                if (! method_exists($class, $need)) {
                    $missing[] = "{$route->uri()}: {$class}::{$need}";
                }
            }
        }
        $this->assertSame([], $missing);
    }
}
