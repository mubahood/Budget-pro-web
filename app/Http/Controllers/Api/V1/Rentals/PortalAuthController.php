<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\RentalLogin;
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

        return $this->guard(fn () => $this->success(app(RentalLogins::class)->sendCode((string) ($d['login'] ?? $d['email']), $request->ip()),
            'If that opens an account, a code is on its way to its email.'));
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
