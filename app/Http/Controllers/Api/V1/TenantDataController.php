<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Ops\TenantDataService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shop's own data (plan Part D): GET company/data-requests · POST company/export ·
 * GET company/exports/{id}/download · POST company/delete { password } · POST company/delete/cancel
 */
class TenantDataController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantDataService $data)
    {
    }

    public function index(Request $request)
    {
        return $this->success(DB::table('data_requests')->where('company_id', $request->user()->company_id)->orderByDesc('id')->limit(20)->get(['id', 'kind', 'status', 'purge_after', 'completed_at', 'created_at']), 'Data requests.');
    }

    public function export(Request $request)
    {
        $id = $this->data->export((int) $request->user()->company_id, (int) $request->user()->id);

        return $this->created(DB::table('data_requests')->find($id), 'Your export is ready.');
    }

    public function download(Request $request, $id)
    {
        $r = DB::table('data_requests')->where('company_id', $request->user()->company_id)->where('kind', 'export')->find($id);
        $path = $r ? $this->data->exportPath($r) : null;
        if ($path === null) {
            return $this->notFound('This export is not available (exports are kept for 7 days).');
        }

        return response()->download($path, 'shop-data-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip']);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        try {
            $id = $this->data->requestDeletion(Company::withoutGlobalScopes()->findOrFail($request->user()->company_id), $request->user(), $data['password']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(DB::table('data_requests')->find($id), 'Deletion scheduled.');
    }

    public function cancelDelete(Request $request)
    {
        return $this->data->cancelDeletion((int) $request->user()->company_id) ? $this->success(null, 'Deletion cancelled.') : $this->notFound('No deletion is scheduled.');
    }
}
