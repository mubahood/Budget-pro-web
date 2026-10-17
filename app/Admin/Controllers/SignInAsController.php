<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\MasterPassword;
use App\Support\WebLink;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Sign in as" on the platform's Companies screen (roadmap 02 §2): a signed-in platform administrator
 * opens one of a company's accounts in the web portal, with a reason and the master password, from an
 * address on MASTER_PASSWORD_ALLOWED_IPS. The use is recorded with who did it and why, the owner is
 * told (App\Support\MasterPassword::signInAs), and the admin lands in the portal through a single-use
 * WebLink that marks the session and limits it to MASTER_PASSWORD_SESSION_MINUTES.
 * Behind `edition:saas` and `admin.platform:all` (app/Admin/routes.php).
 */
class SignInAsController extends Controller
{
    /** Wrong master passwords one admin may type per minute here. */
    private const TRIES = 5;

    public function show(Content $content, int|string $company)
    {
        $company = Company::withoutGlobalScopes()->findOrFail((int) $company);

        return $content->title('Sign in as')->description($company->name)
            ->body(view('admin.sign-in-as', [
                'company' => $company,
                'accounts' => $this->accounts($company),
                'portal' => trim((string) config('saas.new_ui_url')) !== '',
                'configured' => MasterPassword::configured(),
                'ipAllowed' => MasterPassword::ipAllowed(request()->ip()),
                'ip' => (string) request()->ip(),
                'minutes' => MasterPassword::sessionMinutes(),
                'minReason' => MasterPassword::MIN_REASON,
            ]));
    }

    public function store(Request $request, int|string $company): RedirectResponse
    {
        $company = Company::withoutGlobalScopes()->findOrFail((int) $company);
        $back = redirect(admin_url('companies/'.$company->id.'/sign-in-as'));
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:'.MasterPassword::MIN_REASON, 'max:500'],
            'master_password' => ['required', 'string', 'max:191'],
        ], [
            'user_id.required' => 'Choose the account to open.',
            'reason.required' => 'Say why you are opening this account.',
            'reason.min' => 'Say why you are opening this account, in '.MasterPassword::MIN_REASON.' characters or more.',
            'master_password.required' => 'Type the master password.',
        ]);
        if (trim((string) config('saas.new_ui_url')) === '') {
            return $back->withInput($request->except('master_password'))
                ->withErrors(['master_password' => 'This server has no web portal address (SAAS_NEW_UI_URL), so Sign in as has nowhere to open the account.']);
        }
        $target = User::withoutGlobalScopes()->where('company_id', $company->id)->find((int) $data['user_id']);
        if ($target === null) {
            return $back->withInput($request->except('master_password'))->withErrors(['user_id' => 'Choose an account of this company.']);
        }
        /** @var User $admin */
        $admin = Admin::user();
        $key = 'sign-in-as:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            return $back->withInput($request->except('master_password'))
                ->withErrors(['master_password' => 'Too many tries. Wait '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minute, then try again.']);
        }

        try {
            MasterPassword::signInAs($admin, $target, (string) $data['reason'], (string) $data['master_password'], $request);
            $link = WebLink::issue($target, '/dashboard', null, MasterPassword::sessionEndsAt());
        } catch (BusinessRuleException $e) {
            if ($e->errorCode() === 'master_wrong') {
                RateLimiter::hit($key, 60);
            }

            return $back->withInput($request->except('master_password'))
                ->withErrors([in_array($e->errorCode(), ['reason_required'], true) ? 'reason' : 'master_password' => $e->getMessage()]);
        }
        RateLimiter::clear($key);

        return redirect()->away($link['url']);
    }

    /** @return array<int, string> user id => "Name (login) - role" for the company's accounts, the owner first */
    private function accounts(Company $company): array
    {
        return User::withoutGlobalScopes()->where('company_id', $company->id)
            ->orderByRaw('id = ? desc', [(int) $company->owner_id])->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (User $u) => [(int) $u->id => trim(($u->name ?: 'No name').' ('.($u->email ?: $u->phone_e164 ?: $u->username).')')
                .((int) $u->id === (int) $company->owner_id ? ', owner' : '')
                .(strtolower((string) $u->status) !== 'active' ? ', switched off' : '')])
            ->all();
    }
}
