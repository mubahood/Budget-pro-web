<?php

namespace App\PingPin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscription;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Ping Pin's own, independent signup and login (Task 3.1 / brief §3) —
 * the actual missing piece: everything built before this (billing,
 * organisation membership, entitlements) had no front door of its own,
 * and the mobile client's only login screen logged into an *existing*
 * budget-pro account. This creates admin_users + companies +
 * company_members owner row + a trialing pingpin_subscriptions row in one
 * transaction, matching AuthController::register()'s proven shape, but
 * leaner (no financial period, no required company_name) and accepting
 * phone OR email, per the brief's "phone is likely primary for this
 * market" framing.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $r)
    {
        $data = $r->validate([
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191|unique:admin_users,email',
            'phone_number' => 'nullable|string|max:30',
            'password' => 'required|string|min:6|max:100',
            'organisation_name' => 'nullable|string|max:191',
            'currency' => 'nullable|string|max:8',
        ]);

        if (empty($data['email']) && empty($data['phone_number'])) {
            return $this->error('Provide an email or phone number.', 422);
        }

        if (! empty($data['phone_number']) && User::where('phone_number', $data['phone_number'])->exists()) {
            return $this->error('This phone number is already registered.', 422);
        }

        try {
            [$user, $company] = DB::transaction(function () use ($data) {
                $user = new User();
                $user->name = $data['name'];
                $user->username = $data['email'] ?? $data['phone_number'];
                $user->email = $data['email'] ?? null;
                $user->phone_number = $data['phone_number'] ?? null;
                $user->password = Hash::make($data['password']);
                $user->status = 'Active';
                $user->save();

                $company = new Company();
                $company->owner_id = $user->id;
                $company->name = $data['organisation_name'] ?? ($data['name']."'s Organisation");
                $company->email = $data['email'] ?? null;
                $company->phone_number = $data['phone_number'] ?? null;
                $company->status = 'Active';
                $company->currency = $data['currency'] ?? 'UGX';
                $company->save();

                CompanyMember::create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                $trialPlan = PingPinPlan::where('slug', 'trial')->first();
                $trialDays = $trialPlan?->trial_days ?? 14;
                PingPinSubscription::create([
                    'company_id' => $company->id,
                    'plan_id' => $trialPlan?->id,
                    'status' => 'trialing',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays($trialDays),
                    'ends_at' => now()->addDays($trialDays),
                    'provider' => 'trial',
                ]);

                $user->refresh();

                return [$user, $company];
            });
        } catch (\Throwable $e) {
            Log::error('PingPin registration failed', ['error' => $e->getMessage()]);

            return $this->error('Registration failed. Please try again.', 500);
        }

        return $this->success($this->authPayload($user, $company), 'Registered.', 201);
    }

    public function login(Request $r)
    {
        $data = $r->validate([
            'identifier' => 'required|string', // email or phone
            'password' => 'required|string',
        ]);

        $isEmail = str_contains($data['identifier'], '@');
        $user = $isEmail
            ? User::where('email', $data['identifier'])->first()
            : User::where('phone_number', $data['identifier'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials.', 401);
        }

        if (strtolower((string) $user->status) === 'inactive') {
            return $this->forbidden('Your account has been deactivated.');
        }

        // A user's first/primary organisation for this product — a user
        // belonging to several (invited into others) picks among them via
        // GET pingpin/organisations after login; this just needs one to
        // land on. Prefers an 'owner' row (their own org) over one they
        // were merely invited into.
        $membership = CompanyMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByRaw("role = 'owner' desc")
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            return $this->error('This account has no Ping Pin organisation yet.', 403);
        }

        return $this->success($this->authPayload($user, $membership->company), 'Logged in.');
    }

    /**
     * Re-authentication step for anything that WEAKENS protection on an
     * enrolled device — disabling tracking, signing out, (later) requesting
     * an uninstall/Device Admin release. A stolen phone's tracking
     * notification is required to stay visible (Android enforces this, and
     * so does Play policy — see the app's own onboarding copy), so the real
     * defence against a thief isn't hiding that tracking is happening, it's
     * making sure noticing it doesn't let them casually turn it off:
     * whoever is holding the phone needs the OWNER's password to do that,
     * not just an already-unlocked screen and an already-logged-in app.
     */
    public function verifyPassword(Request $r)
    {
        $data = $r->validate(['password' => 'required|string']);

        if (! Hash::check($data['password'], $r->user()->password)) {
            return $this->error('Incorrect password.', 401);
        }

        return $this->success(null, 'Verified.');
    }

    private function authPayload(User $user, Company $company): array
    {
        $token = $user->createToken('pingpin-mobile')->plainTextToken;

        return [
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone_number' => $user->phone_number],
            'company' => ['id' => $company->id, 'name' => $company->name],
        ];
    }
}
