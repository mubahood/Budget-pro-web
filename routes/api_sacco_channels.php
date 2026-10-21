<?php

/*
| Akiba Ledger P3 (docs/akiba/04-architecture.md §11, §13.2 P3 rows), required from routes/api_sacco.php inside the v1
| group: mobile-money collections and payouts, check-off, tills, statement imports and the member's own requests.
| Staff use the same middleware as the rest of the SACCO API (ApiPermissionMap names each write's permission; the
| services check it again). Members use their own token (SaccoMemberAuth), never a staff one.
*/

use App\Http\Controllers\Api\V1\Sacco\CollectionController;
use App\Http\Controllers\Api\V1\Sacco\MemberMoneyController;
use App\Http\Controllers\Api\V1\Sacco\PayoutController;
use App\Http\Controllers\Api\V1\Sacco\StatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('sacco')->middleware(['auth:sanctum', 'api.tenant', 'api.module:sacco', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('collections', [CollectionController::class, 'index']);
    Route::post('collections', [CollectionController::class, 'store'])->middleware('throttle:30,1');
    Route::post('collections/setup', [CollectionController::class, 'setup'])->middleware('throttle:10,1');
    Route::get('collections/{id}', [CollectionController::class, 'show'])->whereNumber('id');
    Route::post('collections/{id}/check', [CollectionController::class, 'check'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('payouts', [PayoutController::class, 'index']);
    Route::get('payouts/{id}', [PayoutController::class, 'show'])->whereNumber('id');
    Route::post('payouts/{id}/retry', [PayoutController::class, 'retry'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('payouts/{id}/resolve', [PayoutController::class, 'resolve'])->whereNumber('id');

    Route::get('statement-imports', [StatementController::class, 'index']);
    Route::post('statement-imports', [StatementController::class, 'store'])->middleware('throttle:10,1');
    Route::get('statement-imports/{id}', [StatementController::class, 'show'])->whereNumber('id');
    Route::post('statement-imports/{id}/match', [StatementController::class, 'match'])->whereNumber('id');
    Route::post('statement-imports/{id}/lines/{line}/{action}', [StatementController::class, 'review'])->whereNumber(['id', 'line'])
        ->whereIn('action', ['confirm', 'reject', 'link', 'ignore', 'unignore', 'journal']);
});

Route::prefix('sacco/me')->middleware([\App\Http\Middleware\SaccoMemberAuth::class, 'throttle:60,1'])->group(function () {
    Route::get('collections', [MemberMoneyController::class, 'collections']);
    Route::post('collections', [MemberMoneyController::class, 'collect'])->middleware('throttle:10,1');
    Route::get('collections/{id}', [MemberMoneyController::class, 'collection'])->whereNumber('id');
});
