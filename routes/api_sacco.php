<?php

/*
| Akiba Ledger (the `sacco` module), API v1 (docs/akiba/04-architecture.md §13). Required from routes/api.php inside
| the v1 group, so every path here is /api/v1/…. Who may call what: ApiPermissionMap (writes) and the controllers
| (reads: sacco_view or the auditor's sacco_audit_view), with the finer rules in SaccoAccess and the services.
| Loans (P2) keep their routes in their own group.
*/

use App\Http\Controllers\Api\V1\Sacco\ApprovalController;
use App\Http\Controllers\Api\V1\Sacco\BooksController;
use App\Http\Controllers\Api\V1\Sacco\LedgerController;
use App\Http\Controllers\Api\V1\Sacco\MemberAuthController;
use App\Http\Controllers\Api\V1\Sacco\MemberController;
use App\Http\Controllers\Api\V1\Sacco\ReportController;
use App\Http\Controllers\Api\V1\Sacco\ReportDownloadController;
use App\Http\Controllers\Api\V1\Sacco\SavingsController;
use App\Http\Controllers\Api\V1\Sacco\TransactionController;
use Illuminate\Support\Facades\Route;

// A report file behind a 30-minute signed link (SaccoReportLinks): no session, the signature is the key.
Route::get('sacco/report-download', ReportDownloadController::class)->middleware('throttle:30,1')->name('sacco.report.download');

// Staff (04 §13.1): a SACCO's officers, with the module on and a live plan.
Route::prefix('sacco')->middleware(['auth:sanctum', 'api.tenant', 'api.module:sacco', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::post('setup', [BooksController::class, 'setup'])->middleware('throttle:10,1');
    Route::get('settings', [BooksController::class, 'settings']);
    Route::match(['put', 'patch'], 'settings', [BooksController::class, 'updateSettings']);
    Route::get('overview', [BooksController::class, 'overview']);
    Route::get('officers', [BooksController::class, 'officers']);
    Route::post('officers', [BooksController::class, 'appoint']);
    Route::get('officers/{id}', [BooksController::class, 'officer'])->whereNumber('id');
    Route::post('officers/{id}/end', [BooksController::class, 'endTerm'])->whereNumber('id');
    Route::get('audit', [BooksController::class, 'audit']);
    Route::get('ledger/verify', [BooksController::class, 'verify'])->middleware('throttle:10,1');

    Route::get('members', [MemberController::class, 'index']);
    Route::post('members', [MemberController::class, 'store']);
    Route::post('members/import', [MemberController::class, 'import'])->middleware('throttle:10,1');
    Route::get('members/{id}', [MemberController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'members/{id}', [MemberController::class, 'update'])->whereNumber('id');
    Route::post('members/{id}/kyc', [MemberController::class, 'kyc'])->whereNumber('id');
    Route::post('members/{id}/status', [MemberController::class, 'status'])->whereNumber('id');
    Route::get('members/{id}/relations', [MemberController::class, 'relations'])->whereNumber('id');
    Route::post('members/{id}/relations', [MemberController::class, 'addRelation'])->whereNumber('id');
    Route::delete('members/{id}/relations/{relationId}', [MemberController::class, 'endRelation'])->whereNumber(['id', 'relationId']);
    Route::post('members/{id}/documents', [MemberController::class, 'document'])->whereNumber('id');
    Route::get('members/{id}/statement', [MemberController::class, 'statement'])->whereNumber('id');

    Route::get('savings-products', [SavingsController::class, 'products']);
    Route::post('savings-products', [SavingsController::class, 'storeProduct']);
    Route::get('savings-products/{id}', [SavingsController::class, 'product'])->whereNumber('id');
    Route::match(['put', 'patch'], 'savings-products/{id}', [SavingsController::class, 'updateProduct'])->whereNumber('id');
    Route::get('savings-accounts', [SavingsController::class, 'accounts']);
    Route::post('savings-accounts', [SavingsController::class, 'open']);
    Route::get('savings-accounts/{id}', [SavingsController::class, 'account'])->whereNumber('id');
    Route::post('savings-accounts/{id}/freeze', [SavingsController::class, 'freeze'])->whereNumber('id');
    Route::post('savings-accounts/{id}/unfreeze', [SavingsController::class, 'unfreeze'])->whereNumber('id');
    Route::post('savings-accounts/{id}/close', [SavingsController::class, 'close'])->whereNumber('id');
    Route::get('share-accounts', [SavingsController::class, 'shareAccounts']);
    Route::post('shares/purchase', [SavingsController::class, 'purchase']);
    Route::post('shares/transfer', [SavingsController::class, 'transfer']);

    Route::get('transactions', [TransactionController::class, 'index']);
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{id}', [TransactionController::class, 'show'])->whereNumber('id');
    Route::post('transactions/{id}/reverse', [TransactionController::class, 'reverse'])->whereNumber('id');
    Route::get('transactions/{id}/receipt', [TransactionController::class, 'receipt'])->whereNumber('id');
    Route::post('withdrawals', [TransactionController::class, 'withdraw']);

    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::get('approvals/{id}', [ApprovalController::class, 'show'])->whereNumber('id');
    Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id');
    Route::post('approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id');
    Route::post('approvals/{id}/cancel', [ApprovalController::class, 'cancel'])->whereNumber('id');
    Route::post('approvals/{id}/execute', [ApprovalController::class, 'execute'])->whereNumber('id');

    Route::get('ledger/accounts', [LedgerController::class, 'accounts']);
    Route::post('ledger/accounts', [LedgerController::class, 'storeAccount']);
    Route::match(['put', 'patch'], 'ledger/accounts/{id}', [LedgerController::class, 'updateAccount'])->whereNumber('id');
    Route::get('ledger/journal', [LedgerController::class, 'journal']);
    Route::post('ledger/journal', [LedgerController::class, 'storeJournal']);
    Route::get('ledger/journal/{id}', [LedgerController::class, 'entry'])->whereNumber('id');
    Route::post('ledger/journal/{id}/reverse', [LedgerController::class, 'reverseEntry'])->whereNumber('id');
    Route::get('periods', [LedgerController::class, 'periods']);
    Route::post('periods/{id}/close', [LedgerController::class, 'close'])->whereNumber('id');
    Route::post('periods/{id}/reopen', [LedgerController::class, 'reopen'])->whereNumber('id');
    Route::get('opening-balances', [LedgerController::class, 'openingStatus']);
    Route::post('opening-balances/import', [LedgerController::class, 'importOpening'])->middleware('throttle:10,1');

    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z0-9_]+')->middleware('throttle:60,1');
});

// Loans (P2): their own file.
require __DIR__.'/api_sacco_loans.php';

// Members (04 §6.4): their own sign-in (public, throttled) and their own records (a member token, never a staff one).
Route::prefix('sacco/member-auth')->middleware(['edition:saas', 'throttle:10,1'])->group(function () {
    Route::post('otp', [MemberAuthController::class, 'otp']);
    Route::post('verify', [MemberAuthController::class, 'verify']);
    Route::post('token', [MemberAuthController::class, 'token']);
});
Route::prefix('sacco/me')->middleware([\App\Http\Middleware\SaccoMemberAuth::class, 'throttle:60,1'])->group(function () {
    Route::get('/', [MemberAuthController::class, 'show']);
    Route::get('accounts', [MemberAuthController::class, 'accounts']);
    Route::get('statement', [MemberAuthController::class, 'statement']);
    Route::get('receipts/{id}', [MemberAuthController::class, 'receipt'])->whereNumber('id');
    Route::post('logout', [MemberAuthController::class, 'logout']);
});
