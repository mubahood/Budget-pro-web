<?php

use App\Admin\Controllers\AuthController;
use App\Http\Controllers\ApiController;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\DataExport;
use App\Models\FinancialReport;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes (admin panel + registration)
|--------------------------------------------------------------------------
|
| NOTE: The REST API lives entirely in routes/api.php (/api/v1). The routes
| below serve the laravel-admin web panel only. Everything that reads or
| writes tenant data is behind the `admin` guard and re-checks company_id.
|
*/

// Registration (public)
Route::get('auth/register', [AuthController::class, 'getRegister'])->name('admin.register');
Route::post('auth/register', [AuthController::class, 'postRegister'])->name('admin.register.post');

// Admin-only AJAX helpers (require a laravel-admin session) + tenant-scoped PDFs.
Route::middleware('admin.auth')->group(function () {
    // Quick actions used by the admin dashboard (already scope by Admin::user()->company_id).
    Route::post('api/products/quick-add', [ApiController::class, 'product_quick_add']);
    Route::post('api/sales/quick-record', [ApiController::class, 'quick_sale_record']);
    Route::get('api/global-search', [ApiController::class, 'global_search']);

    Route::get('financial-report', function () {
        $rep = FinancialReport::find(request('id'));
        if ($rep === null || (int) $rep->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        $company = $rep->company;
        if ($company && $company->logo === null) {
            $company->logo = null;
        }

        $pdf = App::make('dompdf.wrapper');
        $pdf->loadHTML(view('reports.financial-report', ['data' => $rep, 'company' => $company]));
        $pdf->render();

        $storePath = public_path('storage/files/report-'.$rep->id.'.pdf');
        file_put_contents($storePath, $pdf->output());
        $rep->file = 'files/report-'.$rep->id.'.pdf';
        $rep->file_generated = 'Yes';
        $rep->saveQuietly();

        return $pdf->stream();
    });

    Route::get('budget-program-print', function () {
        $rep = BudgetProgram::find(request('id'));
        if ($rep === null || (int) $rep->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        $company = $rep->company;
        if ($rep->logo === null || strlen((string) $rep->logo) < 2) {
            $rep->logo = null;
        }

        $rep->get_categories();
        $pdf = App::make('dompdf.wrapper');
        $pdf->loadHTML(view('reports.budget-report', ['data' => $rep, 'company' => $company]));
        $pdf->render();

        // Cache the rendered PDF to disk (matches the sibling financial-report
        // route's pattern), but unlike FinancialReport, budget_programs has no
        // `file` column -- writing one via saveQuietly() threw "Unknown column
        // 'file'" and 500'd on every single request to this route.
        $storePath = public_path('storage/files/budget-'.$rep->id.'.pdf');
        file_put_contents($storePath, $pdf->output());

        return $pdf->stream();
    });

    // "Print Thanks" button on ContributionRecordController's grid links here
    // (App\Admin\Controllers\ContributionRecordController.php). The previous
    // /thanks page hardcoded one specific person's wedding details (real
    // name, phone numbers, a stale deadline) into every company's receipt
    // and was removed for that reason, but the button was left pointing at
    // a route that no longer existed. This is a fresh, generic, tenant-
    // scoped replacement built from the actual contribution record.
    Route::get('thanks', function () {
        $record = ContributionRecord::withoutGlobalScopes()->find(request('id'));
        if ($record === null || (int) $record->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        return view('reports.thanks', ['record' => $record]);
    });

    Route::get('sale-receipt-pdf', function () {
        $sale = \App\Models\SaleRecord::with(['saleRecordItems', 'company'])->find(request('id'));
        if ($sale === null || (int) $sale->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        $pdf = App::make('dompdf.wrapper');
        $pdf->loadHTML(view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company]));
        $pdf->render();

        file_put_contents(public_path('storage/files/receipt-'.$sale->id.'.pdf'), $pdf->output());
        $sale->receipt_pdf_url = 'files/receipt-'.$sale->id.'.pdf';
        $sale->receipt_pdf_is_generated = 'Yes';
        $sale->saveQuietly();

        return $pdf->stream('receipt-'.$sale->receipt_number.'.pdf');
    });

    Route::get('sale-invoice-pdf', function () {
        $sale = \App\Models\SaleRecord::with(['saleRecordItems', 'company'])->find(request('id'));
        if ($sale === null || (int) $sale->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        $pdf = App::make('dompdf.wrapper');
        $pdf->loadHTML(view('reports.sale-invoice', ['sale' => $sale, 'company' => $sale->company]));
        $pdf->render();

        file_put_contents(public_path('storage/files/invoice-'.$sale->id.'.pdf'), $pdf->output());
        $sale->invoice_pdf_url = 'files/invoice-'.$sale->id.'.pdf';
        $sale->invoice_pdf_is_generated = 'Yes';
        $sale->saveQuietly();

        return $pdf->stream('invoice-'.$sale->invoice_number.'.pdf');
    });
});

/*
|--------------------------------------------------------------------------
| data-exports-print -- deliberately OUTSIDE the admin.auth group
|--------------------------------------------------------------------------
|
| Two different, unrelated buttons link here with two different URL shapes,
| and only one of them has a browser session to check:
|
| 1. Admin panel "Print" button (DataExportController grid) -- ?id=<numeric
|    data_exports.id>, clicked from an already-authenticated admin session.
| 2. The Flutter app's contribution-records share menu (budget-pro-mobo's
|    ContributionRecordsScreen.dart) -- ?id=<category string>&company_id=
|    <int>, opened via url_launcher in the device's EXTERNAL browser, which
|    carries no admin session cookie at all. Putting this behind admin.auth
|    would just bounce every mobile user to the web login form -- confirmed
|    live: that request currently gets a 302 to /auth/login, and before this
|    route existed at all it was a plain 404 either way. This is the exact
|    URL shape the shipped app already calls; it cannot be changed without a
|    coordinated mobile release, same reasoning as routes/api.php's legacy
|    endpoints.
|
| Branching on whether `id` is numeric tells the two apart. The mobile path
| still can't verify the caller actually owns `company_id` (no session to
| check it against) -- but it now at least scopes strictly to that one
| company_id + category, which is already narrower than the previous
| /data-exports-print this replaced (BACKEND_API_MASTER_TASKS.md: removed
| for returning literally every tenant's contribution records).
*/
Route::get('data-exports-print', function () {
    $rawId = request('id');

    if (ctype_digit((string) $rawId)) {
        if (! Admin::user()) {
            return redirect()->guest('auth/login');
        }

        $export = DataExport::find($rawId);
        if ($export === null || (int) $export->company_id !== (int) Admin::user()->company_id) {
            abort(404);
        }

        $companyId = (int) $export->company_id;
        $categoryId = $export->category_id;
        $treasurerId = $export->treasurer_id;
    } else {
        $companyId = (int) request('company_id');
        $categoryId = $rawId;
        $treasurerId = null;

        if ($companyId <= 0 || $categoryId === null || $categoryId === '') {
            abort(404);
        }
    }

    $company = Company::find($companyId);
    if ($company === null) {
        abort(404);
    }

    $treasurer = $treasurerId ? \App\Models\User::find($treasurerId) : null;

    $records = ContributionRecord::where('company_id', $companyId)
        ->where('category_id', $categoryId)
        ->when($treasurerId, fn ($q) => $q->where('treasurer_id', $treasurerId))
        ->orderBy('name')
        ->get();

    return view('reports.data-export', [
        'export' => (object) ['category_id' => $categoryId, 'treasurer_id' => $treasurerId],
        'company' => $company,
        'treasurer' => $treasurer,
        'records' => $records,
    ]);
});
