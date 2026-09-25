<?php

use Illuminate\Routing\Router;

Admin::routes();

Route::group([
    'prefix' => config('admin.route.prefix'),
    'namespace' => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
    'as' => config('admin.route.prefix').'.',
], function (Router $router) {

    $router->get('/', 'HomeController@index')->name('home');
    $router->get('subscription-expired', 'BillingController@expired');
    $router->resource('product-templates', ProductTemplateController::class)->except(['show']);
    $router->get('setup', 'SetupController@index');
    $router->post('setup/business', 'SetupController@business');
    $router->post('setup/products', 'SetupController@products');
    $router->post('setup/import', 'SetupController@import');
    $router->post('setup/money', 'SetupController@money');
    $router->post('setup/team', 'SetupController@team');
    $router->post('setup/skip/{step}', 'SetupController@skip');
    $router->post('setup/dismiss', 'SetupController@dismiss');
    $router->get('billing', 'BillingController@index');
    $router->post('billing/checkout', 'BillingController@checkout');
    $router->post('billing/cancel', 'BillingController@cancel');
    $router->post('billing/resume', 'BillingController@resume');
    $router->get('billing/invoices/{id}', 'BillingController@invoice')->where('id', '[0-9]+');
    $router->resource('stock-categories', StockCategoryController::class);
    $router->resource('stock-sub-categories', StockSubCategoryController::class);
    $router->resource('financial-periods', FinancialPeriodController::class);
    $router->resource('employees', EmployeesController::class);
    $router->resource('stock-items', StockItemController::class);
    $router->resource('stock-records', StockRecordController::class);
    $router->post('stock-records/{id}/reverse', 'StockRecordController@reverse');
    // Stock pick-lists (tenant-scoped; see StockOptionsController).
    $router->get('ajax/sub-categories', 'StockOptionsController@subCategories');
    $router->get('ajax/stock-items', 'StockOptionsController@stockItems');
    $router->get('ajax/categories', 'StockOptionsController@categories');
    $router->post('ajax/categories', 'StockOptionsController@storeCategory');
    $router->resource('companies-edit', CompanyEditController::class);
    $router->resource('financial-categories', FinancialCategoryController::class);
    $router->resource('financial-reports', FinancialReportController::class);
    $router->resource('financial-records', FinancialRecordController::class);
    $router->resource('budget-programs', BudgetProgramController::class);
    $router->resource('contribution-records', ContributionRecordController::class);
    $router->resource('handover-records', HandoverRecordController::class);
    $router->resource('budget-item-categories', BudgetItemCategoryController::class);
    $router->resource('budget-items', BudgetItemController::class);
    $router->resource('data-exports', DataExportController::class);
    // Phase 2 — POS & inventory
    $router->get('customers/{id}/pay', 'CustomerController@payForm');
    $router->post('customers/{id}/pay', 'CustomerController@pay');
    $router->resource('customers', CustomerController::class);
    $router->get('suppliers/{id}/pay', 'SupplierController@payForm');
    $router->post('suppliers/{id}/pay', 'SupplierController@pay');
    $router->resource('suppliers', SupplierController::class);
    $router->resource('units', UnitController::class)->except(['show']);
    $router->resource('shifts', ShiftController::class)->only(['index', 'show']);
    $router->post('purchase-orders/{id}/send', 'PurchaseOrderController@send')->where('id', '[0-9]+');
    $router->post('purchase-orders/{id}/receive', 'PurchaseOrderController@receive')->where('id', '[0-9]+');
    $router->post('purchase-orders/{id}/cancel', 'PurchaseOrderController@cancel')->where('id', '[0-9]+');
    $router->resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'show', 'create', 'store']);
    $router->resource('purchase-returns', PurchaseReturnController::class)->only(['index', 'show', 'create', 'store']);
    $router->get('reports', 'ReportController@index');
    $router->get('your-data', 'YourDataController@index');
    $router->post('your-data/export', 'YourDataController@export');
    $router->get('your-data/exports/{id}', 'YourDataController@download')->where('id', '[0-9]+');
    $router->post('your-data/delete', 'YourDataController@delete');
    $router->post('your-data/delete/cancel', 'YourDataController@cancel');
    $router->get('system-health', 'SystemHealthController@index');
    $router->post('system-health/errors/{id}/resolve', 'SystemHealthController@resolve');
    $router->get('duplicates', 'DuplicateController@index');
    $router->post('duplicates/merge', 'DuplicateController@merge');
    $router->get('locations', 'LocationController@index');
    $router->post('locations', 'LocationController@store');
    $router->post('locations/devices', 'LocationController@devices');
    $router->get('stock-transfers', 'LocationController@transfers');
    $router->post('stock-transfers', 'LocationController@transfer');
    $router->get('reorder-suggestions', 'ReorderSuggestionController@index');
    $router->post('reorder-suggestions/orders', 'ReorderSuggestionController@orders');
    $router->resource('goods-receipts', GoodsReceiptController::class)->only(['index', 'show', 'create', 'store']);
    $router->get('stock-takes/{id}/count', 'StockTakeController@countForm');
    $router->post('stock-takes/{id}/count', 'StockTakeController@saveCounts');
    $router->post('stock-takes/{id}/post', 'StockTakeController@post');
    $router->resource('stock-takes', StockTakeController::class)->only(['index', 'show', 'create', 'store']);
    $router->resource('sale-records', SaleRecordController::class);
    $router->post('sale-records/{id}/void', 'SaleRecordController@void');
    $router->post('sale-records/{id}/send-receipt', 'SaleRecordController@sendReceipt');
    $router->post('sale-records/{id}/momo-request', 'SaleRecordController@momoRequest');
    $router->post('sale-records/{id}/return', 'SaleRecordController@returnItems');
    $router->post('sale-records/{id}/payments', 'SaleRecordController@receivePayment')->where('id', '[0-9]+');
    $router->post('sale-records/{id}/payments/{paymentId}/reverse', 'SaleRecordController@reversePayment')->where(['id' => '[0-9]+', 'paymentId' => '[0-9]+']);
    $router->get('ajax/products', 'AjaxController@products');
    $router->post('customers/{id}/remind', 'CustomerController@remind');
    $router->get('engagement', 'EngagementController@index');
    $router->post('engagement', 'EngagementController@save');
    $router->post('engagement/momo', 'EngagementController@momo');
    $router->resource('poultry-farm-types', PoultryFarmTypeController::class);
    $router->resource('poultry-production-guide-tasks', PoultryProductionGuideTaskController::class);
    $router->resource('poultry-batches', PoultryBatchController::class);
    $router->resource('poultry-feed-types', PoultryFeedTypeController::class);
    $router->resource('poultry-feed-stock', PoultryFeedStockController::class);
    $router->resource('poultry-customers', PoultryCustomerController::class);
    $router->resource('poultry-sales', PoultrySaleController::class);
    $router->resource('poultry-daily-records', PoultryDailyRecordController::class);
    $router->resource('poultry-expenses', PoultryExpenseController::class);
    $router->resource('poultry-egg-transactions', PoultryEggTransactionController::class);
    $router->resource('poultry-mortality-events', PoultryMortalityEventController::class);
    $router->resource('poultry-health-events', PoultryHealthEventController::class);
    $router->resource('poultry-vaccination-events', PoultryVaccinationEventController::class);

    $router->resource('tracked-devices', TrackedDeviceController::class);
    $router->post('tracked-devices/{id}/locate-now', 'TrackedDeviceController@locateNow');
    $router->resource('device-locations', DeviceLocationController::class)->only(['index', 'show']);
    $router->resource('device-commands', DeviceCommandController::class)->only(['index', 'show']);
    $router->get('tracking-map', 'DeviceLocationController@fleetMap');
    $router->get('tracking-map/{deviceId}', 'DeviceLocationController@deviceTrail');

    // Platform-administration screens: never for tenant users (P0-1). The
    // permission tables also exclude them; this is the explicit second lock.
    $router->middleware('admin.platform:all')->group(function (Router $router) {
        $router->resource('companies', CompanyController::class);
        $router->resource('gens', CodeGenController::class);
        $router->resource('gen', GenGenController::class);
        $router->resource('pingpin-plans', PingPinPlanController::class);
        $router->resource('plans', PlanController::class);
        $router->resource('subscriptions', SubscriptionController::class);
    });

});
