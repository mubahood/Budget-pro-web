<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerAccount;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\ReversalService;
use App\Services\Sacco\Ledger\SubRef;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLedger;
use Tests\Support\Sacco\PotLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §4, §5): the one writer of the books. A bad draft is refused before anything is
 * written (no entry, no number used, no cache moved); a good one moves the account and sub-ledger caches by
 * exactly its lines; a mistake is corrected by its mirror, once.
 */
class PostingServiceTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    protected function tearDown(): void
    {
        $this->restorePots();
        parent::tearDown();
    }

    private function refused(string $code, callable $fn, int $companyId): void
    {
        $entries = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->count();
        $seq = DB::table('number_sequences')->where('company_id', $companyId)->where('kind', 'je')->value('last_value');
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
        $this->assertSame($entries, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->count(), "{$code}: nothing written");
        $this->assertSame($seq, DB::table('number_sequences')->where('company_id', $companyId)->where('kind', 'je')->value('last_value'), "{$code}: no number used");
        $this->assertSame(0, (int) SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)->sum('debit_total_minor'), "{$code}: no cache moved");
    }

    public function test_a_bad_draft_is_refused_before_any_write(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $cash = ChartOfAccounts::id($cid, 'cash.office');
        $fees = ChartOfAccounts::id($cid, 'inc.entrance');
        $post = fn (JournalDraft $d) => (new PostingService())->post($d);

        $this->refused('unbalanced_entry', fn () => $post(JournalDraft::make($cid, 'manual', 'k1')->debit($cash, $this->ugx(1000))->credit($fees, $this->ugx(999))), $cid);
        $this->refused('unbalanced_entry', fn () => $post(JournalDraft::make($cid, 'manual', 'k2')->debit($cash, $this->ugx(1000))), $cid);
        $this->refused('invalid_line', fn () => $post(JournalDraft::make($cid, 'manual', 'k3')->debit($cash, $this->ugx(0))->credit($fees, $this->ugx(0))), $cid);
        $this->refused('invalid_line', fn () => $post(JournalDraft::make($cid, 'manual', 'k4')->debit($cash, $this->ugx(-5))->credit($fees, $this->ugx(-5))), $cid);
        $this->refused('currency_mismatch', fn () => $post(JournalDraft::make($cid, 'manual', 'k5')->debit($cash, $this->ugx(100, 'KES'))->credit($fees, $this->ugx(100, 'KES'))), $cid);
        $this->refused('idempotency_key_required', fn () => $post($this->simple($cid, '  ', 100)), $cid);
        $this->refused('invalid_entry_kind', fn () => $post(JournalDraft::make($cid, 'magic', 'k6')->debit($cash, $this->ugx(1))->credit($fees, $this->ugx(1))), $cid);
        $this->refused('invalid_value_date', fn () => $post($this->simple($cid, 'k7', 100)->dated(now()->subDay(), now())), $cid);

        // Accounts: another SACCO's, a header, an inactive one; control accounts need their member account and others refuse one.
        [$other] = $this->saccoBooks();
        $theirs = ChartOfAccounts::id((int) $other->id, 'inc.entrance');
        $this->refused('account_not_found', fn () => $post(JournalDraft::make($cid, 'manual', 'k8')->debit($cash, $this->ugx(1))->credit($theirs, $this->ugx(1))), $cid);
        $header = (int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '1000')->value('id');
        $this->refused('account_not_postable', fn () => $post(JournalDraft::make($cid, 'manual', 'k9')->debit($header, $this->ugx(1))->credit($fees, $this->ugx(1))), $cid);
        SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '4900')->update(['is_active' => false]);
        $inactive = (int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '4900')->value('id');
        $this->refused('account_not_postable', fn () => $post(JournalDraft::make($cid, 'manual', 'k10')->debit($cash, $this->ugx(1))->credit($inactive, $this->ugx(1))), $cid);
        $savings = ChartOfAccounts::id($cid, 'savings.voluntary');
        $this->refused('sub_ledger_required', fn () => $post(JournalDraft::make($cid, 'manual', 'k11')->debit($cash, $this->ugx(1))->credit($savings, $this->ugx(1))), $cid);
        $this->refused('sub_ledger_required', fn () => $post(JournalDraft::make($cid, 'manual', 'k12')->debit($cash, $this->ugx(1))->credit($fees, $this->ugx(1), 'savings', 1)), $cid);
        // Savings is registered (P1.12): a row it does not have is refused; a type nobody registered yet (fines, P5) is unavailable.
        $this->refused('sub_account_not_found', fn () => $post(JournalDraft::make($cid, 'manual', 'k13')->debit($cash, $this->ugx(1))->credit($savings, $this->ugx(1), 'savings', 999999999)), $cid);
        $finesAccount = ChartOfAccounts::id($cid, 'fines.receivable');
        $this->refused('sub_ledger_unavailable', fn () => $post(JournalDraft::make($cid, 'manual', 'k13b')->debit($finesAccount, $this->ugx(1), 'fine', 1)->credit($cash, $this->ugx(1))), $cid);

        $this->usePots();
        $compulsory = ChartOfAccounts::id($cid, 'savings.compulsory');
        $pot = PotLedger::open($cid, $savings);
        $theirPot = PotLedger::open((int) $other->id, ChartOfAccounts::id((int) $other->id, 'savings.voluntary'));
        $this->refused('wrong_control_account', fn () => $post(JournalDraft::make($cid, 'manual', 'k14')->debit($cash, $this->ugx(1))->credit($compulsory, $this->ugx(1), 'savings', $pot)), $cid);
        $this->refused('sub_account_not_found', fn () => $post(JournalDraft::make($cid, 'manual', 'k15')->debit($cash, $this->ugx(1))->credit($savings, $this->ugx(1), 'savings', $theirPot)), $cid);

        // A guard that fails stops the posting after the locks, still before any write.
        $this->refused('insufficient_available', fn () => $post($this->simple($cid, 'k16', 100)->guard(fn () => throw BusinessRuleException::make('insufficient_available', 'No.'))), $cid);

        $bare = Company::factory()->create(['name' => 'No books', 'status' => 'Active']);
        $this->refused('ledger_not_set_up', fn () => $post(JournalDraft::make((int) $bare->id, 'manual', 'k17')->debit($cash, $this->ugx(1))->credit($fees, $this->ugx(1))), (int) $bare->id);
    }

    public function test_a_posting_moves_the_caches_by_its_lines_and_chains_its_hash(): void
    {
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $this->usePots();
        $savings = ChartOfAccounts::id($cid, 'savings.voluntary');
        $pot = PotLedger::open($cid, $savings, 77);

        $entry = (new PostingService())->post(JournalDraft::make($cid, 'deposit', 'txn:a')->by($user->id)->memo('Deposit')
            ->debit(ChartOfAccounts::id($cid, 'cash.office'), $this->ugx(50000))
            ->credit($savings, $this->ugx(50000), 'savings', $pot));
        $this->assertSame('JE-'.now()->year.'-000001', $entry->entry_no);
        $this->assertSame(50000, $entry->total_minor);
        $this->assertSame(PostingService::GENESIS, $entry->prev_hash);
        $this->assertSame(77, $entry->lines[1]->member_id, 'the line names the member of its account');
        $this->assertSame(50000, PotLedger::balance($pot));
        $this->assertSame(50000, (int) SaccoAccountBalance::withoutGlobalScopes()->where('ledger_account_id', $savings)->value('balance_minor'));
        $this->assertSame(50000, (int) SaccoAccountBalance::withoutGlobalScopes()->where('ledger_account_id', ChartOfAccounts::id($cid, 'cash.office'))->value('balance_minor'));

        $second = (new PostingService())->post($this->simple($cid, 'fee:1', 20000));
        $this->assertSame($entry->hash, $second->prev_hash);
        $head = DB::table('sacco_ledger_heads')->where('company_id', $cid)->first();
        $this->assertSame($second->hash, $head->last_hash);
        $this->assertSame(2, (int) $head->entries_count);
    }

    public function test_reversal_mirrors_the_entry_once_and_is_never_reversed(): void
    {
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $this->usePots();
        $savings = ChartOfAccounts::id($cid, 'savings.voluntary');
        $pot = PotLedger::open($cid, $savings);
        $rules = new \App\Services\Sacco\Ledger\PostingRules($cid, 'office');
        $entry = (new PostingService())->post($rules->deposit('txn:d1', new SubRef('savings', $pot, $savings), $this->ugx(30000), 'cash'));

        $reversal = (new ReversalService())->reverse($cid, (int) $entry->id, 'Wrong member', null, $user->id);
        $this->assertSame('reversal', $reversal->kind);
        $this->assertSame((int) $entry->id, (int) $reversal->reverses_entry_id);
        $this->assertSame('rev:'.$entry->id, $reversal->idempotency_key);
        $this->assertStringContainsString('Wrong member', $reversal->memo);
        $this->assertSame([30000, 0], [$reversal->lines[0]->credit_minor, $reversal->lines[0]->debit_minor], 'debits and credits swap');
        $this->assertSame(0, PotLedger::balance($pot));
        $this->assertSame(0, (int) SaccoAccountBalance::withoutGlobalScopes()->where('ledger_account_id', $savings)->value('balance_minor'));
        $this->assertSame((int) $reversal->id, (int) (new ReversalService())->reverse($cid, (int) $entry->id, 'Again')->id, 'asking twice replays the one reversal');

        foreach ([['reversal_of_reversal', fn () => (new ReversalService())->reverse($cid, (int) $reversal->id, 'Undo')],
            ['reason_required', fn () => (new ReversalService())->reverse($cid, (int) $entry->id, ' ')],
            ['entry_not_found', fn () => (new ReversalService())->reverse((int) $this->saccoBooks()[0]->id, (int) $entry->id, 'Not mine')]] as [$code, $fn]) {
            try {
                $fn();
                $this->fail("expected {$code}");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('reverses_entry_id', $entry->id)->count());
    }

    public function test_posting_rules_build_the_documented_lines(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $rules = new \App\Services\Sacco\Ledger\PostingRules($cid, 'office');
        $sav = new SubRef('savings', 5, ChartOfAccounts::id($cid, 'savings.voluntary'), 9);
        $sav2 = new SubRef('savings', 6, ChartOfAccounts::id($cid, 'savings.compulsory'), 9);
        $sh = new SubRef('shares', 3, ChartOfAccounts::id($cid, 'shares.capital'), 9);
        $sh2 = new SubRef('shares', 4, ChartOfAccounts::id($cid, 'shares.capital'), 10);
        $code = fn (int $id) => SaccoLedgerAccount::withoutGlobalScopes()->find($id)->code;
        $shape = fn (JournalDraft $d) => array_map(fn ($l) => [$code($l->accountId), $l->debit->minor, $l->credit->minor, $l->subType], $d->lines);
        $a = $this->ugx(1000);

        $this->assertSame([['1010', 1000, 0, null], ['2110', 0, 1000, 'savings']], $shape($rules->deposit('x', $sav, $a, 'cash')));
        $this->assertSame([['1100', 1000, 0, null], ['2110', 0, 1000, 'savings']], $shape($rules->deposit('x', $sav, $a, 'bank')));
        $this->assertSame([['2110', 1000, 0, 'savings'], ['1010', 0, 1000, null]], $shape($rules->withdrawal('x', $sav, $a, 'cash')));
        $this->assertSame([['2110', 1000, 0, 'savings'], ['4420', 0, 1000, null]], $shape($rules->fee('x', $a, $sav)));
        $this->assertSame([['1010', 1000, 0, null], ['4420', 0, 1000, null]], $shape($rules->fee('x', $a, null, 'cash', 9)));
        $this->assertSame([['1010', 1000, 0, null], ['3100', 0, 1000, 'shares']], $shape($rules->sharePurchase('x', $sh, $a, 'cash')));
        $this->assertSame([['3100', 1000, 0, 'shares'], ['3100', 0, 1000, 'shares']], $shape($rules->shareTransfer('x', $sh, $sh2, $a)));
        $this->assertSame([['1010', 1000, 0, null], ['4400', 0, 1000, null]], $shape($rules->entranceFee('x', 9, $a, 'cash')));
        $this->assertSame([['2110', 1000, 0, 'savings'], ['2120', 0, 1000, 'savings']], $shape($rules->internalTransfer('x', $sav, $sav2, $a)));
        $this->assertSame([['1010', 1000, 0, null], ['2150', 0, 1000, null]], $shape($rules->heldReceipt('x', $a)));
        $this->assertSame([['2150', 1000, 0, null], ['2110', 0, 1000, 'savings']], $shape($rules->heldReceiptAllocation('x', $sav, $a)));

        [$group] = $this->saccoBooks('group');
        $g = new \App\Services\Sacco\Ledger\PostingRules((int) $group->id, 'group');
        $this->assertSame('1020', $code($g->deposit('x', new SubRef('savings', 1, ChartOfAccounts::id((int) $group->id, 'savings.voluntary')), $a, 'cash')->lines[0]->accountId), 'a group keeps its cash in the lockbox');
        try {
            $g->fee('x', $a, null, 'cash');
            $this->fail('a group has no fees');
        } catch (BusinessRuleException $e) {
            $this->assertSame('not_in_group_mode', $e->errorCode());
        }
    }
}
