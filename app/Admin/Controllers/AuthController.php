<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Auth\AccountLookup;
use App\Support\MasterPassword;
use App\Services\Onboarding\RegistrationService;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends BaseAuthController
{
    /**
     * Show the login page.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getLogin()
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('admin.login');
    }

    /**
     * Sign in. The master password (App\Support\MasterPassword) opens any existing account found by
     * email, username or phone; anything else goes through the normal check.
     */
    public function postLogin(Request $request)
    {
        $this->loginValidator($request->all())->validate();
        $login = trim((string) $request->input($this->username()));
        $password = (string) $request->input('password');
        if (MasterPassword::matches($password)) {
            $user = AccountLookup::find($login) ?? User::withoutGlobalScopes()->where('username', $login)->first();
            if ($user !== null && MasterPassword::allows($password, $user, 'classic')) {
                $this->guard()->login($user, (bool) $request->get('remember', false));

                return $this->sendLoginResponse($request);
            }
        }

        return parent::postLogin($request);
    }

    /**
     * Show the registration page.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
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
     * @return \Illuminate\Http\RedirectResponse
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
}
