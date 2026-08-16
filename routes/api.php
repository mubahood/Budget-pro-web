<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BudgetItemCategoryController;
use App\Http\Controllers\Api\V1\BudgetItemController;
use App\Http\Controllers\Api\V1\BudgetProgramController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContributionRecordController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinancialCategoryController;
use App\Http\Controllers\Api\V1\FinancialPeriodController;
use App\Http\Controllers\Api\V1\FinancialRecordController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockCategoryController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockRecordController;
use App\Http\Controllers\Api\V1\StockSubCategoryController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Budget Pro v1
|--------------------------------------------------------------------------
|
| All endpoints live under /api/v1 and return the standard envelope:
|   { "code": 1|0, "message": string, "data": mixed, "meta"?: object, "errors"?: object }
|
| Authentication: Laravel Sanctum bearer tokens (Authorization: Bearer <token>).
| Tenant + subscription enforcement runs after auth via api.tenant / api.subscription.
|
*/

/**
 * Register the standard resource route set for a CRUD controller:
 * list / show / create / update / delete, plus /options (dropdowns) and /search (typeahead).
 */
if (! function_exists('apiCrud')) {
    function apiCrud(string $uri, string $controller): void
    {
        Route::get("{$uri}/options", [$controller, 'options']);
        Route::get("{$uri}/search", [$controller, 'search']);
        Route::get($uri, [$controller, 'index']);
        Route::post($uri, [$controller, 'store']);
        Route::get("{$uri}/{id}", [$controller, 'show'])->whereNumber('id');
        Route::put("{$uri}/{id}", [$controller, 'update'])->whereNumber('id');
        Route::patch("{$uri}/{id}", [$controller, 'update'])->whereNumber('id');
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->whereNumber('id');
    }
}

Route::prefix('v1')->group(function () {

    // ── Public auth endpoints (rate-limited to deter brute force) ──
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    // ── Public billing: pricing page + Flutterwave webhook (signature-verified) ──
    Route::get('plans', [BillingController::class, 'plans']);
    Route::post('webhooks/flutterwave', [BillingController::class, 'webhook']);

    // ── Authenticated: session/profile (no subscription gate) ──
    Route::middleware(['auth:sanctum', 'api.tenant'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);

        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update']);

        // Billing — reachable even when the subscription has lapsed, so a
        // customer can always pay to reactivate.
        Route::get('subscription', [BillingController::class, 'current']);
        Route::post('subscription/checkout', [BillingController::class, 'checkout']);
        Route::post('subscription/verify', [BillingController::class, 'verify']);
    });

    // ── Authenticated + active subscription: the product surface ──
    Route::middleware(['auth:sanctum', 'api.tenant', 'api.subscription'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::post('uploads', [UploadController::class, 'store']);

        // Inventory
        apiCrud('stock-categories', StockCategoryController::class);
        apiCrud('stock-sub-categories', StockSubCategoryController::class);
        apiCrud('stock-items', StockItemController::class);
        Route::get('stock-items/by-barcode/{code}', [StockItemController::class, 'byBarcode']);
        apiCrud('stock-records', StockRecordController::class);

        // Sales / POS
        apiCrud('sales', SaleController::class);
        Route::post('sales/checkout', [SaleController::class, 'checkout']);

        // Finance
        apiCrud('financial-categories', FinancialCategoryController::class);
        apiCrud('financial-periods', FinancialPeriodController::class);
        apiCrud('financial-records', FinancialRecordController::class);

        // Budget / fundraising
        apiCrud('budget-programs', BudgetProgramController::class);
        apiCrud('budget-item-categories', BudgetItemCategoryController::class);
        apiCrud('budget-items', BudgetItemController::class);
        apiCrud('contribution-records', ContributionRecordController::class);
    });
});
