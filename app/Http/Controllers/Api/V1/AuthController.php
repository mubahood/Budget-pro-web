<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Onboarding\RegistrationService;
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
        try {
            ['user' => $user, 'company' => $company] = app(RegistrationService::class)
                ->register($request->validated(), RegistrationService::PRODUCT_BUDGET, 'api');
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        } catch (\Throwable $e) {
            Log::error('API registration failed', ['error' => $e->getMessage()]);

            return $this->error('Registration failed. Please try again.', 500);
        }

        $token = $user->createToken($this->deviceName($request), ['*'], now()->addMinutes((int) config('sanctum.expiration')))->plainTextToken;
        app(\App\Services\Notifications\Notifier::class)->welcome($user, $company);

        return $this->created([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
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

        $user = $this->findByIdentifier((string) ($data['identifier'] ?? $data['email']));

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                isset($data['identifier']) ? 'identifier' : 'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (strtolower((string) $user->status) === 'inactive') {
            return $this->forbidden('Your account has been deactivated. Please contact your administrator.');
        }

        $company = Company::find($user->company_id);
        if ($company === null) {
            return $this->error('Your account is not linked to a company.', 403);
        }

        return $this->issue($request, $user, $company, 'Login successful.');
    }

    private function issue(Request $request, User $user, Company $company, string $message)
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $token = $user->createToken($this->deviceName($request), ['*'], now()->addMinutes((int) config('sanctum.expiration')))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
            'user' => new UserResource($user),
            'company' => new CompanyResource($company),
        ], $message);
    }

    /** Email (case-insensitive) or phone in any local/international form. */
    private function findByIdentifier(string $raw): ?User
    {
        $raw = trim($raw);
        if (str_contains($raw, '@')) {
            return User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [strtolower($raw)])->first();
        }
        foreach (array_keys(\App\Support\Phone::COUNTRIES) as $country) {
            $e164 = \App\Support\Phone::e164($raw, $country);
            if ($e164 && ($u = User::withoutGlobalScopes()->where('phone_e164', $e164)->first())) {
                return $u;
            }
        }

        return User::withoutGlobalScopes()->where('phone_number', $raw)->first();
    }

    /** POST auth/otp/request { identifier, purpose: login|register|reset } */
    public function otpRequest(Request $request)
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:191'], 'purpose' => ['required', 'in:login,register,reset'], 'country' => ['nullable', 'string', 'size:2']]);
        try {
            $id = \App\Services\Auth\OtpService::identifier($data['identifier'], $data['country'] ?? 'UG');
            $user = $this->findByIdentifier($id);
            if ($data['purpose'] === 'register' && $user !== null) {
                return $this->error('This '.(str_contains($id, '@') ? 'email' : 'phone number').' is already registered. Log in instead.', 422, ['code' => 'already_registered']);
            }
            if ($data['purpose'] !== 'register' && $user === null) {
                // Same answer as success so the endpoint cannot be used to discover accounts.
                return $this->success(['sent' => true, 'expires_in' => 600], 'If an account exists, a code has been sent.');
            }
            $r = app(\App\Services\Auth\OtpService::class)->request($id, $data['purpose'], $request->ip());
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($r, 'Code sent.');
    }

    /** POST auth/otp/verify { identifier, purpose, code } → login: token · register: verification_token · reset: reset_token */
    public function otpVerify(Request $request)
    {
        $data = $request->validate(['identifier' => ['required', 'string'], 'purpose' => ['required', 'in:login,register,reset'], 'code' => ['required', 'string', 'max:10'], 'country' => ['nullable', 'string', 'size:2']]);
        try {
            $id = \App\Services\Auth\OtpService::identifier($data['identifier'], $data['country'] ?? 'UG');
            app(\App\Services\Auth\OtpService::class)->verify($id, $data['purpose'], $data['code']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        if ($data['purpose'] === 'register') {
            return $this->success(['verification_token' => RegistrationService::verificationToken($id), 'identifier' => $id], 'Verified.');
        }
        $user = $this->findByIdentifier($id);
        if ($user === null) {
            return $this->error('Account not found.', 404);
        }
        $this->markVerified($user, $id);
        if ($data['purpose'] === 'reset') {
            $token = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['u' => $user->id, 'exp' => now()->addMinutes(15)->timestamp]));

            return $this->success(['reset_token' => $token], 'Verified — choose a new password.');
        }
        $company = Company::find($user->company_id);
        if ($company === null || strtolower((string) $user->status) === 'inactive') {
            return $this->forbidden('This account cannot sign in.');
        }

        return $this->issue($request, $user, $company, 'Login successful.');
    }

    private function markVerified(User $user, string $identifier): void
    {
        $col = str_contains($identifier, '@') ? 'email_verified_at' : 'phone_verified_at';
        if ($user->{$col} === null) {
            $user->forceFill([$col => now()])->saveQuietly();
        }
    }

    /** POST auth/password/reset { reset_token, password, password_confirmation } */
    public function resetPassword(Request $request)
    {
        $data = $request->validate(['reset_token' => ['required', 'string'], 'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed']]);
        try {
            $d = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($data['reset_token']), true);
        } catch (\Throwable $e) {
            $d = null;
        }
        if (! is_array($d) || ($d['exp'] ?? 0) < now()->timestamp) {
            return $this->error('This reset link has expired. Request a new code.', 422, ['code' => 'reset_expired']);
        }
        $user = User::withoutGlobalScopes()->find($d['u'] ?? 0);
        if ($user === null) {
            return $this->error('Account not found.', 404);
        }
        $user->password = Hash::make($data['password']);
        $user->save();
        $user->tokens()->delete(); // every session signs in again with the new password

        return $this->success(null, 'Password changed. Please log in.');
    }

    /** PUT auth/profile { first_name?, last_name?, phone_number?, email?, locale? } */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191', \Illuminate\Validation\Rule::unique('admin_users', 'email')->ignore($user->id)],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'locale' => ['sometimes', 'in:en,sw,lg'],
        ]);
        if (array_key_exists('phone_number', $data)) {
            $country = Company::find($user->company_id)?->country ?? 'UG';
            $e164 = $data['phone_number'] ? \App\Support\Phone::e164($data['phone_number'], $country) : null;
            if ($data['phone_number'] && $e164 === null) {
                return $this->error('Enter a valid phone number.', 422, ['code' => 'invalid_phone']);
            }
            if ($e164 && User::withoutGlobalScopes()->where('phone_e164', $e164)->where('id', '!=', $user->id)->exists()) {
                return $this->error('This phone number is used by another account.', 422, ['code' => 'phone_taken']);
            }
            if ($e164 !== $user->phone_e164) {
                $user->phone_verified_at = null;
            }
            $user->phone_e164 = $e164;
        }
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }
        foreach (['first_name', 'last_name', 'email', 'phone_number', 'locale'] as $f) {
            if (array_key_exists($f, $data)) {
                $user->{$f} = $data[$f];
            }
        }
        $user->save();

        return $this->success(new UserResource($user->fresh()), 'Profile updated.');
    }

    /** POST auth/verify/request {channel: phone|email} → POST auth/verify {channel, code} */
    public function verifyRequest(Request $request)
    {
        $data = $request->validate(['channel' => ['required', 'in:phone,email']]);
        $user = $request->user();
        $id = $data['channel'] === 'phone' ? $user->phone_e164 : ($user->email ? strtolower($user->email) : null);
        if (! $id) {
            return $this->error('Add a '.$data['channel'].' to your profile first.', 422, ['code' => 'missing_contact']);
        }
        try {
            $r = app(\App\Services\Auth\OtpService::class)->request($id, 'verify', $request->ip());
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($r, 'Code sent.');
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['channel' => ['required', 'in:phone,email'], 'code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        $id = $data['channel'] === 'phone' ? $user->phone_e164 : strtolower((string) $user->email);
        try {
            app(\App\Services\Auth\OtpService::class)->verify((string) $id, 'verify', $data['code']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $this->markVerified($user, (string) $id);

        return $this->success(new UserResource($user->fresh()), ucfirst($data['channel']).' verified.');
    }

    /** GET auth/sessions — signed-in devices; DELETE auth/sessions/{id} — sign one out. */
    public function sessions(Request $request)
    {
        $current = $request->user()->currentAccessToken()->id;
        $rows = $request->user()->tokens()->orderByDesc('last_used_at')->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(fn ($t) => $t->toArray() + ['current' => $t->id === $current]);

        return $this->success($rows, 'Sessions.');
    }

    public function revokeSession(Request $request, $id)
    {
        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        return $deleted ? $this->success(null, 'Signed out of that device.') : $this->notFound('Session not found.');
    }

    /** POST auth/refresh — swap the current token for a fresh one (extends expiry). */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $company = Company::find($user->company_id);
        $old = $user->currentAccessToken();
        $response = $this->issue($request, $user, $company, 'Token refreshed.');
        $old->delete();

        return $response;
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
            // Snapshot the device caches for offline limit/grace decisions (P1-6).
            'entitlements' => $company ? Entitlements::for($company) : null,
            'role' => \App\Services\Team\Permissions::roleOf($user),
            'permissions' => \App\Services\Team\Permissions::of($user),
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
}
