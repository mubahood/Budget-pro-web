<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\RentalLogin;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Portal\PersonView;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenants' and landlords' own sign-in on the API (KR-16, docs/estate-ledger/04-architecture.md §8; SaaS only, throttled):
 * a password or an emailed code opens every account the login has. One: a token at once ({token, person}). More
 * than one (several agencies, or a tenant and a landlord record): {choices[], ticket}, and POST …/token with the
 * choice. A refusal never says whether a login exists (401 invalid_credentials); only someone who proved who they are
 * hears that the account's plan has no own sign-in (403 portal_not_on_plan).
 */
class PortalAuthController extends Controller
{
    use ApiResponse;

    /** POST rentals/portal-auth/login {login (email or phone), password, device_name} */
    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['login' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:190'], 'device_name' => ['nullable', 'string', 'max:60']]);

        return $this->guard(fn () => $this->answer(app(RentalLogins::class)->attempt($d['login'], $d['password']), $d['device_name'] ?? null));
    }

    /** POST rentals/portal-auth/code {login}: a one-time code to the login's email; the same answer whether or not it exists. */
    public function code(Request $request): JsonResponse
    {
        $d = $request->validate(['login' => ['required_without:email', 'nullable', 'string', 'max:190'], 'email' => ['nullable', 'string', 'max:190']]);

        // Whether email can leave this server is the same for everyone, so saying so gives nobody away. The web refuses in these words: the app must not promise a code.
        $on = RentalLogins::codesOn();

        return $this->guard(fn () => $this->success(app(RentalLogins::class)->sendCode((string) ($d['login'] ?? $d['email']), $request->ip()) + ['codes_on' => $on],
            $on ? 'If that opens an account, a code is on its way to its email.' : 'We cannot send sign-in codes at the moment, because email is switched off on this service. Sign in with your password. If you have none, ask the people who manage your home or property to set one for you.'));
    }

    /** POST rentals/portal-auth/code/verify {login, code, device_name}: a token, or the choices and a ticket. */
    public function verify(Request $request): JsonResponse
    {
        $d = $request->validate(['login' => ['required_without:email', 'nullable', 'string', 'max:190'], 'email' => ['nullable', 'string', 'max:190'],
            'code' => ['required', 'string', 'max:12'], 'device_name' => ['nullable', 'string', 'max:60']]);

        return $this->guard(fn () => $this->answer(app(RentalLogins::class)->verifyCode((string) ($d['login'] ?? $d['email']), $d['code']), $d['device_name'] ?? null));
    }

    /** POST rentals/portal-auth/token {ticket, company_id, subject_type, subject_id, device_name}: a token for the chosen account. */
    public function token(Request $request): JsonResponse
    {
        $d = $request->validate(['ticket' => ['required', 'string', 'max:80'], 'company_id' => ['required', 'integer'], 'subject_type' => ['required', 'in:tenant,landlord'],
            'subject_id' => ['required', 'integer'], 'device_name' => ['nullable', 'string', 'max:60']]);

        return $this->guard(fn () => $this->issue(app(RentalLogins::class)->redeem($d['ticket'], (int) $d['company_id'], $d['subject_type'], (int) $d['subject_id']),
            $d['device_name'] ?? null));
    }

    /**
     * POST rentals/portal-auth/demo {account: agency|solo, role, device_name}: the demo's tenant or landlord, in one tap: the app never holds a password. A person token that expires
     * like a staff demo token (demo.token_hours). Whatever the demo refuses on the web it refuses here: the locks are in the services (RentalsDemo::guard).
     */
    public function demo(Request $request): JsonResponse
    {
        if (! RentalsDemo::enabled()) {
            return $this->error('There is no live demo on this server.', 404, ['code' => 'not_found']);
        }
        $d = $request->validate(['role' => ['required', 'string', 'max:40'], 'account' => ['nullable', 'in:agency,solo'], 'device_name' => ['nullable', 'string', 'max:60']]);
        $account = $d['account'] ?? RentalsDemo::AGENCY;
        if (! array_key_exists($d['role'], RentalsDemo::people($account))) {
            return $this->error('The demo has no such person.', 422, ['code' => 'demo_unknown_role']);
        }
        $company = RentalsDemo::company($account);
        $row = $company === null ? null : RentalLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('email', RentalsDemo::email($d['role'], $account))->whereNull('disabled_at')->first();
        $login = $row === null ? null : app(RentalLogins::class)->resolve((int) $row->id);
        if ($login === null) {
            return $this->error('The demo is being rebuilt. Try again in a few minutes.', 409, ['code' => 'demo_rebuilding']);
        }
        $login->tokens()->where('expires_at', '<', now())->delete();
        $expires = now()->addHours(max(1, (int) config('demo.token_hours', 12)));
        $device = substr('demo:'.preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) ($d['device_name'] ?? 'phone')), 0, 60);

        return $this->created(['token' => $login->createToken($device, [RentalLogin::ABILITY], $expires)->plainTextToken, 'expires_at' => $expires->toIso8601String(), 'demo' => true, 'person' => PersonView::for($login)->me()], 'Welcome to the live demo.');
    }

    /** @param list<array{login_id: int, company_id: int, company_name: string, subject_type: string, subject_id: int, name: string}> $choices */
    private function answer(array $choices, ?string $device): JsonResponse
    {
        if (count($choices) === 1) {
            $login = app(RentalLogins::class)->resolve((int) $choices[0]['login_id']) ?? throw BusinessRuleException::make('invalid_credentials', 'That sign-in or password is not right.');

            return $this->issue($login, $device);
        }
        $shown = array_map(fn ($c) => ['company_id' => $c['company_id'], 'company_name' => $c['company_name'], 'subject_type' => $c['subject_type'],
            'subject_id' => $c['subject_id'], 'name' => $c['name']], $choices);

        return $this->success(['choices' => $shown] + app(RentalLogins::class)->ticket($choices), 'Choose an account.');
    }

    private function issue(RentalLogin $login, ?string $device): JsonResponse
    {
        $token = app(RentalLogins::class)->issueToken($login, (string) ($device ?? 'app'));

        return $this->created(['token' => $token, 'expires_at' => now()->addDays(RentalLogins::TOKEN_DAYS)->toIso8601String(), 'person' => PersonView::for($login)->me()], 'Signed in.');
    }

    /** @param callable(): JsonResponse $fn */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), match ($e->errorCode()) {
                'invalid_credentials', 'portal_session_invalid' => 401,
                'portal_not_on_plan' => 403,
                default => 422,
            }, $e->toErrors());
        }
    }
}
