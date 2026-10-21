<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Sacco\Reports\SaccoReportFormat;
use App\Services\Sacco\Reports\SaccoReports;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET sacco/report-download (signed, no session): the file behind a link from SaccoReportLinks. The signature, the
 * company and the person's permission are checked on every open; an expired or altered link answers 403.
 */
class ReportDownloadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return response()->json(['code' => 0, 'message' => 'This link has expired. Ask for the report again.', 'data' => null, 'errors' => ['code' => 'link_expired']], 403);
        }
        $user = User::withoutGlobalScopes()->find((int) $request->query('user_id'));
        $company = Company::withoutGlobalScopes()->find((int) $request->query('company_id'));
        if ($user === null || $company === null || (int) $user->company_id !== (int) $company->id) {
            return response()->json(['code' => 0, 'message' => 'Not found.', 'data' => null], 404);
        }
        try {
            $report = (new SaccoReports())->build($company, (string) $request->query('key'), $request->query(), $user);
            [$bytes, $type, $name] = SaccoReportFormat::render($report, (string) $request->query('format'));
        } catch (BusinessRuleException $e) {
            return response()->json(['code' => 0, 'message' => $e->getMessage(), 'data' => null, 'errors' => $e->toErrors()], $e->errorCode() === 'forbidden' ? 403 : 422);
        }

        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
    }
}
