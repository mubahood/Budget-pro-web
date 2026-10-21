<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Sacco\Members\MemberService;

/**
 * `member_kyc` (04 §7): in office mode an officer who captured a member asks for the identity check, and an officer
 * with sacco_kyc (never the capturer) verifies or rejects it. A group verifies directly (MemberService::verifyKyc).
 */
final class MemberKycExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'member_kyc';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_members';
    }

    public function checkerPermission(): string
    {
        return 'sacco_kyc';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($this->int($payload, 'member_id'))
            ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        $decision = (string) ($payload['decision'] ?? 'verified');
        if (! in_array($decision, ['verified', 'rejected'], true)) {
            throw BusinessRuleException::make('invalid_decision', 'Verify or reject.');
        }

        return ['payload' => ['member_id' => (int) $member->id, 'member_no' => $member->member_no, 'decision' => $decision, 'note' => $payload['note'] ?? null],
            'subject_type' => 'sacco_members', 'subject_id' => (int) $member->id, 'amount_minor' => null];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $member = (new MemberService())->verifyKyc($this->company($approval), (int) $p['member_id'], $checker, (string) $p['decision'], $p['note'] ?? null);

        return ['member_id' => (int) $member->id, 'kyc_status' => $member->kyc_status];
    }
}
