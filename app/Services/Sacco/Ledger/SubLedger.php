<?php

namespace App\Services\Sacco\Ledger;

/**
 * A member sub-ledger whose rows hang under control accounts (ADR 0009 §7): savings accounts under their product's
 * 21x0, share accounts under 3100, loans under 1300/1320/1330/1340. Journal lines on a control account name a row
 * (sub_type, sub_id), and the row's cached balance is the sum of those lines, kept by PostingService through
 * project() in the posting's own transaction and checked by LedgerVerifier through balances().
 *
 * Implementations are registered in SubLedgers by the module that owns the table (shares P1.11, savings P1.12,
 * loans P2), so the ledger itself never needs to know their tables.
 */
interface SubLedger
{
    /** The sub_type its lines carry (`savings`, `shares`, `loan`, `fine`, `fund_due`). */
    public function type(): string;

    /**
     * Lock these rows FOR UPDATE in ascending id order (deadlock avoidance) and describe the ones that exist in this
     * company: the control accounts each may be posted to, and its member.
     *
     * @param  list<int>  $ids  ascending
     * @return array<int, array{accounts: list<int>, member_id: ?int}>
     */
    public function lock(int $companyId, array $ids): array;

    /** Add a line's amount (signed, positive on the control account's normal side) to the row's cache for that account. */
    public function project(int $companyId, int $id, int $accountId, int $signedMinor): void;

    /**
     * Every row's cached balance per control account, for the verifier.
     *
     * @return list<array{sub_id: int, ledger_account_id: int, balance_minor: int}>
     */
    public function balances(int $companyId): array;
}
