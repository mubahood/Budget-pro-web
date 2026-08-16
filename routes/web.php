<?php

use App\Admin\Controllers\AuthController;
use App\Http\Controllers\ApiController;
use App\Models\BudgetProgram;
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

        $storePath = public_path('storage/files/budget-'.$rep->id.'.pdf');
        file_put_contents($storePath, $pdf->output());
        $rep->file = 'files/budget-'.$rep->id.'.pdf';
        $rep->saveQuietly();

        return $pdf->stream();
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
