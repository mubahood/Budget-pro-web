<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSavingsProductVersion;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.12 (04 §3.3, §5.1, §5.2, §7): savings products and their versions, accounts, and the money
 * documents for deposits, withdrawals (maker-checker above the threshold, always for a dormant account), fees,
 * transfers and reversals (approved, once). The sub-ledger always equals its control account.
 */
class SavingsTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    private function cash(Company $c): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'cash.office'))->value('balance_minor');
    }

    private function control(Company $c, string $key = 'savings.voluntary'): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, $key))->value('balance_minor');
    }

    private function assertBooksSound(Company $c): void
    {
        $r = (new LedgerVerifier())->verify((int) $c->id);
        $this->assertSame([], LedgerVerifier::failed($r), json_encode($r['checks']));
    }

    public function test_setup_creates_the_default_products_with_their_first_version(): void
    {
        $s = $this->saccoCompany();
        $products = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $s['company']->id)->orderBy('code')->get();
        $this->assertSame(['CMP', 'ORD'], $products->pluck('code')->all());
        $this->assertFalse($products[0]->withdrawable, 'compulsory savings are not withdrawable');
        $this->assertSame(ChartOfAccounts::id((int) $s['company']->id, 'savings.compulsory'), (int) $products[0]->ledger_account_id);
        $this->assertSame(2, SaccoSavingsProductVersion::withoutGlobalScopes()->where('company_id', $s['company']->id)->count());

        $g = $this->saccoCompany('group');
        $this->assertSame(['GRP'], SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $g['company']->id)->pluck('code')->all());
        $this->assertSame('not_in_group_mode', $this->code(fn () => app(SavingsProducts::class)->create($g['company'], ['code' => 'X', 'name' => 'Fee', 'kind' => 'voluntary', 'withdrawal_fee_minor' => 500], $g['owner'])));
    }

    public function test_a_deposit_posts_once_per_uuid_and_the_sub_ledger_equals_its_control(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = $this->activeMember($c, $s['owner']);
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $this->assertMatchesRegularExpression('/^SAV-\d{6}$/', $a->account_no);
        $uuid = (string) Str::uuid();
        $tx = $this->deposit($c, $a, 150000, $s['teller'], ['uuid' => $uuid]);
        $this->assertSame('posted', $tx->status);
        $this->assertMatchesRegularExpression('/^RCT-\d{4}-\d{6}$/', $tx->receipt_no);
        $again = app(SaccoTransactions::class)->record($c, ['kind' => 'deposit', 'savings_account_id' => $a->id, 'amount_minor' => 150000, 'uuid' => $uuid], $s['teller']);
        $this->assertTrue($again['replayed']);
        $this->assertSame('idempotency_conflict', $this->code(fn () => $this->deposit($c, $a, 150001, $s['teller'], ['uuid' => $uuid])));
        $this->assertSame(150000, $this->balanceOf((int) $a->id));
        $this->assertSame(150000, $this->cash($c));
        $this->assertSame(150000, $this->control($c));
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $c->id)->where('kind', 'deposit')->count());
        $this->assertSame('ledger_immutable', $this->code(fn () => SaccoSavingsAccount::withoutGlobalScopes()->find($a->id)->fill(['balance_minor' => 1])->save()));
        $this->assertSame('immutable_event', $this->code(fn () => $tx->fresh()->fill(['amount_minor' => 1])->save()));
        $this->assertSame('forbidden', $this->code(fn () => $this->deposit($c, $a, 1000, $this->saccoStaff($c, 'sacco_auditor'))));
        $this->assertBooksSound($c);
    }

    public function test_withdrawals_post_under_the_threshold_and_wait_for_a_second_officer_above_it(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['teller']);
        $this->deposit($c, $a, 3_000_000, $s['teller']);

        $small = $this->withdraw($c, $a, 200_000, $s['teller']);
        $this->assertSame('posted', $small->status);
        $this->assertSame(2_800_000, $this->balanceOf((int) $a->id));

        $big = $this->withdraw($c, $a, 1_500_000, $s['teller']);
        $this->assertSame('pending_approval', $big->status);
        $this->assertNull($big->receipt_no);
        $this->assertSame(2_800_000, $this->balanceOf((int) $a->id), 'nothing moves before approval');
        $this->assertSame(1_300_000, SavingsAccounts::available((int) $c->id, (int) $a->id), 'the waiting withdrawal is reserved');
        $this->assertSame('insufficient_available', $this->code(fn () => $this->withdraw($c, $a, 1_400_000, $s['teller'])));
        $approval = SaccoApproval::withoutGlobalScopes()->find($big->approval_id);
        $this->assertSame('withdrawal', $approval->action);
        $this->assertSame(['above_threshold'], $approval->payload['reasons']);
        $this->assertSame('forbidden', $this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $approval->id, $s['teller'])), 'a teller cannot check');
        $done = app(SaccoApprovals::class)->approve($c, (int) $approval->id, $s['treasurer']);
        $this->assertSame('executed', $done->status);
        $big->refresh();
        $this->assertSame('posted', $big->status);
        $this->assertNotNull($big->receipt_no);
        $this->assertSame(1_300_000, $this->balanceOf((int) $a->id));
        $this->assertSame(3_000_000 - 1_700_000, $this->cash($c));

        $rejected = $this->withdraw($c, $a, 1_100_000, $s['teller']);
        app(SaccoApprovals::class)->reject($c, (int) $rejected->approval_id, $s['treasurer'], 'No such request from the member');
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame(1_300_000, SavingsAccounts::available((int) $c->id, (int) $a->id));
        $this->assertBooksSound($c);
    }

    public function test_an_approved_withdrawal_whose_money_is_gone_stays_approved_and_posts_nothing(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['teller']);
        $this->deposit($c, $a, 2_000_000, $s['teller']);
        $big = $this->withdraw($c, $a, 1_500_000, $s['teller']);
        // A lien (P2 guarantee) lands after the request: the money is no longer available.
        DB::table('sacco_savings_accounts')->where('id', $a->id)->update(['held_minor' => 1_000_000]);
        $this->assertSame('insufficient_available', $this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $big->approval_id, $s['treasurer'])));
        $approval = SaccoApproval::withoutGlobalScopes()->find($big->approval_id);
        $this->assertSame('approved', $approval->status);
        $this->assertNotNull($approval->last_error);
        $this->assertSame('pending_approval', $big->fresh()->status);
        $this->assertSame(2_000_000, $this->balanceOf((int) $a->id));
        DB::table('sacco_savings_accounts')->where('id', $a->id)->update(['held_minor' => 0]);
        $this->assertSame('executed', app(SaccoApprovals::class)->execute($c, (int) $big->approval_id, $s['treasurer'])->status);
        $this->assertSame(500_000, $this->balanceOf((int) $a->id));
    }

    public function test_a_dormant_account_always_needs_approval_and_the_rules_of_each_product_hold(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = $this->activeMember($c, $s['owner']);
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $this->deposit($c, $a, 500_000, $s['teller']);
        app(SavingsAccounts::class)->markDormant($c, (int) $a->id, $s['treasurer']);
        $tx = $this->withdraw($c, $a, 1_000, $s['teller']);
        $this->assertSame('pending_approval', $tx->status);
        $this->assertContains('dormant', SaccoApproval::withoutGlobalScopes()->find($tx->approval_id)->payload['reasons']);
        $this->deposit($c, $a, 1_000, $s['teller']);
        $this->assertSame('active', $a->fresh()->status, 'the member\'s own deposit wakes the account');

        $cmp = $this->savingsAccount($c, $m, $s['teller'], 'CMP');
        $this->deposit($c, $cmp, 100_000, $s['teller']);
        $this->assertSame('not_withdrawable', $this->code(fn () => $this->withdraw($c, $cmp, 1_000, $s['teller'])));
        $this->assertSame(100_000, $this->control($c, 'savings.compulsory'));
        $this->assertSame('account_exists', $this->code(fn () => $this->savingsAccount($c, $m, $s['teller'], 'CMP')));

        app(SavingsAccounts::class)->freeze($c, (int) $a->id, 'Court order', $s['treasurer']);
        $this->assertSame('account_frozen', $this->code(fn () => $this->withdraw($c, $a, 1_000, $s['teller'])));
        $this->assertSame('posted', $this->deposit($c, $a, 1_000, $s['teller'])->status, 'deposits still come in');
        $this->assertSame('account_not_empty', $this->code(fn () => app(SavingsAccounts::class)->close($c, (int) $a->id, 'Member asked', $s['treasurer'])));
        $this->assertSame('forbidden', $this->code(fn () => app(SavingsAccounts::class)->unfreeze($c, (int) $a->id, $s['teller'])));
    }

    public function test_fees_transfers_and_the_product_withdrawal_fee_post_their_lines(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $p = app(SavingsProducts::class)->create($c, ['code' => 'FEE', 'name' => 'Holiday savings', 'kind' => 'voluntary', 'withdrawal_fee_minor' => 2_000, 'max_withdrawals_per_month' => 1], $s['owner']);
        $m = $this->activeMember($c, $s['owner']);
        $a = app(SavingsAccounts::class)->open($c, (int) $m->id, (int) $p->id, $s['teller']);
        $ord = $this->savingsAccount($c, $m, $s['teller']);
        $this->deposit($c, $a, 100_000, $s['teller']);
        $w = $this->withdraw($c, $a, 50_000, $s['teller']);
        $this->assertSame(2_000, $w->fee_minor);
        $this->assertSame(48_000, $this->balanceOf((int) $a->id));
        $fees = ChartOfAccounts::id((int) $c->id, 'inc.fees');
        $this->assertSame(2_000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', $fees)->value('balance_minor'));
        $this->assertSame('withdrawal_limit', $this->code(fn () => $this->withdraw($c, $a, 1_000, $s['teller'])));

        $t = app(SaccoTransactions::class)->record($c, ['kind' => 'internal_transfer', 'savings_account_id' => $a->id, 'counter_account_id' => $ord->id, 'amount_minor' => 40_000], $s['teller'])['transaction'];
        $this->assertSame('internal', $t->channel);
        $this->assertSame([8_000, 40_000], [$this->balanceOf((int) $a->id), $this->balanceOf((int) $ord->id)]);
        $other = $this->savingsAccount($c, $this->activeMember($c, $s['owner'], 'Opio', 'Sam'), $s['teller']);
        $this->assertSame('not_own_account', $this->code(fn () => app(SaccoTransactions::class)->record($c, ['kind' => 'internal_transfer', 'savings_account_id' => $ord->id,
            'counter_account_id' => $other->id, 'amount_minor' => 1_000], $s['teller'])));
        app(SaccoTransactions::class)->record($c, ['kind' => 'fee', 'savings_account_id' => $ord->id, 'amount_minor' => 1_500, 'narration' => 'Passbook'], $s['teller']);
        $this->assertSame(38_500, $this->balanceOf((int) $ord->id));
        $this->assertBooksSound($c);
    }

    public function test_a_reversal_needs_a_second_officer_happens_once_and_never_overdraws(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['teller']);
        $dep = $this->deposit($c, $a, 80_000, $s['teller']);
        $this->assertSame('reverse_the_receipt', $this->code(fn () => app(SaccoApprovals::class)->request($c, 'reversal', ['entry_id' => $dep->journal_entry_id, 'reason' => 'x'], $s['teller'])));
        $req = app(SaccoTransactions::class)->reverse($c, (int) $dep->id, 'Typed the wrong account', $s['teller']);
        $this->assertSame(80_000, $this->balanceOf((int) $a->id), 'nothing moves until approved');
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer']);
        $rev = SaccoTransaction::withoutGlobalScopes()->where('reverses_id', $dep->id)->firstOrFail();
        $this->assertSame('reversal', $rev->kind);
        $this->assertSame(0, $this->balanceOf((int) $a->id));
        $this->assertSame('posted', $dep->fresh()->status, 'the original stays as it was');
        $this->assertSame('already_reversed', $this->code(fn () => app(SaccoTransactions::class)->reverse($c, (int) $dep->id, 'Again', $s['teller'])));
        $this->assertSame('reversal_of_reversal', $this->code(fn () => app(SaccoTransactions::class)->reverse($c, (int) $rev->id, 'Undo', $s['teller'])));
        $this->assertSame('executed', app(SaccoApprovals::class)->execute($c, (int) $req->id, $s['treasurer'])->status, 'executing again returns the first result');
        $this->assertSame(1, SaccoTransaction::withoutGlobalScopes()->where('reverses_id', $dep->id)->count());

        $dep2 = $this->deposit($c, $a, 50_000, $s['teller']);
        $this->withdraw($c, $a, 40_000, $s['teller']);
        $req2 = app(SaccoTransactions::class)->reverse($c, (int) $dep2->id, 'Bounced cheque', $s['teller']);
        $this->assertSame('insufficient_balance', $this->code(fn () => app(SaccoApprovals::class)->approve($c, (int) $req2->id, $s['treasurer'])));
        $this->assertSame(10_000, $this->balanceOf((int) $a->id));
        $this->assertBooksSound($c);
    }

    public function test_a_rate_cut_waits_thirty_days_and_terms_never_change_in_place(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $p = app(SavingsProducts::class)->create($c, ['code' => 'INT', 'name' => 'Interest savings', 'kind' => 'voluntary', 'interest_rate_bp' => 600], $s['owner']);
        $this->assertSame('terms_need_approval', $this->code(fn () => app(SavingsProducts::class)->update($c, (int) $p->id, ['interest_rate_bp' => 500], $s['owner'])));
        $this->assertSame('notice_too_short', $this->code(fn () => app(SavingsProducts::class)->requestTermsChange($c, (int) $p->id, ['interest_rate_bp' => 500], $s['owner'],
            LocalDate::today((int) $c->id)->addDays(5)->toDateString())));
        $req = app(SavingsProducts::class)->requestTermsChange($c, (int) $p->id, ['interest_rate_bp' => 500], $s['owner']);
        $this->assertSame(600, $req->payload['old']['interest_rate_bp']);
        app(SaccoApprovals::class)->approve($c, (int) $req->id, $s['treasurer']);
        $this->assertSame(600, $p->fresh()->interest_rate_bp, 'still the old rate during the notice');
        $this->assertSame(0, app(SavingsProducts::class)->applyDue((int) $c->id));
        $this->travelTo(now()->addDays(31));
        $this->assertSame(1, app(SavingsProducts::class)->applyDue((int) $c->id));
        $this->assertSame([500, 2], [$p->fresh()->interest_rate_bp, $p->fresh()->terms_version]);
        $this->travelBack();

        $raise = app(SavingsProducts::class)->requestTermsChange($c, (int) $p->id, ['min_balance_minor' => 5_000], $s['owner']);
        app(SaccoApprovals::class)->approve($c, (int) $raise->id, $s['treasurer']);
        $this->assertSame(5_000, $p->fresh()->min_balance_minor, 'a change that is not a cut takes effect at once');
        $this->assertSame(3, SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $p->id)->count());
    }

    public function test_history_can_be_posted_on_an_open_past_day_but_never_into_a_closed_month_or_the_future(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $a = $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['teller']);
        $past = LocalDate::today((int) $c->id)->subDays(3)->toDateString();
        $tx = $this->deposit($c, $a, 10_000, $s['teller'], [], ['entry_date' => $past]);
        $entry = SaccoJournalEntry::withoutGlobalScopes()->find($tx->journal_entry_id);
        $this->assertSame([$past, $past], [$entry->entry_date->toDateString(), $entry->value_date->toDateString()]);
        $this->assertSame('invalid_date', $this->code(fn () => $this->deposit($c, $a, 1, $s['teller'], [], ['entry_date' => LocalDate::today((int) $c->id)->addDay()->toDateString()])));
        $this->assertSame('invalid_value_date', $this->code(fn () => $this->deposit($c, $a, 1, $s['teller'], ['value_date' => LocalDate::today((int) $c->id)->addDay()->toDateString()])));
    }

    public function test_another_saccos_accounts_are_out_of_reach(): void
    {
        $one = $this->saccoCompany();
        $two = $this->saccoCompany();
        $a = $this->savingsAccount($one['company'], $this->activeMember($one['company'], $one['owner']), $one['teller']);
        $this->deposit($one['company'], $a, 5_000, $one['teller']);
        $this->assertSame('account_not_found', $this->code(fn () => $this->deposit($two['company'], $a, 5_000, $two['teller'])));
        $this->assertSame('member_not_found', $this->code(fn () => app(SavingsAccounts::class)->open($two['company'], (int) $a->member_id, (int) $this->product($two['company'])->id, $two['teller'])));
        $this->actingAs($two['owner']);
        $this->assertSame(0, SaccoSavingsAccount::query()->whereKey($a->id)->count(), 'the company scope narrows the model');
        $this->assertSame(0, SaccoTransaction::query()->where('savings_account_id', $a->id)->count());
    }
}
