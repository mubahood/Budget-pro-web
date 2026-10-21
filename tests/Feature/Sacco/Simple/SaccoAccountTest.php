<?php

namespace Tests\Feature\Sacco\Simple;

use App\Models\SaccoAccountEntry;
use App\Models\SaccoJournalEntry;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Simple\Money;
use App\Services\Sacco\Simple\SaccoAccount;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/** Flow 2: the SACCO's own money in and out posts to the ledger, moves the balance, is corrected only by a reversing entry. */
class SaccoAccountTest extends ApiTestCase
{
    use MakesSimpleSacco;

    public function test_money_in_and_out_post_move_the_balance_and_reverse_without_editing(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $cid = (int) $company->id;
        $h = $this->staffHeaders($owner);
        $acc = $this->getJson('/api/v1/sacco/account', $h)->assertOk();
        $cats = collect($acc->json('data.categories'))->keyBy('name');
        $this->assertSame(0, $acc->json('data.balance.total'));
        $this->assertTrue($cats->has('Bank charges') && $cats->has('Grant') && $cats->has('Office expenses'));

        $uuid = (string) Str::uuid();
        $in = ['uuid' => $uuid, 'direction' => 'in', 'category_id' => $cats['Grant']['id'], 'amount_minor' => 500_000, 'method' => 'bank', 'notes' => 'District grant'];
        $this->postJson('/api/v1/sacco/account/entries', $in, $h)->assertCreated()->assertJsonPath('data.status', 'posted');
        $this->postJson('/api/v1/sacco/account/entries', $in, $h)->assertOk(); // a double tap: one entry
        $this->assertSame(1, SaccoAccountEntry::where('company_id', $cid)->count());
        $out = $this->postJson('/api/v1/sacco/account/entries', ['direction' => 'out', 'category_id' => $cats['Bank charges']['id'], 'amount_minor' => 12_000, 'method' => 'bank'], $h)->assertCreated();
        $this->postJson('/api/v1/sacco/account/entries', ['direction' => 'out', 'category_id' => $cats['Office expenses']['id'], 'amount_minor' => 1, 'method' => 'cash'], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_funds');
        $this->postJson('/api/v1/sacco/account/entries', ['direction' => 'in', 'category_id' => $cats['Bank charges']['id'], 'amount_minor' => 5, 'method' => 'cash'], $h)->assertStatus(422);
        $this->postJson('/api/v1/sacco/account/entries', ['direction' => 'in', 'category_id' => $cats['Grant']['id'], 'amount_minor' => 10.5, 'method' => 'cash'], $h)->assertStatus(422);
        $this->assertSame(['cash' => 0, 'bank' => 488_000, 'mobile_money' => 0, 'total' => 488_000], Money::balances($cid));

        $entry = SaccoAccountEntry::find($out->json('data.id'));
        $this->assertSame('money_out', SaccoJournalEntry::find($entry->journal_entry_id)->kind);
        try {
            $entry->update(['amount_minor' => 1]);
            $this->fail('a posted entry never changes');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('entry_locked', $e->errorCode());
        }
        $this->postJson('/api/v1/sacco/account/entries/'.$entry->id.'/reverse', ['reason' => 'Charged twice by mistake'], $h)->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->postJson('/api/v1/sacco/account/entries/'.$entry->id.'/reverse', ['reason' => 'again'], $h)->assertStatus(422);
        $this->assertSame(500_000, Money::balances($cid)['total']);

        $st = $this->getJson('/api/v1/sacco/account/statement', $h)->assertOk();
        $this->assertSame(500_000, $st->json('data.closing_minor'));
        $this->assertSame([500_000, 488_000, 500_000], array_column($st->json('data.rows'), 'balance_minor'));

        $this->postJson('/api/v1/sacco/account/categories', ['direction' => 'out', 'name' => 'Transport'], $h)->assertCreated();
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->postJson('/api/v1/sacco/account/entries', $in + ['uuid' => (string) Str::uuid()], $this->staffHeaders($teller))->assertStatus(403);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_group_mode_has_the_categories_too(): void
    {
        [$company, $owner] = $this->simpleSacco('group');
        $svc = new SaccoAccount();
        $grant = collect($svc->categories((int) $company->id))->firstWhere('name', 'Grant');
        $svc->record($company, $owner, ['direction' => 'in', 'category_id' => $grant->id, 'amount_minor' => 1000, 'method' => 'cash']);
        $this->assertSame(1000, Money::balances((int) $company->id)['cash']);
    }
}
