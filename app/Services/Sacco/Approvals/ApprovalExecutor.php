<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;
use App\Models\User;

/**
 * What one kind of maker-checker request does (04 §7). SaccoApprovals freezes the payload, checks the maker, the
 * checker and the expiry, and calls execute() in one transaction with the request locked; an executor that posts
 * uses the key `apr:{uuid}`, so executing twice can never post twice.
 */
interface ApprovalExecutor
{
    public function action(): string;

    /** The permission the maker needs to ask (null: only the system asks, e.g. a held receipt from sync). */
    public function makerPermission(): ?string;

    /** The permission the checker needs. */
    public function checkerPermission(): string;

    /** Offices the checker must also hold (period_reopen: the chair or the auditor), or []. @return list<string> */
    public function checkerPositions(): array;

    /** How many checkers it takes (write-offs and dividends take 2, P2/P4). @param array<string, mixed> $payload */
    public function requiredApprovals(array $payload): int;

    /**
     * Refuse a payload before it is frozen, and normalise it (whole minor units, ids that exist in this SACCO).
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, subject_type: ?string, subject_id: ?int, amount_minor: ?int}
     */
    public function prepare(int $companyId, array $payload): array;

    /** Do it. @return array<string, mixed> the result kept on the request (entry_id when a journal was posted) */
    public function execute(SaccoApproval $approval, User $checker): array;
}
