<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoPeriod;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\ReversalService;
use App\Services\Sacco\Ledger\SubRef;
use App\Support\Sacco\Amount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLedger;
use Tests\Support\Sacco\PotLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §9): 500 seeded random steps across deposits, withdrawals (some refused for want of
 * money), fees, entrance fees, transfers, held receipts and their allocation, replays, reversals and month closes.
 * After every step every LedgerVerifier check holds: debits = credits, each cache equals its lines, the member
 * accounts add up to their control accounts, the hash chain is intact; and each member account equals a model
 * kept beside the books in plain integers.
 */
class LedgerPropertyTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    protected function tearDown(): void
    {
        $this->restorePots();
        parent::tearDown();
    }

    /** @return array<string, array{0: int}> */
    public static function seeds(): array
    {
        return ['seed 7' => [7], 'seed 20261021' => [20261021]];
    }

    /** @dataProvider seeds */
    public function test_the_books_stay_sound_over_random_postings(int $seed): void
    {
        mt_srand($seed);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-03 09:00:00', 'Africa/Kampala'));
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $this->usePots();
        $rules = new PostingRules($cid, 'office');
        $accounts = [ChartOfAccounts::id($cid, 'savings.voluntary'), ChartOfAccounts::id($cid, 'savings.compulsory')];
        $pots = [];
        $model = [];
        for ($i = 0; $i < 6; $i++) {
            $id = PotLedger::open($cid, $accounts[$i % 2], 100 + $i);
            $pots[] = new SubRef('savings', $id, $accounts[$i % 2], 100 + $i);
            $model[$id] = 0;
        }
        $held = [];
        $posted = [];
        $closed = 0;
        $ugx = fn (int $m) => Amount::of($m, 'UGX');

        for ($step = 0; $step < 250; $step++) {
            $roll = mt_rand(1, 100);
            $amount = mt_rand(1, 200) * 500;
            $pot = $pots[array_rand($pots)];
            try {
                if ($roll <= 30) {
                    $posted[] = (new PostingService())->post($rules->deposit("txn:d{$step}", $pot, $ugx($amount), mt_rand(0, 1) ? 'cash' : 'bank'));
                    $model[$pot->id] += $amount;
                } elseif ($roll <= 48) {
                    $draft = $rules->withdrawal("txn:w{$step}", $pot, $ugx($amount), 'cash')
                        ->guard(function () use ($pot, $amount) {
                            if (PotLedger::balance($pot->id) < $amount) {
                                throw BusinessRuleException::make('insufficient_available', 'Not enough.');
                            }
                        });
                    $posted[] = (new PostingService())->post($draft);
                    $model[$pot->id] -= $amount;
                } elseif ($roll <= 55) {
                    $to = $pots[array_rand($pots)];
                    if ($to->id === $pot->id || $model[$pot->id] < $amount) {
                        continue;
                    }
                    $posted[] = (new PostingService())->post($rules->internalTransfer("txn:t{$step}", $pot, $to, $ugx($amount)));
                    $model[$pot->id] -= $amount;
                    $model[$to->id] += $amount;
                } elseif ($roll <= 62 && $model[$pot->id] >= 1000) {
                    $posted[] = (new PostingService())->post($rules->fee("txn:f{$step}", $ugx(1000), $pot));
                    $model[$pot->id] -= 1000;
                } elseif ($roll <= 68) {
                    $posted[] = (new PostingService())->post($rules->entranceFee("txn:e{$step}", $pot->memberId, $ugx(20000), 'cash'));
                } elseif ($roll <= 74) {
                    $held[] = [(new PostingService())->post($rules->heldReceipt("txn:h{$step}", $ugx($amount))), $amount];
                } elseif ($roll <= 79 && $held !== []) {
                    [$h, $heldAmount] = array_shift($held);
                    $posted[] = (new PostingService())->post($rules->heldReceiptAllocation('apr:alloc'.$h->id, $pot, $ugx($heldAmount)));
                    $model[$pot->id] += $heldAmount;
                } elseif ($roll <= 85 && $posted !== []) {
                    // Replay a random earlier posting: nothing moves.
                    $replay = $posted[array_rand($posted)];
                    $this->assertSame((int) $replay->id, (int) (new PostingService())->post($this->redraft($replay))->id);
                } elseif ($roll <= 92 && $posted !== []) {
                    $target = $posted[array_rand($posted)];
                    if (SaccoJournalEntry::withoutGlobalScopes()->where('reverses_entry_id', $target->id)->exists()) {
                        continue;
                    }
                    $moves = $this->moves($target);
                    $ok = true;
                    foreach ($moves as $potId => $delta) {
                        $ok = $ok && $model[$potId] - $delta >= 0;
                    }
                    if (! $ok) {
                        continue;
                    }
                    (new ReversalService())->reverse($cid, (int) $target->id, 'Property step '.$step, null, $user->id);
                    foreach ($moves as $potId => $delta) {
                        $model[$potId] -= $delta;
                    }
                } else {
                    // Time passes; a month that has ended is closed and its snapshot must match the trial balance.
                    $this->travel(mt_rand(1, 9))->days();
                    (new PeriodService())->ensureAhead($cid, now(), 2);
                    $open = SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'open')->orderBy('starts_on')->first();
                    if ($open !== null && $open->ends_on->lt(\App\Support\LocalDate::today($cid))) {
                        $period = (new PeriodService())->close($cid, (int) $open->id, $user->id);
                        $closed++;
                        $sums = (new PeriodService())->sums($cid, null, $period->ends_on);
                        $this->assertSame(array_sum(array_column($sums, 0)), array_sum(array_column($sums, 1)), 'the trial balance at close totals zero');
                        $snap = $period->balances()->withoutGlobalScopes()->where('snapshot_no', $period->snapshot_no)->get();
                        $this->assertSame((int) $snap->sum('debits_minor'), (int) $snap->sum('credits_minor'));
                    }
                }
            } catch (BusinessRuleException $e) {
                $this->assertSame('insufficient_available', $e->errorCode(), "step {$step}: ".$e->getMessage());
            }

            $result = (new LedgerVerifier())->verify($cid);
            $this->assertSame([], LedgerVerifier::failed($result), "step {$step}: ".json_encode($result['checks']));
            if ($step % 25 === 0 || $step === 249) {
                foreach ($model as $potId => $balance) {
                    $this->assertSame($balance, PotLedger::balance($potId), "step {$step}: pot {$potId}");
                    $this->assertGreaterThanOrEqual(0, $balance);
                }
            }
        }
        $this->assertSame(array_sum($model), (int) SaccoAccountBalance::withoutGlobalScopes()->whereIn('ledger_account_id', $accounts)->sum('balance_minor'));
        $this->assertGreaterThan(0, $closed, 'months were closed along the way');
        $this->assertGreaterThan(50, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->count());
    }

    /** The draft that made an entry, rebuilt from its stored lines (a replay of the same event). */
    private function redraft(SaccoJournalEntry $e): \App\Services\Sacco\Ledger\JournalDraft
    {
        $d = \App\Services\Sacco\Ledger\JournalDraft::make((int) $e->company_id, $e->kind, $e->idempotency_key)->source($e->source_type, $e->source_id === null ? null : (int) $e->source_id);
        foreach (DB::table('sacco_journal_lines')->where('journal_entry_id', $e->id)->orderBy('line_no')->get() as $l) {
            $d->line(new \App\Services\Sacco\Ledger\JournalLine((int) $l->ledger_account_id, Amount::of((int) $l->debit_minor, 'UGX'), Amount::of((int) $l->credit_minor, 'UGX'),
                $l->sub_type, $l->sub_id === null ? null : (int) $l->sub_id, $l->member_id === null ? null : (int) $l->member_id, $l->memo));
        }

        return $d;
    }

    /** How an entry moved each pot (credit-normal: credit − debit). @return array<int, int> */
    private function moves(SaccoJournalEntry $e): array
    {
        $out = [];
        foreach (DB::table('sacco_journal_lines')->where('journal_entry_id', $e->id)->whereNotNull('sub_id')->get() as $l) {
            $out[(int) $l->sub_id] = ($out[(int) $l->sub_id] ?? 0) + (int) $l->credit_minor - (int) $l->debit_minor;
        }

        return $out;
    }
}
