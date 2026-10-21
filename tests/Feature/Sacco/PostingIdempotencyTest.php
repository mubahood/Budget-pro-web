<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoJournalEntry;
use App\Services\Sacco\Ledger\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\MakesSaccoLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §4.4, §9): one business event, one entry. The same key with the same lines is a
 * replay (today, tomorrow, by any family of key); the same key with other lines is `idempotency_conflict`.
 */
class PostingIdempotencyTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    public function test_every_key_family_posts_once_and_replays(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $keys = ['txn:'.\Illuminate\Support\Str::uuid(), 'apr:'.\Illuminate\Support\Str::uuid(), 'int:5:1:3', 'pen:5:202610', 'prov:202610', 'sint:9',
            'momo:BP-123', 'payout:4:sent', 'payout:4:settled', 'payout:4:failed', 'close:2026', 'approp:2026', 'shareout:2', 'open:7:12'];
        foreach ($keys as $i => $key) {
            $first = (new PostingService())->post($this->simple($cid, $key, 1000 + $i));
            $again = (new PostingService())->post($this->simple($cid, $key, 1000 + $i));
            $this->assertSame((int) $first->id, (int) $again->id, $key);
        }
        $this->assertSame(count($keys), SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(array_sum(array_map(fn ($i) => 1000 + $i, array_keys($keys))),
            (int) \App\Models\SaccoAccountBalance::withoutGlobalScopes()->where('ledger_account_id', \App\Services\Sacco\Ledger\ChartOfAccounts::id($cid, 'cash.office'))->value('balance_minor'),
            'replays never move a cache');
    }

    public function test_a_replay_tomorrow_is_still_a_replay(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $first = (new PostingService())->post($this->simple($cid, 'txn:late', 5000)->dated(null, now()->toDateString()));
        $this->travel(1)->days();
        $this->assertSame((int) $first->id, (int) (new PostingService())->post($this->simple($cid, 'txn:late', 5000)->dated(null, $first->value_date))->id);
    }

    public function test_the_same_key_with_other_lines_is_a_conflict(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $entry = (new PostingService())->post($this->simple($cid, 'txn:x', 5000));
        foreach ([$this->simple($cid, 'txn:x', 5001), $this->simple($cid, 'txn:x', 5000, 'bank.main'), $this->simple($cid, 'txn:x', 5000)->source('sacco_transactions', 9)] as $other) {
            try {
                (new PostingService())->post($other);
                $this->fail('a different payload under a used key');
            } catch (BusinessRuleException $e) {
                $this->assertSame('idempotency_conflict', $e->errorCode());
                $this->assertSame((int) $entry->id, $e->meta()['entry_id']);
            }
        }
        // The key is per SACCO: another SACCO may use the same one.
        [$other] = $this->saccoBooks();
        $this->assertNotSame((int) $entry->id, (int) (new PostingService())->post($this->simple((int) $other->id, 'txn:x', 5000))->id);
    }
}
