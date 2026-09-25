<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** GET members — active people in the shop, for pickers (treasurer, task assignee). Any member may read it (v1 replacement for mobile/list/User, P4-8). */
class MemberController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $rows = User::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Inactive'))
            ->orderBy('name')->get(['id', 'company_id', 'name', 'first_name', 'last_name', 'phone_number', 'phone_e164', 'email', 'avatar'])
            ->map(fn ($u) => $u->toArray() + ['role' => Permissions::roleOf($u)]);

        return $this->success($rows, 'Members.');
    }
}
