<?php

namespace App\Services\Sacco\Ledger;

use App\Support\Sacco\Amount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An entry before it is posted (ADR 0009 §4): what PostingRules (or a person, for a manual journal) builds and
 * PostingService checks and writes. The idempotency key names the business event, so posting the same draft twice
 * (a retry, a webhook and a poll, a double click) gives one entry. Guards are closures PostingService runs after
 * it has locked the rows they read (an available balance, a share cap); one that throws stops the posting.
 */
final class JournalDraft
{
    /** @var list<JournalLine> */
    public array $lines = [];

    /** @var list<callable(): void> */
    public array $guards = [];

    public ?CarbonInterface $entryDate = null;

    public ?CarbonInterface $valueDate = null;

    public ?string $sourceType = null;

    public ?int $sourceId = null;

    public ?string $memo = null;

    public ?int $postedById = null;

    public ?int $approvalId = null;

    public ?int $reversesEntryId = null;

    public ?int $cycleId = null;

    private function __construct(public readonly int $companyId, public readonly string $kind, public readonly string $idempotencyKey)
    {
    }

    public static function make(int $companyId, string $kind, string $idempotencyKey): self
    {
        return new self($companyId, $kind, trim($idempotencyKey));
    }

    public function debit(int $accountId, Amount $amount, ?string $subType = null, ?int $subId = null, ?int $memberId = null, ?string $memo = null): self
    {
        $this->lines[] = new JournalLine($accountId, $amount, Amount::zero($amount->currency), $subType, $subId, $memberId, $memo);

        return $this;
    }

    public function credit(int $accountId, Amount $amount, ?string $subType = null, ?int $subId = null, ?int $memberId = null, ?string $memo = null): self
    {
        $this->lines[] = new JournalLine($accountId, Amount::zero($amount->currency), $amount, $subType, $subId, $memberId, $memo);

        return $this;
    }

    public function line(JournalLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /** The posting day (default: the SACCO's today) and the day the money moved (default: the posting day). */
    public function dated(CarbonInterface|string|null $entryDate, CarbonInterface|string|null $valueDate = null): self
    {
        $this->entryDate = $entryDate === null ? null : Carbon::parse($entryDate)->startOfDay();
        $this->valueDate = $valueDate === null ? null : Carbon::parse($valueDate)->startOfDay();

        return $this;
    }

    public function source(?string $type, ?int $id): self
    {
        [$this->sourceType, $this->sourceId] = [$type, $id];

        return $this;
    }

    public function memo(?string $memo): self
    {
        $this->memo = $memo === null ? null : mb_substr(trim($memo), 0, 255);

        return $this;
    }

    public function by(?int $userId): self
    {
        $this->postedById = $userId;

        return $this;
    }

    public function approval(?int $approvalId): self
    {
        $this->approvalId = $approvalId;

        return $this;
    }

    public function reverses(?int $entryId): self
    {
        $this->reversesEntryId = $entryId;

        return $this;
    }

    public function cycle(?int $cycleId): self
    {
        $this->cycleId = $cycleId;

        return $this;
    }

    /** @param  callable(): void  $guard */
    public function guard(callable $guard): self
    {
        $this->guards[] = $guard;

        return $this;
    }

    /**
     * What makes two drafts the same business event (the idempotency payload hash): the kind, the source, the value
     * date the draft names and the lines. The posting date is left out on purpose (and so is a value date left to
     * default to it), so a retry tomorrow is still a replay.
     */
    public function payloadHash(): string
    {
        return hash('sha256', (string) json_encode([
            'kind' => $this->kind, 'source_type' => $this->sourceType, 'source_id' => $this->sourceId, 'value_date' => $this->valueDate?->toDateString(),
            'reverses' => $this->reversesEntryId, 'lines' => array_map(fn (JournalLine $l) => $l->payload(), $this->lines),
        ]));
    }
}
