<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Services\Sacco\Reports\SaccoReportLinks;
use App\Services\Sacco\Reports\SaccoReports;
use App\Services\Sacco\SaccoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET sacco/reports and GET sacco/reports/{key}?from&to&date&member_id&format=json|pdf|xlsx|csv (04 §12, §13.2). */
class ReportController extends SaccoController
{
    public function index(Request $request): JsonResponse
    {
        $u = $this->user($request);
        $out = [];
        foreach (SaccoReports::catalog() as $key => $r) {
            if (SaccoAccess::can($u, $r['permission']) || SaccoAccess::can($u, 'sacco_audit_view')) {
                $out[] = ['key' => $key, 'title' => $r['title'], 'formats' => SaccoReports::FORMATS];
            }
        }

        return $this->success($out);
    }

    /** JSON for the screens; a file format answers with a 30-minute signed link to download. */
    public function show(Request $request, string $key): JsonResponse
    {
        $format = (string) $request->query('format', 'json');
        $params = $request->only(['from', 'to', 'date', 'member_id', 'account_type', 'account_id']);

        return $this->attempt(fn () => $format === 'json'
            ? $this->success((new SaccoReports())->build($this->company($request), $key, $params, $this->user($request)))
            : $this->success(SaccoReportLinks::make($this->company($request), $this->user($request), $key, $params, $format), 'Download ready.'));
    }
}
