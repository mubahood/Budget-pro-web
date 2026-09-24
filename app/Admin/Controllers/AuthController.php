<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
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
