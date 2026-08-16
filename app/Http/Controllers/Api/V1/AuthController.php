<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Register a new company + owner, start a trial subscription, and issue an API token.
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        try {
            $result = DB::transaction(function () use ($data) {
                // 1. Create the owner user (company_id backfilled by the Company::created hook).
                $user = new User();
                $user->first_name = $data['first_name'];
                $user->last_name = $data['last_name'];
                $user->name = trim($data['first_name'].' '.$data['last_name']);
                $user->username = $data['email'];
                $user->email = $data['email'];
                $user->phone_number = $data['phone_number'] ?? null;
                $user->password = Hash::make($data['password']);
                $user->status = 'Active';
                $user->save();

                // 2. Create the company (its created hook sets owner->company_id,
                //    assigns the owner role, and seeds account categories).
                $company = new Company();
                $company->owner_id = $user->id;
                $company->name = $data['company_name'];
                $company->email = $data['email'];
                $company->phone_number = $data['phone_number'] ?? null;
                $company->status = 'Active';
                $company->currency = $data['currency'];
                $company->license_expire = now()->addDays((int) config('saas.trial_days', 14));
                $company->save();

                // 3. Start a trial subscription on the default plan.
                $plan = Plan::where('slug', config('saas.default_plan', 'trial'))->first();
                Subscription::create([
                    'company_id' => $company->id,
                    'plan_id' => $plan?->id,
                    'status' => 'trialing',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays((int) config('saas.trial_days', 14)),
                    'ends_at' => now()->addDays((int) config('saas.trial_days', 14)),
                    'provider' => 'trial',
                ]);

                // 4. Create a default active financial period for the current year.
                $this->createDefaultFinancialPeriod($company->id);

                // 5. Re-fetch the user so company_id reflects the hook's back-fill.
                $user->refresh();

                return [$user, $company];
            });
        } catch (\Throwable $e) {
            Log::error('API registration failed', ['error' => $e->getMessage()]);

            return $this->error('Registration failed. Please try again.', 500);
        }

        [$user, $company] = $result;

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return $this->created([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'company' => new CompanyResource($company),
        ], 'Registration successful.');
    }

    /**
     * Authenticate and issue an API token.
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (strtolower((string) $user->status) === 'inactive') {
            return $this->forbidden('Your account has been deactivated. Please contact your administrator.');
        }

        $company = Company::find($user->company_id);
        if ($company === null) {
            return $this->error('Your account is not linked to a company.', 403);
        }

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'company' => new CompanyResource($company),
        ], 'Login successful.');
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * Revoke all of the user's tokens (log out everywhere).
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return $this->success(null, 'Logged out of all devices.');
    }

    /**
     * The authenticated user profile + company + roles + subscription (manifest).
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $company = $request->attributes->get('company') ?? Company::find($user->company_id);

        $roles = DB::table('admin_role_users')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')
            ->where('admin_role_users.user_id', $user->id)
            ->get(['admin_roles.id', 'admin_roles.name', 'admin_roles.slug']);

        $subscription = $company?->subscription;

        return $this->success([
            'user' => new UserResource($user),
            'company' => $company ? new CompanyResource($company) : null,
            'roles' => $roles,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'price', 'currency', 'interval', 'features', 'limits']),
                'trial_ends_at' => optional($subscription->trial_ends_at)->toIso8601String(),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
        ], 'Profile loaded.');
    }

    /**
     * Change the authenticated user's password (revokes other tokens).
     */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->error('Your current password is incorrect.', 422);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        // Revoke all other tokens for security, keep the current session token.
        $currentId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentId)->delete();

        return $this->success(null, 'Password updated successfully.');
    }

    private function deviceName(Request $request): string
    {
        return (string) ($request->input('device_name') ?: $request->userAgent() ?: 'api-token');
    }

    private function createDefaultFinancialPeriod(int $companyId): void
    {
        $exists = FinancialPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'Active')
            ->exists();

        if ($exists) {
            return;
        }

        $period = new FinancialPeriod();
        $period->company_id = $companyId;
        $period->name = 'FY '.date('Y');
        $period->start_date = now()->startOfYear();
        $period->end_date = now()->endOfYear();
        $period->status = 'Active';
        $period->description = 'Default financial year created during registration';
        $period->total_investment = 0;
        $period->total_sales = 0;
        $period->total_profit = 0;
        $period->total_expenses = 0;
        $period->saveQuietly();
    }
}
