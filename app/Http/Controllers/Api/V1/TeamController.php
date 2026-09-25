<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
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
        $out = [];
        foreach (config('permissions.roles') as $key => $r) {
            $perms = Permissions::defaultsFor($key);
            foreach (DB::table('company_role_permissions')->where('company_id', $company->id)->where('role', $key)->get() as $o) {
                $perms = $o->allowed ? array_values(array_unique([...$perms, $o->permission])) : array_values(array_diff($perms, [$o->permission]));
            }
            $out[] = ['key' => $key, 'label' => $r['label'], 'permissions' => $key === 'owner' ? Permissions::all() : $perms];
        }

        return $out;
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
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['in:'.implode(',', Permissions::all())]]);
        $company = $this->company($request);
        $defaults = Permissions::defaultsFor($role);
        $wanted = array_diff($data['permissions'], ['billing', 'manage_team', 'manage_settings']); // owner-only stays owner-only
        DB::table('company_role_permissions')->where('company_id', $company->id)->where('role', $role)->delete();
        foreach (Permissions::all() as $p) {
            $want = in_array($p, $wanted, true);
            if ($want !== in_array($p, $defaults, true)) {
                DB::table('company_role_permissions')->insert(['company_id' => $company->id, 'role' => $role, 'permission' => $p, 'allowed' => $want, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Permissions::flush();

        return $this->roles($request);
    }

    public function invite(Request $request, TeamService $team)
    {
        $data = $request->validate(['role' => ['required', 'string'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'name' => ['nullable', 'string', 'max:150']]);
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

    public function revoke(Request $request, $id)
    {
        $n = DB::table('invites')->where('company_id', $request->user()->company_id)->where('id', $id)->where('status', 'pending')->update(['status' => 'revoked', 'updated_at' => now()]);

        return $n ? $this->success(null, 'Invite cancelled.') : $this->notFound('Invite not found.');
    }

    /** PATCH team/members/{id} { role?, active? } */
    public function updateMember(Request $request, TeamService $team, $userId)
    {
        $company = $this->company($request);
        $member = User::withoutGlobalScopes()->where('company_id', $company->id)->find($userId);
        if (! $member) {
            return $this->notFound('Member not found.');
        }
        $data = $request->validate(['role' => ['nullable', 'string'], 'active' => ['nullable', 'boolean']]);
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
