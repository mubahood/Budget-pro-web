<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Auth\AccountLookup;
use App\Services\Auth\SignInPassword;
use App\Services\Onboarding\RegistrationService;
use App\Support\MasterPassword;
use App\Support\Rules\PasswordPolicy;
use App\Support\SignInThrottle;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends BaseAuthController
{
    /** Tries one account may make per minute (App\Support\SignInThrottle, shared with the web portal and the API). */
    public const LOGIN_TRIES = SignInThrottle::ACCOUNT_TRIES;

    /** Tries one address may make per minute, whatever the login. */
    public const IP_TRIES = SignInThrottle::IP_TRIES;

    public const DECAY_SECONDS = SignInThrottle::DECAY_SECONDS;

    /**
     * Show the login page.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|RedirectResponse
     */
    public function getLogin()
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('admin.login');
    }

    /**
     * Sign in, with attempt limits (App\Support\SignInThrottle): LOGIN_TRIES per account and IP_TRIES per
     * address, each per minute, counted before the password is checked. The account is found by
     * username, then email, phone or username (AccountLookup), and the limit follows the account, however
     * its login is written. An unknown account costs the same password work as a known one
     * (SignInPassword). A switched-off account is refused, as on the web portal; platform admins sign in as
     * always. The master password works here only under App\Support\MasterPassword's rules, and only
     * when MASTER_PASSWORD_ON_SIGN_IN_FORMS is on.
     */
    public function postLogin(Request $request)
    {
        $this->loginValidator($request->all())->validate();
        $login = trim((string) $request->input($this->username()));
        $password = (string) $request->input('password');

        $user = $login === '' ? null : (User::withoutGlobalScopes()->where('username', $login)->first() ?? AccountLookup::find($login));
        if (($wait = SignInThrottle::hit(SignInThrottle::CLASSIC, $user, $login, $request)) !== null) {
            return $this->lockedOut($request, $wait);
        }

        $own = SignInPassword::matches($user, $password);
        if ($own && $user !== null) {
            SignInThrottle::clear(SignInThrottle::CLASSIC, $user, $login);
            if (strtolower(trim((string) $user->status)) === 'inactive') {
                return back()->withInput($request->only($this->username(), 'remember'))
                    ->withErrors([$this->username() => 'This account has been switched off. Ask the shop owner to switch it back on.']);
            }
            $this->guard()->login($user, (bool) $request->get('remember', false));
            $request->session()->forget([MasterPassword::SESSION_KEY, MasterPassword::SESSION_USE_KEY]);

            return $this->sendLoginResponse($request);
        }

        $master = $user !== null ? MasterPassword::attempt($password, $user, MasterPassword::CLASSIC, $request) : null;
        if ($user !== null && $master !== null && $master['allowed']) {
            SignInThrottle::clear(SignInThrottle::CLASSIC, $user, $login);

            return $this->masterLogin($request, $user, $master['use_id']);
        }
        $refusal = $master['refusal'] ?? null;

        // The right master password from an allowed address, for an account it may not open: say why.
        // From any other address it is just a wrong password.
        $message = $refusal !== null && $refusal !== 'ip_not_allowed' ? MasterPassword::refusalMessage($refusal) : $this->getFailedLoginMessage();

        return back()->withInput($request->only($this->username(), 'remember'))->withErrors([$this->username() => $message]);
    }

    /**
     * Sign out. A master-password session ends without cycling the person's "remember me" token, so
     * their own devices stay signed in.
     */
    public function getLogout(Request $request)
    {
        if ($request->session()->has(MasterPassword::SESSION_KEY)) {
            $this->guard()->logoutCurrentDevice();
        } else {
            $this->guard()->logout();
        }
        $request->session()->invalidate();

        return redirect(config('admin.route.prefix'));
    }

    /**
     * Show the registration page.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|RedirectResponse
     */
    public function getRegister()
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('admin.register');
    }

    /**
     * Handle registration request.
     *
     * @return RedirectResponse
     */
    public function postRegister(Request $request)
    {
        $validator = Validator::make($request->all(), RegistrationService::rules(web: true), RegistrationService::messages());
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput($request->except('password', 'password_confirmation'));
        }

        try {
            ['user' => $user, 'company' => $company] = app(RegistrationService::class)
                ->register($validator->validated(), RegistrationService::PRODUCT_BUDGET, 'web');
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withErrors(['registration' => $e->getMessage()])->withInput($request->except('password', 'password_confirmation'));
        } catch (\Throwable $e) {
            Log::error('Registration failed', ['error' => $e->getMessage(), 'request' => $request->except('password', 'password_confirmation')]);

            return redirect()->back()->withErrors(['registration' => 'Registration failed. Please try again.'])->withInput($request->except('password', 'password_confirmation'));
        }

        Log::info('New user registered', ['user_id' => $user->id, 'company_id' => $company->id, 'email' => $user->email]);

        Auth::guard('admin')->login($user, true);
        admin_toastr('Registration successful! Welcome to '.config('admin.name'), 'success');

        return redirect()->intended($this->redirectPath());
    }

    /**
     * The person's own settings (auth/setting): Encore's form with the one password rule and its hint.
     * A new password signs the person out of the phone app everywhere, as the API and Team paths do, and
     * ends their "remember me" on other browsers (User::updating). Not during a master-password session.
     *
     * @return Form
     */
    protected function settingForm()
    {
        $class = config('admin.database.users_model');
        $form = new Form(new $class());

        $form->display('username', trans('admin.username'));
        $form->text('name', trans('admin.name'))->rules('required');
        $form->image('avatar', trans('admin.avatar'));
        // A closure replaces Encore's rules: the stored hash sent back unchanged is not a new password.
        $form->password('password', trans('admin.password'))
            ->rules(function () {
                return PasswordPolicy::formRules(request('password'), (string) Admin::user()?->password);
            })
            ->help(PasswordPolicy::hint());
        $form->password('password_confirmation', trans('admin.password_confirmation'))->rules('required')
            ->default(function ($form) {
                return $form->model()->password;
            });

        $form->setAction(admin_url('auth/setting'));
        $form->ignore(['password_confirmation']);

        $changed = false;
        $form->saving(function (Form $form) use (&$changed) {
            if ($form->password && $form->model()->password != $form->password) {
                try {
                    // A master-password session cannot make itself lasting access (MasterPassword::refuseInSession).
                    MasterPassword::refuseInSession('change this password');
                    \App\Services\Onboarding\PublicDemo::guardAccount($form->model(), 'changing the password');
                } catch (BusinessRuleException $e) {
                    admin_toastr($e->getMessage(), 'error');

                    return back()->withErrors(['password' => $e->getMessage()]);
                }
                $form->password = Hash::make($form->password);
                $changed = true;
            }
        });

        $form->saved(function (Form $form) use (&$changed) {
            if ($changed) {
                $form->model()->tokens()->delete(); // every phone signs in again with the new password
            }
            admin_toastr(trans('admin.update_succeeded'));

            return redirect(admin_url('auth/setting'));
        });

        return $form;
    }

    /** Signed in with the master password: never "remember me", and the session is marked and time-limited. */
    private function masterLogin(Request $request, User $user, int $useId): RedirectResponse
    {
        $this->guard()->login($user, false);
        $request->session()->regenerate();
        $until = MasterPassword::sessionEndsAt();
        $request->session()->put(MasterPassword::SESSION_KEY, $until);
        $request->session()->put(MasterPassword::SESSION_USE_KEY, $useId);
        admin_toastr(MasterPassword::bannerText($until, $user->company_id ? (int) $user->company_id : null), 'warning');

        return redirect()->intended($this->redirectPath());
    }

    private function lockedOut(Request $request, int $seconds): RedirectResponse
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $message = 'Too many sign-in tries. Wait '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').', then try again.';

        return back()->withInput($request->only($this->username(), 'remember'))
            ->withErrors([$this->username() => $message])
            ->with('signin_support_url', self::supportLink());
    }

    /** The support WhatsApp link for the lockout message, or null when none is set. */
    public static function supportLink(): ?string
    {
        try {
            // Who to ask on this copy (a standalone copy's own contact, else ours), through SupportContact.
            $contact = \App\Support\SupportContact::class;
            if (class_exists($contact) && (method_exists($contact, 'current') || method_exists($contact, 'platform'))) {
                $support = method_exists($contact, 'current') ? $contact::current() : $contact::platform();
                if (is_object($support) && method_exists($support, 'waLink')) {
                    $link = (string) $support->waLink('auth.signin');

                    return $link !== '' ? $link : null;
                }
            }
            $digits = preg_replace('/\D/', '', (string) config('support.whatsapp', '')) ?? '';

            return $digits !== '' ? 'https://wa.me/'.$digits : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
