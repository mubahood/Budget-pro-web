<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Services\Onboarding\PublicDemo;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * POST demo/session: "Try the live demo" in the phone apps. Signs into a public demo account without the app ever
 * holding its password, so the shared password can change on the server alone. The answer has the same shape as
 * auth/login, with `demo: true` and `app`, the account opened.
 *
 * With no `app` (every phone released so far) it is Budget Pro's, Fresh Corner Market with the farm and the wedding,
 * exactly as before. With `app` (shelfie, kuku-tally, pledge-and-budget, budget-pro) it is that app's own account.
 * With `app` estate-ledger it is RentalsDemo's: its staff roles here, and its tenant and landlord at rentals/portal-auth/demo.
 *
 * SaaS only and throttled (routes/api.php). The token is short-lived (demo.token_hours) and the demo
 * account's own expired tokens are cleared on each new one. Whatever the demo refuses on the web it
 * refuses here too: the locks are in the services (PublicDemo::guard, guardAccount).
 */
class DemoSessionController extends Controller
{
    use ApiResponse;

    /** Estate Ledger's demo stands alone (RentalsDemo): its own companies, its own lock, and people who sign in with their own token. */
    private const ESTATE_LEDGER = 'estate-ledger';

    /** What each of its buttons opens, in the phone's words. Key: account:role. */
    private const ESTATE_LEDGER_WHAT = [
        'agency:owner' => 'Sees everything: today, the landlords, month end and the money.',
        'agency:manager' => 'Runs the agency day to day: tenants, rent, landlords and repairs.',
        'agency:accountant' => 'Takes money, raises bills, pays landlords and closes the month.',
        'agency:caretaker' => 'Takes rent door to door at two buildings, and sees nothing else.',
        'agency:viewer' => 'Reads the figures and reports, and changes nothing.',
        'agency:tenant' => 'Behind on rent: what they owe, how to pay, and telling the office they have paid.',
        'agency:tenant-paid' => 'Pays on time: bills, receipts and repairs.',
        'agency:landlord' => 'What they are owed, their statements, and a yes or no on repairs.',
        'solo:owner' => 'A landlord with no agency: the same book, run by the owner.',
    ];

    /** GET demo/session?app=: who a visitor can open this app's demo as, for the buttons. */
    public function index(Request $request)
    {
        if ($request->input('app') === self::ESTATE_LEDGER) {
            return $this->estateLedgerRoles();
        }
        if (! PublicDemo::enabled()) {
            return $this->notFound('There is no live demo on this server.');
        }
        $account = $request->filled('app') ? (string) $request->input('app') : PublicDemo::BUDGET_PRO;
        if (! PublicDemo::hasAccount($account)) {
            return $this->error('There is no live demo for that app.', 422, ['code' => 'demo_unknown_app']);
        }
        $roles = [];
        foreach (PublicDemo::roles($account) as $role => $r) {
            $roles[] = ['role' => (string) $role, 'label' => $r['label'], 'what' => $r['what'], 'name' => $r['name']];
        }

        return $this->success(['app' => $account, 'roles' => $roles], 'Demo roles.');
    }

    public function store(Request $request)
    {
        if ($request->input('app') === self::ESTATE_LEDGER) {
            return $this->estateLedgerSession($request);
        }
        if (! PublicDemo::enabled()) {
            return $this->notFound('There is no live demo on this server.');
        }
        $account = $request->filled('app') ? (string) $request->input('app') : PublicDemo::BUDGET_PRO;
        if (! PublicDemo::hasAccount($account)) {
            return $this->error('There is no live demo for that app.', 422, ['code' => 'demo_unknown_app']);
        }
        $owner = PublicDemo::owner($account);
        $company = PublicDemo::company($account);
        if ($owner === null || $company === null || strtolower((string) $owner->status) === 'inactive') {
            return $this->error('The demo is being rebuilt. Try again in a few minutes.', 409, ['code' => 'demo_rebuilding']);
        }

        // Only that app's own roles (PublicDemo::roles); anything else opens the owner, never another person. As the web's five buttons.
        $as = $request->filled('role') ? (PublicDemo::roleUser($account, (string) $request->input('role')) ?? $owner) : $owner;

        $as->tokens()->where('expires_at', '<', now())->delete();
        $expires = now()->addHours(max(1, (int) config('demo.token_hours', 12)));
        $device = substr('demo:'.preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $request->input('device_name', 'phone')), 0, 60);
        $token = $as->createToken($device, ['*'], $expires)->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(),
            'demo' => true,
            'app' => $account,
            'user' => new UserResource($as),
            'company' => new CompanyResource($company),
        ], 'Welcome to the live demo.');
    }

    /** The agency's staff and people, and the landlord on his own, from RentalsDemo. No sign-in details: the phone never holds a password. */
    private function estateLedgerRoles()
    {
        if (! RentalsDemo::enabled()) {
            return $this->notFound('There is no live demo on this server.');
        }
        $status = RentalsDemo::status();
        $what = fn (string $account, string $role, string $label) => self::ESTATE_LEDGER_WHAT[$account.':'.$role] ?? $label;
        $roles = [];
        if ($status['ready']) {
            foreach ($status['logins'] as $l) {
                $roles[] = ['role' => (string) $l['key'], 'label' => (string) $l['label'], 'what' => $what('agency', (string) $l['key'], (string) $l['label']), 'name' => (string) $l['name'], 'type' => 'staff', 'account' => RentalsDemo::AGENCY];
            }
            foreach ($status['people'] as $p) {
                $roles[] = ['role' => (string) $p['key'], 'label' => (string) $p['label'], 'what' => $what('agency', (string) $p['key'], (string) $p['label']), 'name' => (string) ($p['name'] ?? ''), 'type' => (string) $p['type'], 'account' => RentalsDemo::AGENCY];
            }
        }
        if ($status['solo'] !== null) {
            $roles[] = ['role' => 'owner', 'label' => (string) $status['solo']['label'], 'what' => $what('solo', 'owner', (string) $status['solo']['label']), 'name' => (string) $status['solo']['name'], 'type' => 'staff', 'account' => RentalsDemo::SOLO];
        }

        return $this->success(['app' => self::ESTATE_LEDGER, 'ready' => (bool) $status['ready'], 'roles' => $roles], 'Demo roles.');
    }

    /** A staff role of the agency, or the landlord on his own. A tenant or landlord signs in through rentals/portal-auth/demo. */
    private function estateLedgerSession(Request $request)
    {
        if (! RentalsDemo::enabled()) {
            return $this->notFound('There is no live demo on this server.');
        }
        $account = $request->input('account', RentalsDemo::AGENCY);
        $role = $request->input('role', 'owner');
        if (! in_array($account, [RentalsDemo::AGENCY, RentalsDemo::SOLO], true) || ! is_string($role) || ! array_key_exists($role, RentalsDemo::roles($account))) {
            return $this->error('The demo has no such role.', 422, ['code' => 'demo_unknown_role']);
        }
        $company = RentalsDemo::company($account);
        $as = $company === null ? null : RentalsDemo::login($role, $account);
        if ($as === null || (int) $as->company_id !== (int) $company->id || strtolower((string) $as->status) === 'inactive') {
            return $this->error('The demo is being rebuilt. Try again in a few minutes.', 409, ['code' => 'demo_rebuilding']);
        }
        $as->tokens()->where('expires_at', '<', now())->delete();
        $expires = now()->addHours(max(1, (int) config('demo.token_hours', 12)));
        $device = substr('demo:'.preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $request->input('device_name', 'phone')), 0, 60);

        return $this->success(['token' => $as->createToken($device, ['*'], $expires)->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'demo' => true, 'app' => self::ESTATE_LEDGER, 'account' => $account,
            'user' => new UserResource($as), 'company' => new CompanyResource($company)], 'Welcome to the live demo.');
    }
}
