<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Services\Team\TeamService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** Public invite endpoints (plan C5): the app opens the link, shows the shop, and accepts. */
class InviteController extends Controller
{
    use ApiResponse;

    public function show(string $token)
    {
        $invite = TeamService::findOpen($token);
        if (! $invite) {
            return $this->error('This invite link has expired or was already used.', 404, ['code' => 'invite_invalid']);
        }
        $company = Company::withoutGlobalScopes()->find($invite->company_id);

        return $this->success(['company' => $company?->name, 'role' => $invite->role, 'role_label' => config("permissions.roles.{$invite->role}.label"),
            'name' => $invite->name, 'phone' => $invite->phone_e164, 'email' => $invite->email, 'expires_at' => $invite->expires_at], 'Invite.');
    }

    public function accept(Request $request, TeamService $team, string $token)
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'min:6', 'max:100'], 'device_name' => ['nullable', 'string', 'max:100']]);
        try {
            $user = $team->accept($token, $data['first_name'], $data['last_name'], $data['password']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $token = $user->createToken($data['device_name'] ?? 'invite', ['*'], now()->addMinutes((int) config('sanctum.expiration')))->plainTextToken;

        return $this->created(['token' => $token, 'token_type' => 'Bearer', 'user' => new UserResource($user), 'company' => new CompanyResource(Company::withoutGlobalScopes()->find($user->company_id))], 'Welcome to the team!');
    }
}
