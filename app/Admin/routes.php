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
    $router->resource('stock-categories', StockCategoryController::class);
    $router->resource('stock-sub-categories', StockSubCategoryController::class);
    $router->resource('financial-periods', FinancialPeriodController::class);
    $router->resource('employees', EmployeesController::class);
    $router->resource('stock-items', StockItemController::class);
    $router->resource('stock-records', StockRecordController::class);
    $router->post('stock-records/{id}/reverse', 'StockRecordController@reverse');
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
    $router->resource('purchase-orders', PurchaseOrderController::class);
    // P0-12: forecasting + auto-reorder are unfinished (Phase 4 rebuild); hidden unless the flag is on.
    // The trigger route is declared before the resource so it is no longer shadowed by {auto_reorder_rule}.
    if (config('saas.features.inventory_automation')) {
        $router->get('inventory-forecasts-generate', 'InventoryForecastController@generate');
        $router->post('inventory-forecasts-generate', 'InventoryForecastController@processGenerate');
        $router->resource('inventory-forecasts', InventoryForecastController::class);
        $router->get('auto-reorder-rules/trigger', 'AutoReorderRuleController@trigger');
        $router->resource('auto-reorder-rules', AutoReorderRuleController::class);
    }
    // Phase 2 — POS & inventory
    $router->get('customers/{id}/pay', 'CustomerController@payForm');
    $router->post('customers/{id}/pay', 'CustomerController@pay');
    $router->resource('customers', CustomerController::class);
    $router->get('suppliers/{id}/pay', 'SupplierController@payForm');
    $router->post('suppliers/{id}/pay', 'SupplierController@pay');
    $router->resource('suppliers', SupplierController::class);
    $router->resource('units', UnitController::class);
    $router->resource('shifts', ShiftController::class)->only(['index', 'show']);
    $router->resource('goods-receipts', GoodsReceiptController::class)->only(['index', 'show', 'create', 'store']);
    $router->get('stock-takes/{id}/count', 'StockTakeController@countForm');
    $router->post('stock-takes/{id}/count', 'StockTakeController@saveCounts');
    $router->post('stock-takes/{id}/post', 'StockTakeController@post');
    $router->resource('stock-takes', StockTakeController::class)->only(['index', 'show', 'create']);
    $router->resource('sale-records', SaleRecordController::class);
    $router->post('sale-records/{id}/void', 'SaleRecordController@void');
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
    $router->resource('device-locations', DeviceLocationController::class);
    $router->resource('device-commands', DeviceCommandController::class);
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
