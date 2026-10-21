<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoMemberLogin;
use App\Models\User;
use App\Services\Sacco\Members\MemberLogin;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** A member's own API token for the `sacco/me/*` tests (P3.7/P3.8): opened by an officer, as MemberAuthController issues it. */
trait SignsInSaccoMembers
{
    /** @return array<string, string> request headers carrying the member's token */
    protected function memberHeaders(Company $company, SaccoMember $member, User $officer, string $password = 'member-pass-1'): array
    {
        $email = $member->email ?: 'member'.$member->id.'.'.Str::lower(Str::random(5)).'@example.com';
        app(MemberLogin::class)->enable($company, (int) $member->id, $officer, $email, null, $password);
        $login = SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('member_id', $member->id)->firstOrFail();
        $plain = Str::random(48);
        $pat = PersonalAccessToken::forceCreate(['tokenable_type' => SaccoMemberLogin::class, 'tokenable_id' => $login->id, 'name' => 'm',
            'token' => hash('sha256', $plain), 'abilities' => ['sacco:member'], 'expires_at' => now()->addDay()]);

        return ['Authorization' => 'Bearer '.$pat->id.'|'.$plain, 'Accept' => 'application/json'];
    }
}
