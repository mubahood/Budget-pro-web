<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportFormat;
use App\Services\Reports\ReportService;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;

/** Reports on the web (plan A6, P4-2): the same ReportService as the API, with PDF and Excel downloads. */
class ReportController extends Controller
{
    public function index(Content $content, Request $request, ReportService $reports)
    {
        $available = array_filter(ReportService::REPORTS, fn ($r) => Permissions::can(Admin::user(), $r[1]));
        $name = $request->query('report', array_key_first($available) ?? 'sales_summary');
        $report = null;
        $error = null;
        if (isset($available[$name])) {
            try {
                $report = $reports->run((int) Admin::user()->company_id, $name, $request->query('from'), $request->query('to'), $request->query());
                if (in_array($request->query('format'), ['pdf', 'xlsx'], true)) {
                    [$bytes, $type, $file] = ReportFormat::render($report, $request->query('format'));

                    return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$file.'"']);
                }
            } catch (BusinessRuleException $e) {
                $error = $e->getMessage();
            }
        }

        return $content->title('Reports')->body(view('admin.reports', ['available' => $available, 'name' => $name, 'report' => $report, 'error' => $error, 'groups' => ReportService::GROUPS]));
    }
}
