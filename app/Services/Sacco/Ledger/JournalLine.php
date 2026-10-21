<?php

namespace App\Services\Sacco\Ledger;

use App\Support\Sacco\Amount;

/** One line of a draft (ADR 0009 §4.2): one side, an account, and on a control account the sub-ledger row it moves. */
final class JournalLine
{
    public function __construct(
        public readonly int $accountId,
        public readonly Amount $debit,
        public readonly Amount $credit,
        public readonly ?string $subType = null,
        public readonly ?int $subId = null,
        public readonly ?int $memberId = null,
        public readonly ?string $memo = null,
    ) {
    }

    /** @return array{account: int, debit: int, credit: int, sub_type: ?string, sub_id: ?int, member_id: ?int} */
    public function payload(): array
    {
        return ['account' => $this->accountId, 'debit' => $this->debit->minor, 'credit' => $this->credit->minor,
            'sub_type' => $this->subType, 'sub_id' => $this->subId, 'member_id' => $this->memberId];
    }
}
