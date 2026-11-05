<?php

/*
| Estate Ledger – Rent & Tenants (the `rentals` module), API v1 (docs/estate-ledger/04-architecture.md §9). Required from routes/api.php
| inside the v1 group, so every path here is /api/v1/…. Who may call what: ApiPermissionMap (one line per group of the
| §9 table), then the services (RentalAccess: the permission again, and a caretaker's properties: a record outside them
| is a 404). Shapes: App\Http\Controllers\Api\V1\Rentals\Present (§9.1).
*/

use App\Http\Controllers\Api\V1\Rentals\ActivityController;
use App\Http\Controllers\Api\V1\Rentals\ArrearsController;
use App\Http\Controllers\Api\V1\Rentals\ChargesController;
use App\Http\Controllers\Api\V1\Rentals\DocumentsController;
use App\Http\Controllers\Api\V1\Rentals\DownloadsController;
use App\Http\Controllers\Api\V1\Rentals\ExpensesController;
use App\Http\Controllers\Api\V1\Rentals\LandlordsController;
use App\Http\Controllers\Api\V1\Rentals\LeasesController;
use App\Http\Controllers\Api\V1\Rentals\MaintenanceController;
use App\Http\Controllers\Api\V1\Rentals\MeController;
use App\Http\Controllers\Api\V1\Rentals\MetersController;
use App\Http\Controllers\Api\V1\Rentals\OverviewController;
use App\Http\Controllers\Api\V1\Rentals\PaymentsController;
use App\Http\Controllers\Api\V1\Rentals\PayoutsController;
use App\Http\Controllers\Api\V1\Rentals\PortalAuthController;
use App\Http\Controllers\Api\V1\Rentals\PropertiesController;
use App\Http\Controllers\Api\V1\Rentals\ReportsController;
use App\Http\Controllers\Api\V1\Rentals\SettingsController;
use App\Http\Controllers\Api\V1\Rentals\TenantsController;
use App\Http\Controllers\Api\V1\Rentals\UnitsController;
use App\Http\Middleware\RentalPortalAuth;
use Illuminate\Support\Facades\Route;

// Signed links (RentalLinks) for a phone's browser, no session: receipt and statement PDFs and report files (30 minutes),
// photos and documents (a week). The signature, the account and the person who asked are checked on every open.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('rentals/pdf/receipt', [DownloadsController::class, 'receipt'])->name('rentals.pdf.receipt');
    Route::get('rentals/pdf/statement', [DownloadsController::class, 'statement'])->name('rentals.pdf.statement');
    Route::get('rentals/pdf/payout-advice', [DownloadsController::class, 'payoutAdvice'])->name('rentals.pdf.payout-advice');
    Route::get('rentals/report-download', [DownloadsController::class, 'report'])->name('rentals.report.download');
    Route::get('rentals/files/{uuid}', [DownloadsController::class, 'file'])->where('uuid', '[0-9a-fA-F-]{36}')->name('rentals.file');
});

// Staff (§9): an agency's or a landlord's team, with the module on and a live plan.
Route::prefix('rentals')->middleware(['auth:sanctum', 'api.tenant', 'api.module:rentals', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('overview', [OverviewController::class, 'show']);
    Route::post('setup', [SettingsController::class, 'setup'])->middleware('throttle:10,1');
    Route::get('settings', [SettingsController::class, 'show']);
    Route::match(['put', 'patch'], 'settings', [SettingsController::class, 'update']);

    Route::get('landlords', [LandlordsController::class, 'index']);
    Route::post('landlords', [LandlordsController::class, 'store']);
    Route::get('landlords/{id}', [LandlordsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'landlords/{id}', [LandlordsController::class, 'update'])->whereNumber('id');
    Route::get('landlords/{id}/statement', [LandlordsController::class, 'statement'])->whereNumber('id');
    Route::post('landlords/{id}/login', [LandlordsController::class, 'login'])->whereNumber('id');

    Route::get('payouts', [PayoutsController::class, 'index']);
    Route::post('payouts', [PayoutsController::class, 'store']);
    Route::post('payouts/{id}/reverse', [PayoutsController::class, 'reverse'])->whereNumber('id');

    Route::get('properties', [PropertiesController::class, 'index']);
    Route::post('properties', [PropertiesController::class, 'store']);
    Route::get('properties/{id}', [PropertiesController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'properties/{id}', [PropertiesController::class, 'update'])->whereNumber('id');
    Route::put('properties/{id}/staff', [PropertiesController::class, 'staff'])->whereNumber('id');
    Route::post('properties/{id}/handover', [PropertiesController::class, 'handover'])->whereNumber('id');

    Route::get('units', [UnitsController::class, 'index']);
    Route::post('units', [UnitsController::class, 'store']);
    Route::get('units/{id}', [UnitsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'units/{id}', [UnitsController::class, 'update'])->whereNumber('id');

    Route::get('tenants', [TenantsController::class, 'index']);
    Route::post('tenants', [TenantsController::class, 'store']);
    Route::get('tenants/{id}', [TenantsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'tenants/{id}', [TenantsController::class, 'update'])->whereNumber('id');
    Route::post('tenants/{id}/login', [TenantsController::class, 'login'])->whereNumber('id');

    Route::get('leases', [LeasesController::class, 'index']);
    Route::post('leases', [LeasesController::class, 'store']);
    Route::get('leases/{id}', [LeasesController::class, 'show'])->whereNumber('id');
    Route::post('leases/{id}/notice', [LeasesController::class, 'notice'])->whereNumber('id');
    Route::post('leases/{id}/withdraw-notice', [LeasesController::class, 'withdrawNotice'])->whereNumber('id');
    Route::post('leases/{id}/renew', [LeasesController::class, 'renew'])->whereNumber('id');
    Route::post('leases/{id}/move-out', [LeasesController::class, 'moveOut'])->whereNumber('id');
    Route::post('leases/{id}/cancel', [LeasesController::class, 'cancel'])->whereNumber('id');
    Route::post('leases/{id}/refund-deposit', [LeasesController::class, 'refundDeposit'])->whereNumber('id');
    Route::post('leases/{id}/refund-credit', [LeasesController::class, 'refundCredit'])->whereNumber('id');
    Route::post('leases/{id}/rent-change', [LeasesController::class, 'rentChange'])->whereNumber('id');
    Route::post('leases/{id}/items', [LeasesController::class, 'addItem'])->whereNumber('id');
    Route::post('leases/{id}/items/{item}/end', [LeasesController::class, 'endItem'])->whereNumber(['id', 'item']);
    Route::get('leases/{id}/payment-preview', [LeasesController::class, 'paymentPreview'])->whereNumber('id');
    Route::get('leases/{id}/reminder', [ArrearsController::class, 'reminder'])->whereNumber('id');

    Route::get('charges', [ChargesController::class, 'index']);
    Route::post('charges', [ChargesController::class, 'store']);
    Route::post('charges/{id}/void', [ChargesController::class, 'void'])->whereNumber('id');
    Route::post('charges/{id}/waive', [ChargesController::class, 'waive'])->whereNumber('id');
    Route::post('billing/run', [ChargesController::class, 'run'])->middleware('throttle:10,1');

    Route::get('payments', [PaymentsController::class, 'index']);
    Route::post('payments', [PaymentsController::class, 'store']);
    Route::get('payments/{id}', [PaymentsController::class, 'show'])->whereNumber('id');
    Route::post('payments/{id}/reverse', [PaymentsController::class, 'reverse'])->whereNumber('id');
    Route::get('payments/{id}/receipt', [PaymentsController::class, 'receipt'])->whereNumber('id');

    Route::get('arrears', [ArrearsController::class, 'index']);
    Route::get('follow-ups', [ArrearsController::class, 'followUps']);
    Route::post('follow-ups', [ArrearsController::class, 'logFollowUp']);

    Route::get('expenses', [ExpensesController::class, 'index']);
    Route::post('expenses', [ExpensesController::class, 'store']);
    Route::post('expenses/{id}/reverse', [ExpensesController::class, 'reverse'])->whereNumber('id');
    Route::get('cash', [ExpensesController::class, 'cash']);
    Route::post('cash/transfers', [ExpensesController::class, 'transfer']);
    Route::post('cash/opening', [ExpensesController::class, 'opening']);

    Route::get('documents', [DocumentsController::class, 'index']);
    Route::post('documents', [DocumentsController::class, 'store']);
    Route::delete('documents/{id}', [DocumentsController::class, 'destroy'])->whereNumber('id');

    Route::get('maintenance', [MaintenanceController::class, 'index']);
    Route::post('maintenance', [MaintenanceController::class, 'store']);
    Route::get('maintenance/{id}', [MaintenanceController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'maintenance/{id}', [MaintenanceController::class, 'update'])->whereNumber('id');

    Route::get('meter-readings', [MetersController::class, 'index']);
    Route::post('meter-readings', [MetersController::class, 'store']);

    Route::get('reports', [ReportsController::class, 'index']);
    Route::get('reports/{key}', [ReportsController::class, 'show'])->where('key', '[a-z0-9_]+')->middleware('throttle:60,1');

    Route::get('activity', [ActivityController::class, 'index']);
});

// Tenants and landlords (§8): their own records, with their own token (never a staff one).
Route::prefix('rentals/me')->middleware([RentalPortalAuth::class, 'throttle:60,1'])->group(function () {
    Route::get('/', [MeController::class, 'show']);
    Route::post('logout', [MeController::class, 'logout']);
    Route::post('password', [MeController::class, 'password'])->middleware('throttle:10,1');
    // A tenant.
    Route::get('charges', [MeController::class, 'charges']);
    Route::get('payments', [MeController::class, 'payments']);
    Route::get('payments/{id}', [MeController::class, 'payment'])->whereNumber('id');
    Route::get('payments/{id}/receipt', [MeController::class, 'receipt'])->whereNumber('id');
    Route::get('lease/{id}', [MeController::class, 'lease'])->whereNumber('id');
    Route::get('maintenance', [MeController::class, 'maintenance']);
    Route::post('maintenance', [MeController::class, 'report']);
    Route::post('notice', [MeController::class, 'notice']);
    Route::post('files', [MeController::class, 'upload'])->middleware('throttle:20,1');
    // A landlord.
    Route::get('properties', [MeController::class, 'properties']);
    Route::get('statement', [MeController::class, 'statement']);
    Route::get('payouts', [MeController::class, 'payouts']);
    Route::get('arrears', [MeController::class, 'arrears']);
});

// Their sign-in (§8): public, SaaS only, throttled.
Route::prefix('rentals/portal-auth')->middleware(['edition:saas', 'throttle:10,1'])->group(function () {
    Route::post('login', [PortalAuthController::class, 'login']);
    Route::post('token', [PortalAuthController::class, 'token']);
    Route::post('code', [PortalAuthController::class, 'code']);
    Route::post('code/verify', [PortalAuthController::class, 'verify']);
});
