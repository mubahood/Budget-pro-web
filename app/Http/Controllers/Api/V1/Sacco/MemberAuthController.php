<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SaccoMemberAuth;
use App\Models\SaccoMember;
use App\Models\SaccoMemberLogin;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSetting;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Members\MemberLogin;
use App\Services\Sacco\Reports\SaccoReports;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Members' own sign-in and their own records on the API (04 §6.4, §13.2; the member mode of the Akiba app). A code
 * by email (or the password an officer set up) opens every membership of that address; the member picks one and
 * gets a token for it. `sacco/me/*` answers only that member's records (MemberLogin::owns), in their SACCO's name.
 */
class MemberAuthController extends Controller
{
    use ApiResponse;

    public const TOKEN_DAYS = 30;

    public const TICKET_MINUTES = 10;

    /** POST sacco/member-auth/otp {email}: the same answer whether or not the address is known. */
    public function otp(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'string', 'max:190']]);

        return $this->guard(fn () => $this->success(['sent' => true] + app(MemberLogin::class)->requestCode($d['email'], $request->ip()), 'If that address opens a membership, a code is on its way.'));
    }

    /** POST sacco/member-auth/verify {email, code}: the memberships it opens, and a 10-minute ticket to pick one. */
    public function verify(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:12']]);

        return $this->guard(fn () => $this->success($this->ticket(app(MemberLogin::class)->verifyCode($d['email'], $d['code'])), 'Choose a membership.'));
    }

    /**
     * POST sacco/member-auth/token {company_id, member_id, ticket} or {company_id, member_id, email, password}: a token for
     * that membership (30 days).
     */
    public function token(Request $request): JsonResponse
    {
        $d = $request->validate(['company_id' => ['required', 'integer'], 'member_id' => ['required', 'integer'], 'ticket' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'max:190'], 'password' => ['nullable', 'string', 'max:190'], 'device_name' => ['nullable', 'string', 'max:60']]);

        return $this->guard(function () use ($d) {
            if (! empty($d['ticket'])) {
                $pairs = Cache::pull('sacco-member-ticket:'.hash('sha256', $d['ticket']), []);
            } elseif (! empty($d['email']) && ! empty($d['password'])) {
                $pairs = array_map(fn ($m) => [$m['company_id'], $m['member_id']], app(MemberLogin::class)->attempt($d['email'], $d['password']));
            } else {
                throw BusinessRuleException::make('invalid_credentials', 'Sign in with a code or a password first.');
            }
            if (! in_array([(int) $d['company_id'], (int) $d['member_id']], $pairs, true)) {
                throw BusinessRuleException::make('invalid_credentials', 'That membership was not opened by this sign-in.');
            }
            $member = app(MemberLogin::class)->resolve((int) $d['company_id'], (int) $d['member_id']);
            $login = SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $member->company_id)->where('member_id', $member->id)->firstOrFail();
            $plain = Str::random(48);
            $token = PersonalAccessToken::forceCreate(['tokenable_type' => SaccoMemberLogin::class, 'tokenable_id' => $login->id, 'name' => mb_substr($d['device_name'] ?? 'member', 0, 60),
                'token' => hash('sha256', $plain), 'abilities' => [SaccoMemberAuth::ABILITY], 'expires_at' => now()->addDays(self::TOKEN_DAYS)]);

            return $this->created(['token' => $token->id.'|'.$plain, 'expires_at' => $token->expires_at?->toIso8601String(), 'member' => $this->me($member)], 'Signed in.');
        });
    }

    /** GET sacco/me */
    public function show(Request $request): JsonResponse
    {
        return $this->success($this->me($this->member($request)));
    }

    /** GET sacco/me/accounts: the member's savings and share accounts. */
    public function accounts(Request $request): JsonResponse
    {
        $m = $this->member($request);
        $names = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $m->company_id)->pluck('name', 'id');

        return $this->success([
            'savings' => SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->orderBy('id')->get()
                ->filter(fn ($a) => MemberLogin::owns($m, $a))->map(fn ($a) => Present::savings($a) + ['product_name' => $names[$a->product_id] ?? null])->values()->all(),
            'shares' => ($s = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->first()) ? Present::shares($s) : null,
        ]);
    }

    /** GET sacco/me/statement?from&to&account_type&account_id */
    public function statement(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->guard(fn () => $this->success((new SaccoReports())->build($request->attributes->get('company'), 'member_statement',
            ['member_id' => $m->id] + $request->only(['from', 'to', 'account_type', 'account_id']), null)));
    }

    /** GET sacco/me/receipts/{id}: one of the member's own receipts. */
    public function receipt(Request $request, int $id): JsonResponse
    {
        $m = $this->member($request);
        $t = SaccoTransaction::withoutGlobalScopes()->where('company_id', $m->company_id)->find($id);

        return $t === null || ! MemberLogin::owns($m, $t) ? $this->notFound('Not found.') : $this->success(Present::transaction($t));
    }

    /** POST sacco/me/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('sacco_member_token')?->delete();

        return $this->success(null, 'Signed out.');
    }

    private function member(Request $request): SaccoMember
    {
        return $request->attributes->get('sacco_member');
    }

    /** @return array<string, mixed> */
    private function me(SaccoMember $m): array
    {
        $s = SaccoSetting::for((int) $m->company_id);

        return ['member_id' => (int) $m->id, 'member_no' => $m->member_no, 'name' => $m->full_name, 'status' => $m->status, 'phone' => $m->phone_e164, 'email' => $m->email,
            'company_id' => (int) $m->company_id, 'sacco' => ['name' => $s?->short_name, 'legal_name' => $s?->legal_name, 'currency' => $s?->currency, 'regulated_line' => $s?->regulatedLine()]];
    }

    /** @param list<array{company_id: int, member_id: int, member_no: ?string, name: string, sacco: string}> $memberships */
    private function ticket(array $memberships): array
    {
        $ticket = Str::random(40);
        Cache::put('sacco-member-ticket:'.hash('sha256', $ticket), array_map(fn ($m) => [$m['company_id'], $m['member_id']], $memberships), now()->addMinutes(self::TICKET_MINUTES));

        return ['memberships' => $memberships, 'ticket' => $ticket, 'expires_in' => self::TICKET_MINUTES * 60];
    }

    /** @param callable(): JsonResponse $fn */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), in_array($e->errorCode(), ['invalid_credentials', 'member_session_invalid'], true) ? 401 : 422, $e->toErrors());
        }
    }
}
