<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Reports\ListExports;
use App\Services\Reports\XlsxWriter;
use App\Support\LocalDate;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * GET exports/{sales|products|customers}?format=csv|xlsx&from=&to=&… (MOBILE_EXCELLENCE_PLAN.md D6): the web
 * portal's list exports as a file (ListExports). Sales need view_reports; products manage_products or
 * view_reports (costs only with view_cost); customers sell or view_reports. Sales default to the last 30 days.
 */
class ExportController extends Controller
{
    use ApiResponse;

    public function show(Request $request, string $kind)
    {
        if (! in_array($kind, ListExports::KINDS, true)) {
            return $this->notFound('Unknown export. Use sales, products or customers.');
        }
        $f = $request->validate([
            'format' => ['nullable', 'in:csv,xlsx'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:Completed,Partially Refunded,Refunded,Voided'],
            'scope' => ['nullable', 'in:all,active,inactive,low,out,owes,unpaid,paid'],
            'sold_by' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        if (! ListExports::allowed($user, $kind)) {
            $perms = ListExports::PERMISSIONS[$kind];

            return $this->error('Your role does not allow this. Ask the shop owner.', 403, ['code' => 'forbidden', 'permission' => $perms[0]] + (count($perms) > 1 ? ['any_of' => $perms] : []));
        }
        $company = $request->attributes->get('company') ?? Company::withoutGlobalScopes()->findOrFail($user->company_id);
        $format = $f['format'] ?? 'csv';
        [$from, $to] = ListExports::period($kind, (int) $company->id, $f);
        if ($from !== null && $to !== null && $from > $to) {
            return $this->validationError(['to' => ['The end date must be on or after the start date.']]);
        }
        $stamp = $kind === 'sales' ? $from.'-to-'.$to : LocalDate::today((int) $company->id)->format('Y-m-d');
        $name = $kind.'-'.$stamp.'.'.$format;
        $rows = app(ListExports::class)->rows($kind, $user, $company, $f);

        if ($format === 'xlsx') {
            $bytes = XlsxWriter::build(ucfirst($kind), iterator_to_array($rows, false));

            return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Content-Length' => (string) strlen($bytes)]);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel opens UTF-8 names correctly
            // A cell starting with = + - @ would run as a formula in a spreadsheet: quote it.
            $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
            foreach ($rows as $row) {
                fputcsv($out, array_map($safe, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
