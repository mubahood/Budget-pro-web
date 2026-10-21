<?php

namespace App\Http\Controllers\Api\V1\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Sacco\Reports\AuditorExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** GET sacco/auditor-export-download (signed, no session): the auditor's ZIP behind a link from AuditorExport::link. */
class AuditorExportDownloadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return response()->json(['code' => 0, 'message' => 'This link has expired. Ask for the export again.', 'data' => null, 'errors' => ['code' => 'link_expired']], 403);
        }
        $user = User::withoutGlobalScopes()->find((int) $request->query('user_id'));
        $company = Company::withoutGlobalScopes()->find((int) $request->query('company_id'));
        if ($user === null || $company === null || (int) $user->company_id !== (int) $company->id) {
            return response()->json(['code' => 0, 'message' => 'Not found.', 'data' => null], 404);
        }
        try {
            [$bytes, $type, $name] = (new AuditorExport())->build($company, (string) $request->query('from'), (string) $request->query('to'), $user);
        } catch (BusinessRuleException $e) {
            return response()->json(['code' => 0, 'message' => $e->getMessage(), 'data' => null, 'errors' => $e->toErrors()], $e->errorCode() === 'forbidden' ? 403 : 422);
        }

        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
    }
}
