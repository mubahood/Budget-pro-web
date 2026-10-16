<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StockItem;
use App\Services\Shop\CustomerService;
use App\Services\Shop\CustomerSummary;
use App\Services\Shop\ProductSummary;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The phone's record pages (MOBILE_EXCELLENCE_PLAN.md D3, D4), by server id or uuid (a phone knows its
 * records by uuid before the id arrives). Another shop's record answers 404.
 *   GET customers/{id}/summary?from=Y-m-d     GET customers/{id}/statement.pdf?from=Y-m-d
 *   GET stock-items/{id}/summary
 */
class SummaryController extends Controller
{
    use ApiResponse;

    public function customer(Request $request, string $id)
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d']]);
        $customer = $this->find($request, Customer::class, $id);
        if ($customer === null) {
            return $this->notFound('Customer not found.');
        }
        $summary = app(CustomerSummary::class)->for($customer, $data['from'] ?? null);
        $summary['statement_pdf_url'] = url('/api/v1/customers/'.($customer->uuid ?: $customer->id).'/statement.pdf').'?'.http_build_query(['from' => $summary['statement']['from']]);

        return $this->success($summary, 'Customer summary.');
    }

    /** The statement as a PDF, the web customer page's layout; download it with the same bearer token. */
    public function statementPdf(Request $request, string $id)
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $customer = $this->find($request, Customer::class, $id);
        if ($customer === null) {
            return $this->notFound('Customer not found.');
        }
        $company = Company::withoutGlobalScopes()->findOrFail($customer->company_id);
        $statement = (new CustomerService)->statement($customer, $data['from'] ?? null, $data['to'] ?? null);
        $html = view('reports.customer-statement', [
            'shop' => $company, 'party' => $customer, 'statement' => $statement, 'from' => $data['from'] ?? null,
            'printedAt' => now()->setTimezone(\App\Support\LocalTime::timezone($company)),
        ])->render();
        $bytes = app('dompdf.wrapper')->loadHTML($html)->setPaper('a4')->output();
        $name = 'statement-'.(\Illuminate\Support\Str::slug((string) $customer->name) ?: $customer->id).'.pdf';

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"', 'Content-Length' => (string) strlen($bytes)]);
    }

    public function stockItem(Request $request, string $id)
    {
        $item = $this->find($request, StockItem::class, $id);
        if ($item === null) {
            return $this->notFound('Stock item not found.');
        }

        return $this->success(app(ProductSummary::class)->for($item, $request->user()), 'Product summary.');
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function find(Request $request, string $model, string $id)
    {
        return $model::withoutGlobalScopes()->where('company_id', (int) $request->user()->company_id)->where('is_deleted', 0)
            ->where(ctype_digit($id) ? 'id' : 'uuid', ctype_digit($id) ? (int) $id : $id)->first();
    }
}
