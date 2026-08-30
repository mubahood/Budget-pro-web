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
    $router->resource('companies', CompanyController::class);
    $router->resource('stock-categories', StockCategoryController::class);
    $router->resource('stock-sub-categories', StockSubCategoryController::class);
    $router->resource('financial-periods', FinancialPeriodController::class);
    $router->resource('employees', EmployeesController::class);
    $router->resource('stock-items', StockItemController::class);
    $router->resource('stock-records', StockRecordController::class);
    $router->resource('companies-edit', CompanyEditController::class);
    $router->resource('gens', CodeGenController::class);
    $router->resource('gen', GenGenController::class);
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
    $router->resource('inventory-forecasts', InventoryForecastController::class);
    $router->get('inventory-forecasts-generate', 'InventoryForecastController@generate');
    $router->post('inventory-forecasts-generate', 'InventoryForecastController@processGenerate');
    $router->resource('auto-reorder-rules', AutoReorderRuleController::class);
    $router->get('auto-reorder-rules/trigger', 'AutoReorderRuleController@trigger');
    $router->resource('sale-records', SaleRecordController::class);
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

    $router->resource('pingpin-plans', PingPinPlanController::class);

    $router->resource('plans', PlanController::class);
    $router->resource('subscriptions', SubscriptionController::class);

});
