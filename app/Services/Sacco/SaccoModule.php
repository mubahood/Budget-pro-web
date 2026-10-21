<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\SaccoOfficer;

/**
 * Wires the SACCO module's parts together at boot (App\Providers\AppServiceProvider): each part registers what it
 * adds to another, so no part needs to know the next one's tables.
 *
 * - Officers (P1.8) and members (P1.9): an officer who is a member is one of this SACCO's current members and an
 *   adult (Coop s.13), and holding an office makes the member an insider (loan routing, guarantor rules).
 * - Approvals (P1.10): the P1 executors, and a month never closes with a request from it still waiting.
 */
final class SaccoModule
{
    /** The maker-checker actions of P1 (04 §7); withdrawal and product_terms_change register with savings (P1.12, AK-62). */
    public const EXECUTORS = [
        Approvals\ReversalExecutor::class, Approvals\ManualJournalExecutor::class, Approvals\PeriodCloseExecutor::class, Approvals\PeriodReopenExecutor::class,
        Approvals\LedgerAccountCreateExecutor::class, Approvals\MemberKycExecutor::class, Approvals\HeldReceiptAllocationExecutor::class,
    ];

    public static function boot(): void
    {
        foreach (self::EXECUTORS as $class) {
            SaccoApprovals::register(new $class());
        }
        Loans\LoansModule::boot(); // P2: loans register their sub-ledger, executors, jobs and checks there
        Ledger\CloseChecks::register('approvals', function (int $companyId, \App\Models\SaccoPeriod $period) {
            $open = SaccoApprovals::openIn($companyId, $period);
            if ($open > 0) {
                throw BusinessRuleException::make('approvals_pending', "{$open} request(s) from ".$period->label().' still wait for a decision: approve, reject or cancel them first.');
            }
        });
        OfficerService::check('member', function (int $companyId, array $data) {
            if (empty($data['member_id'])) {
                return;
            }
            $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find((int) $data['member_id'])
                ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
            if (! in_array($member->status, ['active', 'dormant'], true)) {
                throw BusinessRuleException::make('invalid_officer', 'Only an active member can hold an office.');
            }
            if ($member->isMinor()) {
                throw BusinessRuleException::make('invalid_officer', 'A member under 18 may not hold an office (Cooperative Societies Act s.13).');
            }
        });
        OfficerService::after('insider', function (int $companyId, SaccoOfficer $officer) {
            if ($officer->member_id !== null) {
                SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($officer->member_id)->first()
                    ?->fill(['is_insider' => OfficerService::memberHoldsOffice($companyId, (int) $officer->member_id)])->save();
            }
        });
    }
}
