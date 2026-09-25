<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportFormat;
use App\Services\Reports\ReportService;
use App\Services\Team\Permissions;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** GET reports · GET reports/{name}?from&to&group_by&days&limit&format=json|pdf|xlsx (plan A6, P4-2). */
class ReportController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $out = [];
        foreach (ReportService::REPORTS as $name => [$title, $perm]) {
            if (Permissions::can($request->user(), $perm)) {
                $out[] = ['name' => $name, 'title' => $title];
            }
        }

        return $this->success(['reports' => $out, 'group_by' => ReportService::GROUPS], 'Reports.');
    }

    public function show(Request $request, ReportService $reports, string $name)
    {
        $perm = ReportService::REPORTS[$name][1] ?? null;
        if ($perm === null) {
            return $this->notFound('Unknown report.');
        }
        if (! Permissions::can($request->user(), $perm)) {
            return $this->error('Your role does not allow this report.', 403, ['code' => 'forbidden', 'permission' => $perm]);
        }
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'group_by' => ['nullable', 'string'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'], 'type' => ['nullable', 'string'], 'stock_item_id' => ['nullable', 'integer'], 'format' => ['nullable', 'in:json,pdf,xlsx']]);
        try {
            $report = $reports->run((int) $request->user()->company_id, $name, $data['from'] ?? null, $data['to'] ?? null, $data);
            if (($data['format'] ?? 'json') !== 'json') {
                [$bytes, $type, $file] = ReportFormat::render($report, $data['format']);

                return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$file.'"']);
            }
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($report, $report['title'].'.');
    }
}
