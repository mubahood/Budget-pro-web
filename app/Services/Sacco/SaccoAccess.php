<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Team\Permissions;

/**
 * Who may do what in a SACCO (docs/akiba/04-architecture.md §6, §7; the BudgetAccess pattern). Two layers (AK-21):
 *
 *   permission  the company role's (ADR 0004): sacco_teller, sacco_approve… (config/permissions.php)
 *   position    an office held today (sacco_officers): the loans committee, the chair, the auditor
 *
 * and two rules beside them: the checker is never the maker, the owner included (AK-22: only a group in
 * single-officer mode may approve its own request, and that is flagged), and a phone never does offline what moves
 * money out or approves it (AK-23, refuseSyncOp). One class for the API, the web portal, sync and the app's cache.
 */
final class SaccoAccess
{
    /** Positions that may reopen a closed month, besides sacco_approve (§7 `period_reopen`). */
    public const REOPEN_POSITIONS = ['chairperson', 'supervisory'];

    /** Positions that vote on loans: the committee, and the board on appeal (§6.2). */
    public const VOTE_POSITIONS = ['loans_committee'];

    public const APPEAL_POSITIONS = ['board_member', 'chairperson', 'vice_chair'];

    /** Money a phone may take in offline, in cash only (§9.1, AK-23). */
    public const OFFLINE_KINDS = ['deposit', 'share_purchase', 'entrance_fee', 'fee', 'loan_repayment', 'fine_payment', 'fund_contribution'];

    /** The company of a SACCO login, if it keeps the SACCO books. */
    private static function sacco(User $user): ?Company
    {
        $company = Company::withoutGlobalScopes()->find($user->company_id);

        return $company !== null && in_array('sacco', $company->modules(), true) ? $company : null;
    }

    /** The login has this sacco_* permission in a SACCO account (and is not switched off). */
    public static function can(?User $user, string $permission): bool
    {
        if ($user === null || self::sacco($user) === null || Permissions::roleOf($user) === 'none') {
            return false;
        }

        return Permissions::can($user, $permission);
    }

    /** Refuse 403-style (`forbidden`, with the missing permission), as ApiPermissionMap does. */
    public static function authorize(?User $user, string $permission): void
    {
        if (! self::can($user, $permission)) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask the SACCO\'s manager.', ['permission' => $permission]);
        }
    }

    /** @param  list<string>  $positions */
    public static function holds(?User $user, array $positions): bool
    {
        return $user !== null && $user->company_id !== null && OfficerService::holds((int) $user->company_id, (int) $user->id, $positions);
    }

    /**
     * The checker of a request made by $makerId (AK-22). The maker may never check their own request, the owner
     * included; in group mode with single_officer_mode on, they may, and the result says so (stored as
     * self_approved, shown on the audit and closing reports).
     *
     * @return bool whether this is a self-approval allowed by single-officer mode
     */
    public static function assertChecker(User $checker, ?int $makerId, string $permission = 'sacco_approve'): bool
    {
        self::authorize($checker, $permission);
        if ($makerId === null || (int) $checker->id !== $makerId) {
            return false;
        }
        $settings = SaccoSetting::for((int) $checker->company_id);
        if ($settings !== null && $settings->isGroup() && $settings->single_officer_mode) {
            return true;
        }

        throw BusinessRuleException::make('self_approval', 'Another officer must check this: nobody approves their own request, the owner included.');
    }

    /** Reopen a closed month: sacco_approve and the chair's or the auditor's office (§7). */
    public static function canReopenPeriod(?User $user): bool
    {
        return self::can($user, 'sacco_approve') && self::holds($user, self::REOPEN_POSITIONS);
    }

    /**
     * Vote on a loan (§6.2): sacco_loans_vote, a seat on the committee (the board on appeal), not the applicant, and
     * not recused (related to the applicant).
     *
     * @param  list<int>  $recusedMemberIds  members related to the applicant
     */
    public static function canVote(?User $user, int $applicantMemberId, ?int $voterMemberId, array $recusedMemberIds = [], bool $appeal = false): bool
    {
        if (! self::can($user, 'sacco_loans_vote') || ! self::holds($user, $appeal ? self::APPEAL_POSITIONS : self::VOTE_POSITIONS)) {
            return false;
        }

        return $voterMemberId === null || ($voterMemberId !== $applicantMemberId && ! in_array($voterMemberId, $recusedMemberIds, true));
    }

    /**
     * Why a pushed operation may not come from a phone, or null (§9.2, AK-23; SyncApplier calls it beside
     * permissionFor). Only cash receipts are events a phone may write; members and loan drafts are master rows.
     *
     * @param  array<string, mixed>  $data
     */
    public static function refuseSyncOp(string $table, string $action, array $data): ?string
    {
        return match ($table) {
            'sacco_transactions' => $action !== 'insert' ? 'A SACCO receipt cannot be changed from a phone.'
                : (! in_array($data['kind'] ?? null, self::OFFLINE_KINDS, true) || ($data['channel'] ?? 'cash') !== 'cash'
                    ? 'Needs the internet: this moves money out or approves it.' : null),
            'sacco_loans' => ($data['status'] ?? 'draft') !== 'draft' ? 'Needs the internet: only a loan draft can be started offline.' : null,
            'sacco_members' => isset($data['kyc_status']) && $data['kyc_status'] !== 'pending' ? 'Needs the internet: identity is verified online by a second officer.' : null,
            default => null,
        };
    }
}
