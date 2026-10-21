<?php

/*
| Akiba Ledger loans (P2, docs/akiba/04-architecture.md §13.2), required from routes/api_sacco.php inside the v1 group.
| Staff: the same middleware as the rest of the SACCO API; ApiPermissionMap names each write's permission and the
| services check it again with the finer rules (positions, maker ≠ checker). Members: their own token. Guarantors:
| the public consent page's API, by the link's opaque token, throttled.
*/

use App\Http\Controllers\Api\V1\Sacco\Loans\LoanController;
use App\Http\Controllers\Api\V1\Sacco\Loans\LoanProductController;
use App\Http\Controllers\Api\V1\Sacco\Loans\LoanSelfServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('sacco')->middleware(['auth:sanctum', 'api.tenant', 'api.module:sacco', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('loan-products', [LoanProductController::class, 'index']);
    Route::post('loan-products', [LoanProductController::class, 'store']);
    Route::get('loan-products/{id}', [LoanProductController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'loan-products/{id}', [LoanProductController::class, 'update'])->whereNumber('id');
    Route::post('loan-products/{id}/preview', [LoanProductController::class, 'preview'])->whereNumber('id');

    Route::get('loans', [LoanController::class, 'index']);
    Route::post('loans', [LoanController::class, 'store']);
    Route::get('loans/{id}', [LoanController::class, 'find'])->whereNumber('id');
    Route::match(['put', 'patch'], 'loans/{id}', [LoanController::class, 'update'])->whereNumber('id');
    Route::post('loans/{id}/votes', [LoanController::class, 'vote'])->whereNumber('id');
    Route::get('loans/{id}/guarantors', [LoanController::class, 'guarantors'])->whereNumber('id');
    Route::post('loans/{id}/guarantors', [LoanController::class, 'addGuarantor'])->whereNumber('id');
    Route::post('loans/{id}/guarantors/{g}/{action}', [LoanController::class, 'guarantorAct'])->whereNumber(['id', 'g'])->whereIn('action', ['consent', 'decline', 'remove', 'resend', 'recover']);
    Route::get('loans/{id}/collateral', [LoanController::class, 'collateral'])->whereNumber('id');
    Route::post('loans/{id}/collateral', [LoanController::class, 'addCollateral'])->whereNumber('id');
    Route::post('loans/{id}/collateral/{c}/{action}', [LoanController::class, 'collateralAct'])->whereNumber(['id', 'c'])->whereIn('action', ['demand', 'realise', 'release']);
    Route::get('loans/{id}/repayments', [LoanController::class, 'repayments'])->whereNumber('id');
    Route::post('loans/{id}/repayments', [LoanController::class, 'repay'])->whereNumber('id');
    Route::get('loans/{id}/documents/{doc}', [LoanController::class, 'document'])->whereNumber(['id', 'doc']);
    Route::get('loans/{id}/{what}', [LoanController::class, 'read'])->whereNumber('id')->whereIn('what', ['schedule', 'statement', 'settlement-quote']);
    Route::post('loans/{id}/{action}', [LoanController::class, 'act'])->whereNumber('id')
        ->whereIn('action', ['submit', 'appraise', 'send-to-committee', 'request-decision', 'decide', 'appeal', 'recuse', 'contract', 'sign', 'cancel', 'disburse', 'top-up', 'reschedule', 'write-off', 'recover']);
    Route::get('guarantees', [LoanController::class, 'guarantees']);
    Route::post('members/{id}/exit', [LoanController::class, 'exit'])->whereNumber('id');
});

Route::prefix('sacco/me')->middleware([\App\Http\Middleware\SaccoMemberAuth::class, 'throttle:60,1'])->group(function () {
    Route::get('loans', [LoanSelfServiceController::class, 'loans']);
    Route::get('loans/{id}', [LoanSelfServiceController::class, 'loan'])->whereNumber('id');
    Route::post('loan-applications', [LoanSelfServiceController::class, 'apply']);
    Route::get('guarantee-requests', [LoanSelfServiceController::class, 'guarantees']);
    Route::post('guarantee-requests/{id}/{action}', [LoanSelfServiceController::class, 'guarantee'])->whereNumber('id')->whereIn('action', ['code', 'consent', 'decline']);
});

Route::prefix('sacco/consent')->middleware(['throttle:10,1'])->group(function () {
    Route::get('{token}', [LoanSelfServiceController::class, 'notice'])->where('token', '[A-Za-z0-9]{32,64}');
    Route::post('{token}/{action}', [LoanSelfServiceController::class, 'consent'])->where('token', '[A-Za-z0-9]{32,64}')->whereIn('action', ['code', 'consent', 'decline']);
});
