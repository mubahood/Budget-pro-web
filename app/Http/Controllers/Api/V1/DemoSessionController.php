<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Services\Onboarding\PublicDemo;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * POST demo/session: "Try the live demo" in the phone apps. Signs into a public demo account without the app ever
 * holding its password, so the shared password can change on the server alone. The answer has the same shape as
 * auth/login, with `demo: true` and `app`, the account opened.
 *
 * With no `app` (every phone released so far) it is Budget Pro's, Fresh Corner Market with the farm and the wedding,
 * exactly as before. With `app` (shelfie, kuku-tally, pledge-and-budget, budget-pro) it is that app's own account.
 *
 * SaaS only and throttled (routes/api.php). The token is short-lived (demo.token_hours) and the demo
 * account's own expired tokens are cleared on each new one. Whatever the demo refuses on the web it
 * refuses here too: the locks are in the services (PublicDemo::guard, guardAccount).
 */
class DemoSessionController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
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

        $owner->tokens()->where('expires_at', '<', now())->delete();
        $expires = now()->addHours(max(1, (int) config('demo.token_hours', 12)));
        $device = substr('demo:'.preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $request->input('device_name', 'phone')), 0, 60);
        $token = $owner->createToken($device, ['*'], $expires)->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(),
            'demo' => true,
            'app' => $account,
            'user' => new UserResource($owner),
            'company' => new CompanyResource($company),
        ], 'Welcome to the live demo.');
    }
}
