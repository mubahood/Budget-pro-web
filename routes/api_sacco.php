<?php

/*
| Akiba Ledger (the `sacco` module), API v1 (docs/akiba/04-architecture.md §13). Required from routes/api.php inside
| the v1 group, so every path here is /api/v1/…. Who may call what: ApiPermissionMap (writes) and the controllers
| (reads: sacco_view or the auditor's sacco_audit_view), with the finer rules in SaccoAccess and the services.
| Loans (P2) keep their routes in their own group.
*/

use App\Http\Controllers\Api\V1\Sacco\ReportDownloadController;
use Illuminate\Support\Facades\Route;

// A report file behind a 30-minute signed link (SaccoReportLinks): no session, the signature is the key.
Route::get('sacco/report-download', ReportDownloadController::class)->middleware('throttle:30,1')->name('sacco.report.download');
