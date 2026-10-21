<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Support\Sacco\Amount;

/**
 * Which accounts each money event debits and credits (docs/akiba/04-architecture.md §5.1), for the P1 kinds. Each
 * rule builds a JournalDraft for PostingService and nothing else: no other code decides a posting's lines.
 *
 * Accounts the books post to are found by system key in the SACCO's own chart; the cash account follows the mode
 * (the office till in office mode, the lockbox in group mode). Member accounts are passed in as SubRef (the
 * owning service knows its row's control account: a savings account's product account, 3100 for shares), and
 * PostingService checks the row really sits under it.
 *
 * Every rule takes the event's idempotency key (`txn:{uuid}`, `apr:{uuid}`… ADR 0009 §4.4).
 */
final class PostingRules
{
    /** Channels per kind of movement in P1: what a teller can take in or pay out. */
    public const RECEIPT_CHANNELS = ['cash', 'bank'];

    /** The P1 kinds that exist in group mode (its chart has no 4420 Other fees: a group charges fines, P5). */
    public const GROUP_KINDS = ['deposit', 'withdrawal', 'share_purchase', 'share_transfer', 'entrance_fee', 'internal_transfer', 'held_receipt', 'held_receipt_allocation', 'manual', 'reversal'];

    /** The system accounts each P1 rule may use, per mode (ChartOfAccountsTest proves each chart has them). */
    public const SYSTEM_KEYS = [
        'office' => ['cash.office', 'bank.main', 'inc.fees', 'inc.entrance', 'suspense.receipts'],
        'group' => ['cash.box', 'bank.main', 'inc.entrance', 'suspense.receipts'],
    ];

    public function __construct(private readonly int $companyId, private readonly string $mode)
    {
        if (! in_array($mode, ChartOfAccounts::MODES, true)) {
            throw BusinessRuleException::make('invalid_mode', 'Choose office mode or group mode.');
        }
    }

    /** The account money comes in by or goes out by. */
    public function channelAccount(string $channel): int
    {
        return match ($channel) {
            'cash' => ChartOfAccounts::id($this->companyId, $this->mode === 'group' ? 'cash.box' : 'cash.office'),
            'bank' => ChartOfAccounts::id($this->companyId, 'bank.main'),
            default => throw BusinessRuleException::make('channel_not_available', "Money cannot move by {$channel} here yet."),
        };
    }

    /** Cash or bank into a member's savings: Dr cash/bank, Cr the product's 21x0. */
    public function deposit(string $key, SubRef $savings, Amount $amount, string $channel): JournalDraft
    {
        return $this->draft('deposit', $key)->debit($this->channelAccount($channel), $amount, memberId: $savings->memberId)
            ->credit($savings->accountId, $amount, $savings->type, $savings->id, $savings->memberId);
    }

    /** Out of a member's savings in cash or by bank: Dr 21x0, Cr cash/bank. */
    public function withdrawal(string $key, SubRef $savings, Amount $amount, string $channel): JournalDraft
    {
        return $this->draft('withdrawal', $key)->debit($savings->accountId, $amount, $savings->type, $savings->id, $savings->memberId)
            ->credit($this->channelAccount($channel), $amount, memberId: $savings->memberId);
    }

    /** A fee (withdrawal, statement, passbook) taken from savings, or paid at the counter: Dr 21x0 or cash, Cr 4420. */
    public function fee(string $key, Amount $amount, ?SubRef $fromSavings = null, ?string $channel = null, ?int $memberId = null): JournalDraft
    {
        $this->office('fee');
        $draft = $this->draft('fee', $key);
        $draft = $fromSavings !== null
            ? $draft->debit($fromSavings->accountId, $amount, $fromSavings->type, $fromSavings->id, $fromSavings->memberId)
            : $draft->debit($this->channelAccount($channel ?? 'cash'), $amount, memberId: $memberId);

        return $draft->credit(ChartOfAccounts::id($this->companyId, 'inc.fees'), $amount, memberId: $fromSavings?->memberId ?? $memberId);
    }

    /** Shares bought: Dr cash/bank, Cr 3100 on the member's share account. */
    public function sharePurchase(string $key, SubRef $shares, Amount $amount, string $channel): JournalDraft
    {
        return $this->draft('share_purchase', $key)->debit($this->channelAccount($channel), $amount, memberId: $shares->memberId)
            ->credit($shares->accountId, $amount, $shares->type, $shares->id, $shares->memberId);
    }

    /** Shares moved from member A to member B: Dr 3100 (A), Cr 3100 (B). */
    public function shareTransfer(string $key, SubRef $from, SubRef $to, Amount $amount): JournalDraft
    {
        return $this->draft('share_transfer', $key)->debit($from->accountId, $amount, $from->type, $from->id, $from->memberId)
            ->credit($to->accountId, $amount, $to->type, $to->id, $to->memberId);
    }

    /** The entrance fee: Dr cash/bank, Cr 4400. */
    public function entranceFee(string $key, int $memberId, Amount $amount, string $channel): JournalDraft
    {
        return $this->draft('entrance_fee', $key)->debit($this->channelAccount($channel), $amount, memberId: $memberId)
            ->credit(ChartOfAccounts::id($this->companyId, 'inc.entrance'), $amount, memberId: $memberId);
    }

    /** Between a member's own savings accounts: Dr 21x0 (from), Cr 21x0 (to). */
    public function internalTransfer(string $key, SubRef $from, SubRef $to, Amount $amount): JournalDraft
    {
        if ($from->id === $to->id && $from->type === $to->type) {
            throw BusinessRuleException::make('same_account', 'Choose two different accounts.');
        }

        return $this->draft('internal_transfer', $key)->debit($from->accountId, $amount, $from->type, $from->id, $from->memberId)
            ->credit($to->accountId, $amount, $to->type, $to->id, $to->memberId);
    }

    /** A cash receipt that cannot reach its account yet (AK-24): Dr cash, Cr 2150 Unallocated receipts. */
    public function heldReceipt(string $key, Amount $amount, ?int $memberId = null): JournalDraft
    {
        return $this->draft('held_receipt', $key)->debit($this->channelAccount('cash'), $amount, memberId: $memberId)
            ->credit(ChartOfAccounts::id($this->companyId, 'suspense.receipts'), $amount, memberId: $memberId);
    }

    /** A held receipt allocated (by approval): Dr 2150, Cr the member account it belonged to. */
    public function heldReceiptAllocation(string $key, SubRef $target, Amount $amount): JournalDraft
    {
        return $this->draft('held_receipt_allocation', $key)->debit(ChartOfAccounts::id($this->companyId, 'suspense.receipts'), $amount, memberId: $target->memberId)
            ->credit($target->accountId, $amount, $target->type, $target->id, $target->memberId);
    }

    private function draft(string $kind, string $key): JournalDraft
    {
        if ($this->mode === 'group' && ! in_array($kind, self::GROUP_KINDS, true)) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group does not record this; use a fine instead.');
        }

        return JournalDraft::make($this->companyId, $kind, $key);
    }

    private function office(string $kind): void
    {
        if ($this->mode !== 'office') {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group does not record '.str_replace('_', ' ', $kind).'s; use a fine instead.');
        }
    }
}
