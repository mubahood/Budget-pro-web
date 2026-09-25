<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Team\TeamService;
use Illuminate\Http\Request;

/** The page an invite link opens in a browser (plan C5): join, then sign in on web or the app. */
class InvitePageController extends Controller
{
    public function show(string $token)
    {
        $invite = TeamService::findOpen($token);

        return view('invite.accept', [
            'invite' => $invite, 'token' => $token, 'done' => false, 'error' => null,
            'company' => $invite ? Company::withoutGlobalScopes()->find($invite->company_id)?->name : null,
            'roleLabel' => $invite ? config("permissions.roles.{$invite->role}.label") : null,
        ]);
    }

    public function accept(Request $request, TeamService $team, string $token)
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed']]);
        $invite = TeamService::findOpen($token);
        try {
            $user = $team->accept($token, $data['first_name'], $data['last_name'], $data['password']);
        } catch (BusinessRuleException $e) {
            return view('invite.accept', ['invite' => $invite, 'token' => $token, 'done' => false, 'error' => $e->getMessage(),
                'company' => $invite ? Company::withoutGlobalScopes()->find($invite->company_id)?->name : null, 'roleLabel' => $invite ? config("permissions.roles.{$invite->role}.label") : null]);
        }

        return view('invite.accept', ['invite' => $invite, 'token' => $token, 'done' => true, 'error' => null, 'login' => $user->phone_e164 ?: $user->email,
            'company' => Company::withoutGlobalScopes()->find($user->company_id)?->name, 'roleLabel' => $invite ? config("permissions.roles.{$invite->role}.label") : null]);
    }
}
