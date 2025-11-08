<?php

namespace App\Admin\Controllers;

use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\User;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postRegister(Request $request)
    {
        // Start database transaction
        DB::beginTransaction();

        try {
            // Step 1: Validate user information
            $userValidator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:admin_users,email',
                'phone_number' => 'required|string|max:20',
                'password' => 'required|string|min:6|confirmed',
            ], [
                'first_name.required' => 'First name is required.',
                'last_name.required' => 'Last name is required.',
                'email.required' => 'Email address is required.',
                'email.email' => 'Please enter a valid email address.',
                'email.unique' => 'This email is already registered. Please login instead.',
                'phone_number.required' => 'Phone number is required.',
                'password.required' => 'Password is required.',
                'password.min' => 'Password must be at least 6 characters.',
                'password.confirmed' => 'Password confirmation does not match.',
            ]);

            if ($userValidator->fails()) {
                return redirect()->back()
                    ->withErrors($userValidator)
                    ->withInput($request->except('password', 'password_confirmation'));
            }

            // Step 2: Validate company information
            $companyValidator = Validator::make($request->all(), [
                'company_name' => 'required|string|max:255',
                'company_phone' => 'nullable|string|max:20',
                'company_address' => 'nullable|string|max:500',
                'currency' => 'required|string|in:UGX,USD,KES,TZS,RWF,EUR,GBP',
            ], [
                'company_name.required' => 'Company name is required.',
                'currency.required' => 'Please select a currency.',
                'currency.in' => 'Please select a valid currency.',
            ]);

            if ($companyValidator->fails()) {
                return redirect()->back()
                    ->withErrors($companyValidator)
                    ->withInput($request->except('password', 'password_confirmation'));
            }

            // Step 3: Create user with temporary company_id
            $user = new User();
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
            $user->name = trim($request->first_name . ' ' . $request->last_name);
            $user->username = $request->email;
            $user->email = $request->email;
            $user->phone_number = $request->phone_number;
            $user->password = Hash::make($request->password);
            $user->company_id = 1; // Temporary, will be updated after company creation
            $user->status = 'active';
            $user->avatar = null;
            $user->save();

            if (!$user->id) {
                throw new \Exception('Failed to create user account.');
            }

            // Step 4: Create company with the new user as owner
            $company = new Company();
            $company->owner_id = $user->id;
            $company->name = $request->company_name;
            $company->email = $request->email;
            $company->phone_number = $request->company_phone ?? $request->phone_number;
            $company->address = $request->company_address;
            $company->currency = $request->currency;
            $company->status = 'Active';
            $company->license_expire = now()->addYear(); // 1 year trial
            $company->save();

            if (!$company->id) {
                throw new \Exception('Failed to create company.');
            }

            // Step 5: Update user's company_id to the new company
            $user->company_id = $company->id;
            $user->save();

            // Step 6: Create default financial year
            $currentYear = now()->year;
            $financialPeriod = new FinancialPeriod();
            $financialPeriod->company_id = $company->id;
            $financialPeriod->name = "FY $currentYear";
            $financialPeriod->start_date = now()->startOfYear();
            $financialPeriod->end_date = now()->endOfYear();
            $financialPeriod->status = 'Active';
            $financialPeriod->description = 'Default financial year created during registration';
            $financialPeriod->total_investment = 0;
            $financialPeriod->total_sales = 0;
            $financialPeriod->total_profit = 0;
            $financialPeriod->total_expenses = 0;
            $financialPeriod->save();

            // Step 7: Assign Company Owner Role (ID 2)
            // This is critical - company owners MUST have role_id = 2
            $companyOwnerRoleId = 2;
            
            // Check if role ID 2 exists
            $companyOwnerRole = DB::table('admin_roles')->where('id', $companyOwnerRoleId)->first();
            if (!$companyOwnerRole) {
                // Fallback: try to find by slug if ID 2 doesn't exist
                $companyOwnerRole = DB::table('admin_roles')->where('slug', 'company-owner')->first();
                if ($companyOwnerRole) {
                    $companyOwnerRoleId = $companyOwnerRole->id;
                }
            }
            
            // Assign the company owner role
            DB::table('admin_role_users')->insert([
                'role_id' => $companyOwnerRoleId,
                'user_id' => $user->id,
            ]);
            
            Log::info('Company owner role assigned during registration', [
                'user_id' => $user->id,
                'role_id' => $companyOwnerRoleId,
                'company_id' => $company->id,
            ]);

            // Step 8: Log the registration
            Log::info('New user registered', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'email' => $user->email,
                'company_name' => $company->name,
            ]);

            // Commit transaction
            DB::commit();

            // Step 9: Automatically log in the user
            Auth::guard('admin')->login($user, true);

            // Redirect to dashboard with success message
            admin_toastr('Registration successful! Welcome to ' . config('admin.name'), 'success');
            return redirect()->intended($this->redirectPath());

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except('password', 'password_confirmation'),
            ]);

            return redirect()->back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
    }
}
