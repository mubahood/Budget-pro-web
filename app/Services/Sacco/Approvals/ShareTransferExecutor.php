<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Shares\ShareService;

/**
 * `share_transfer` (04 §5.1, §9.2): shares move from one member to another (a sale, an inheritance), asked by an
 * officer with sacco_teller and approved by another with sacco_approve; the receiver stays within the cap. Posts
 * Dr 3100 (from) / Cr 3100 (to) with the shares' part of what was paid for them, and moves the counts.
 */
final class ShareTransferExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'share_transfer';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_teller';
    }

    public function prepare(int $companyId, array $payload): array
    {
        return ShareService::prepareTransfer($companyId, $payload);
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $tx = (new ShareService())->executeTransfer($approval, $checker);

        return ['entry_id' => (int) $tx->journal_entry_id, 'transaction_id' => (int) $tx->id, 'receipt_no' => $tx->receipt_no];
    }
}
