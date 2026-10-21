<?php

/*
| Akiba Ledger P4 (docs/akiba/04-architecture.md §13.2): the returns calendar, the ratios, the auditor's export, savings
| interest runs, the year-end close, the appropriation, the dividend run, Share Transfer Fund buy-backs and dormant
| accounts. Required from routes/api_sacco.php inside the v1 group. Staff only, with the same middleware as the rest of
| the SACCO API; ApiPermissionMap names each write's permission and the services check it again.
*/

use App\Http\Controllers\Api\V1\Sacco\YearEnd\AuditorExportDownloadController;
use App\Http\Controllers\Api\V1\Sacco\YearEnd\ReturnsController;
use App\Http\Controllers\Api\V1\Sacco\YearEnd\YearEndController;
use Illuminate\Support\Facades\Route;

// The auditor's ZIP behind a 30-minute signed link (AuditorExport::link): no session, the signature is the key.
Route::get('sacco/auditor-export-download', AuditorExportDownloadController::class)->middleware('throttle:10,1')->name('sacco.auditor.download');

Route::prefix('sacco')->middleware(['auth:sanctum', 'api.tenant', 'api.module:sacco', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('returns', [ReturnsController::class, 'index']);
    Route::get('returns/{id}', [ReturnsController::class, 'show'])->whereNumber('id');
    Route::post('returns/{id}/generate', [ReturnsController::class, 'generate'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('returns/{id}/mark-filed', [ReturnsController::class, 'markFiled'])->whereNumber('id');
    Route::get('returns/{id}/files/{format}', [ReturnsController::class, 'file'])->whereNumber('id')->whereIn('format', ['pdf', 'xlsx', 'csv']);
    Route::get('ratios', [ReturnsController::class, 'ratios']);
    Route::get('auditor-export', [ReturnsController::class, 'auditorExport'])->middleware('throttle:10,1');

    Route::get('interest-runs', [YearEndController::class, 'interestRuns']);
    Route::post('interest-runs/preview', [YearEndController::class, 'previewInterest']);
    Route::post('interest-runs', [YearEndController::class, 'requestInterest']);
    Route::get('year-end/{year}', [YearEndController::class, 'year'])->where('year', '\d{4}');
    Route::post('year-end/{year}/close', [YearEndController::class, 'close'])->where('year', '\d{4}');
    Route::post('year-end/{year}/appropriation/preview', [YearEndController::class, 'previewAppropriation'])->where('year', '\d{4}');
    Route::post('year-end/{year}/appropriate', [YearEndController::class, 'appropriate'])->where('year', '\d{4}');
    Route::get('dividend-runs', [YearEndController::class, 'dividendRuns']);
    Route::post('dividend-runs/preview', [YearEndController::class, 'previewDividend']);
    Route::post('dividend-runs', [YearEndController::class, 'requestDividend']);
    Route::get('dividend-runs/{id}', [YearEndController::class, 'dividendRun'])->whereNumber('id');
    Route::post('dividend-lines/{id}/pay', [YearEndController::class, 'payDividendLine'])->whereNumber('id');
    Route::get('members/{id}/stf-buyback', [YearEndController::class, 'previewBuyback'])->whereNumber('id');
    Route::post('members/{id}/stf-buyback', [YearEndController::class, 'requestBuyback'])->whereNumber('id');
    Route::get('dormancy', [YearEndController::class, 'dormancy']);
    Route::post('dormancy/advertised', [YearEndController::class, 'advertised']);
    Route::post('dormancy/{accountId}/transfer', [YearEndController::class, 'transfer'])->whereNumber('accountId');
});
