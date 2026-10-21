<?php

/*
| Akiba Ledger's simple flows (docs/akiba/flows-plan.md), API v1: shares, the SACCO's own account, the share-out, loan
| requests and their approval, payout and repayments. Staff routes take a staff token; `sacco/me/*` a member token.
| Who may write: ApiPermissionMap, and the services (the chairperson's approval is checked by LoanFlow::isChair).
*/

use App\Http\Controllers\Api\V1\Sacco\FlowsController;
use App\Http\Controllers\Api\V1\Sacco\MemberFlowsController;
use Illuminate\Support\Facades\Route;

Route::prefix('sacco')->middleware(['auth:sanctum', 'api.tenant', 'api.module:sacco', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    // 1. Shares
    Route::get('shares', [FlowsController::class, 'shares']);

    // 2. The SACCO's own account
    Route::get('account', [FlowsController::class, 'account']);
    Route::get('account/statement', [FlowsController::class, 'statement']);
    Route::get('account/entries', [FlowsController::class, 'entries']);
    Route::post('account/entries', [FlowsController::class, 'record']);
    Route::post('account/entries/{id}/reverse', [FlowsController::class, 'reverse'])->whereNumber('id');
    Route::get('account/categories', [FlowsController::class, 'categories']);
    Route::post('account/categories', [FlowsController::class, 'storeCategory']);
    Route::match(['put', 'patch'], 'account/categories/{id}', [FlowsController::class, 'updateCategory'])->whereNumber('id');

    // 3. Share-out
    Route::get('share-out/preview', [FlowsController::class, 'preview']);
    Route::get('share-outs', [FlowsController::class, 'shareouts']);
    Route::post('share-outs', [FlowsController::class, 'runShareout']);
    Route::get('share-outs/{id}', [FlowsController::class, 'shareout'])->whereNumber('id');
    Route::get('share-outs/{id}/slips/{memberId}', [FlowsController::class, 'slip'])->whereNumber(['id', 'memberId']);

    // 4 and 5. Loan requests, approval, payout and repayments
    Route::get('loan-settings', [FlowsController::class, 'loanSettings']);
    Route::match(['put', 'patch'], 'loan-settings', [FlowsController::class, 'updateLoanSettings']);
    Route::get('loan-requests', [FlowsController::class, 'loanRequests']);
    Route::get('loan-requests/{id}', [FlowsController::class, 'loanRequest'])->whereNumber('id');
    Route::post('loan-requests/{id}/chair', [FlowsController::class, 'chair'])->whereNumber('id');
    Route::post('loan-requests/{id}/pay-out', [FlowsController::class, 'payOut'])->whereNumber('id');
    Route::post('loan-requests/{id}/not-paid-out', [FlowsController::class, 'notPaidOut'])->whereNumber('id');
    Route::post('loan-requests/{id}/repayments', [FlowsController::class, 'repay'])->whereNumber('id');
});

Route::prefix('sacco/me')->middleware([\App\Http\Middleware\SaccoMemberAuth::class, 'throttle:60,1'])->group(function () {
    Route::get('shares', [MemberFlowsController::class, 'shares']);
    Route::get('share-out', [MemberFlowsController::class, 'shareout']);
    Route::get('notices', [MemberFlowsController::class, 'notices']);
    Route::post('notices/read', [MemberFlowsController::class, 'readNotices']);
    Route::post('loan-quote', [MemberFlowsController::class, 'quote']);
    Route::get('guarantor-options', [MemberFlowsController::class, 'guarantorOptions']);
    Route::get('loan-requests', [MemberFlowsController::class, 'requests']);
    Route::post('loan-requests', [MemberFlowsController::class, 'ask']);
    Route::get('loan-requests/{id}', [MemberFlowsController::class, 'request'])->whereNumber('id');
    Route::get('guarantor-requests', [MemberFlowsController::class, 'guarantorRequests']);
    Route::post('guarantor-requests/{id}', [MemberFlowsController::class, 'decide'])->whereNumber('id');
});
