<?php

namespace App\Services\Sacco\Ledger;

/** A member account a line moves: its sub-ledger row and the control account it sits under. */
final class SubRef
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly int $accountId,
        public readonly ?int $memberId = null,
    ) {
    }
}
