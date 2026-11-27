<?php

namespace App\Services\Unity;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Team\Permissions;

/**
 * What the signed-in person may do in their group, for the phone to shape its screens (meta.access, GET unity/access), and
 * the self-service rule: someone without an officer's permission acts only as their own member record.
 */
final class UnityAccess
{
    /** @return array{members: bool, finance: bool, chapters: bool, meetings: bool, welfare: bool, voice: bool, settings: bool, member_id: ?int, plan: array{chapters: bool, elections: bool, sms: bool}} */
    public static function for(Company $company, User $user): array
    {
        $can = fn (string $p) => Permissions::can($user, $p);
        $quotas = new Quotas();
        $sms = $quotas->limit($company, 'sms');

        return [
            'view' => $can('unity_view'),
            'members' => $can('unity_members'), 'finance' => $can('unity_finance'), 'chapters' => $can('unity_chapters'),
            'meetings' => $can('unity_meetings'), 'welfare' => $can('unity_welfare'), 'voice' => $can('unity_voice'), 'settings' => $can('unity_settings'),
            'member_id' => self::memberId((int) $company->id, $user),
            'plan' => ['chapters' => $quotas->featureOn($company, 'unity_chapters'), 'elections' => $quotas->featureOn($company, 'unity_elections'),
                'sms' => $sms === null || $sms > 0],
        ];
    }

    /** The signed-in person's own member record (UnityMembers::ofUser), or null. */
    public static function member(int $companyId, User $user): ?SaccoMember
    {
        return UnityMembers::ofUser($companyId, $user);
    }

    public static function memberId(int $companyId, User $user): ?int
    {
        $m = self::member($companyId, $user);

        return $m === null ? null : (int) $m->id;
    }
}
