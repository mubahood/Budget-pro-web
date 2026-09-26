<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use App\Support\Rules\TeamRules;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Team management (plan C5, P3-4). Guarded by `manage_team` in ApiPermissionMap. */
class TeamController extends Controller
{
    use ApiResponse;

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->user()->company_id);
    }

    public function index(Request $request)
    {
        $company = $this->company($request);
        $members = User::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'company_id', 'name', 'email', 'phone_e164', 'status', 'last_login_at'])
            ->map(fn ($u) => $u->toArray() + ['role' => Permissions::roleOf($u), 'is_owner' => (int) $company->owner_id === (int) $u->id]);
        $invites = DB::table('invites')->where('company_id', $company->id)->where('status', 'pending')->orderByDesc('id')
            ->get(['id', 'name', 'phone_e164', 'email', 'role', 'expires_at', 'sent_count', 'created_at']);

        return $this->success(['members' => $members, 'invites' => $invites, 'roles' => $this->roleList($company)], 'Team.');
    }

    private function roleList(Company $company): array
    {
        return TeamService::roleList($company);
    }

    public function roles(Request $request)
    {
        return $this->success(['roles' => $this->roleList($this->company($request)), 'permissions' => config('permissions.permissions')], 'Roles.');
    }

    /** PUT team/roles/{role} { permissions: [...] } — this company's version of a role. */
    public function updateRole(Request $request, string $role)
    {
        if ($role === 'owner' || ! array_key_exists($role, config('permissions.roles'))) {
            return $this->error('This role cannot be changed.', 422, ['code' => 'invalid_role']);
        }
        $data = $request->validate(TeamRules::rolePermissions());
        app(TeamService::class)->setRolePermissions($this->company($request), $role, $data['permissions']);

        return $this->roles($request);
    }

    public function invite(Request $request, TeamService $team)
    {
        $data = $request->validate(TeamRules::invite());
        try {
            $r = $team->invite($request->user(), $data['role'], $data['phone'] ?? null, $data['email'] ?? null, $data['name'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['invite' => $r['invite'], 'link' => $r['link']], 'Invite sent.');
    }

    public function resend(Request $request, TeamService $team, $id)
    {
        $invite = DB::table('invites')->where('company_id', $request->user()->company_id)->find($id);
        if (! $invite) {
            return $this->notFound('Invite not found.');
        }
        try {
            $link = $team->resend($invite, $request->user());
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['link' => $link], 'Invite sent again.');
    }

    public function revoke(Request $request, TeamService $team, $id)
    {
        return $team->revokeInvite($this->company($request), (int) $id) ? $this->success(null, 'Invite cancelled.') : $this->notFound('Invite not found.');
    }

    /** PATCH team/members/{id} { role?, active? } */
    public function updateMember(Request $request, TeamService $team, $userId)
    {
        $company = $this->company($request);
        $member = User::withoutGlobalScopes()->where('company_id', $company->id)->find($userId);
        if (! $member) {
            return $this->notFound('Member not found.');
        }
        $data = $request->validate(TeamRules::member());
        try {
            if (isset($data['role'])) {
                $team->setRole($company, $member, $data['role']);
            }
            if (array_key_exists('active', $data) && $data['active'] !== null) {
                $team->setActive($company, $member, (bool) $data['active']);
            }
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->index($request);
    }

    /** What a member did recently: sales, voids, returns, stock movements. */
    public function activity(Request $request, $userId)
    {
        $companyId = (int) $request->user()->company_id;
        if (! User::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($userId)->exists()) {
            return $this->notFound('Member not found.');
        }
        $sales = DB::table('sale_records')->where('company_id', $companyId)->where('created_by_id', $userId)->orderByDesc('id')->limit(30)
            ->get(['id', 'receipt_number', 'total_amount', 'status', 'created_at'])->map(fn ($s) => ['type' => 'sale', 'at' => $s->created_at, 'text' => "Sale {$s->receipt_number} · {$s->total_amount} · {$s->status}"]);
        $voids = DB::table('sale_records')->where('company_id', $companyId)->where('voided_by_id', $userId)->orderByDesc('voided_at')->limit(30)
            ->get(['receipt_number', 'voided_reason', 'voided_at'])->map(fn ($s) => ['type' => 'void', 'at' => $s->voided_at, 'text' => "Voided {$s->receipt_number}: {$s->voided_reason}"]);
        $moves = DB::table('stock_records')->where('company_id', $companyId)->where('created_by_id', $userId)->whereNotIn('type', ['Sale'])->orderByDesc('id')->limit(30)
            ->get(['type', 'name', 'quantity', 'reason', 'created_at'])->map(fn ($m) => ['type' => 'stock', 'at' => $m->created_at, 'text' => "{$m->type} {$m->quantity} × {$m->name}".($m->reason ? " ({$m->reason})" : '')]);
        $items = $sales->concat($voids)->concat($moves)->sortByDesc('at')->values()->take(60);

        return $this->success($items, 'Activity.');
    }

    public function transferOwnership(Request $request, TeamService $team)
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'password' => ['required', 'string']]);
        $company = $this->company($request);
        if ((int) $company->owner_id !== (int) $request->user()->id) {
            return $this->forbidden('Only the owner can hand over ownership.');
        }
        $to = User::withoutGlobalScopes()->find($data['user_id']);
        try {
            $team->transferOwnership($company, $request->user(), $to ?? new User(), $data['password']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(null, 'Ownership transferred.');
    }
}
