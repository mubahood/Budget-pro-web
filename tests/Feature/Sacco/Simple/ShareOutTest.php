<?php

namespace Tests\Feature\Sacco\Simple;

use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoShareout;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Shares\ShareService;
use App\Services\Sacco\Simple\Money;
use App\Services\Sacco\Simple\SaccoAccount;
use App\Services\Sacco\Simple\ShareOut;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/** Flow 3: the live preview (savings + share of profit, reconciled to the balance), and a run that posts once and locks. */
class ShareOutTest extends ApiTestCase
{
    use MakesSimpleSacco;

    public function test_preview_reconciles_and_a_run_pays_everyone_exactly_once(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $cid = (int) $company->id;
        $par = (int) SaccoSetting::for($cid)->share_par_minor;
        $members = [];
        foreach ([[100_000, 1], [200_000, 2], [300_000, 3]] as $i => [$savings, $shares]) {
            $members[$i] = $this->saver($company, $owner, $savings, 'M'.$i);
            (new ShareService())->purchase($company, ['member_id' => $members[$i]->id, 'shares_count' => $shares], $owner);
        }
        $acct = new SaccoAccount();
        $cats = collect($acct->categories($cid))->keyBy('name');
        $acct->record($company, $owner, ['direction' => 'in', 'category_id' => $cats['Other income']->id, 'amount_minor' => 60_000, 'method' => 'cash']);
        $acct->record($company, $owner, ['direction' => 'out', 'category_id' => $cats['Office expenses']->id, 'amount_minor' => 6_000, 'method' => 'cash']);
        $h = $this->staffHeaders($owner);

        $p = $this->getJson('/api/v1/sacco/share-out/preview', $h)->assertOk()->json('data');
        $this->assertSame(54_000, $p['profit_minor']);
        $this->assertSame(654_000, $p['pool_minor']);
        $this->assertSame([109_000, 218_000, 327_000], array_column($p['members'], 'payout_minor'));
        $this->assertSame(0, $p['reconciliation']['other_minor'], 'savings + shares + profit − loans = the SACCO balance');
        $this->assertSame(600_000 + 6 * $par + 54_000, $p['balance']['total']);
        $this->assertStringContainsString('their shares', $p['formula']);

        $uuid = (string) Str::uuid();
        $run = ['uuid' => $uuid, 'share_on' => now()->toDateString(), 'amount_minor' => 654_000, 'method' => 'cash'];
        $this->postJson('/api/v1/sacco/share-outs', ['amount_minor' => 654_001, 'method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_too_high');
        $r = $this->postJson('/api/v1/sacco/share-outs', $run, $h)->assertCreated();
        $this->assertSame(654_000, array_sum(array_column($r->json('data.lines'), 'payout_minor')));
        $this->postJson('/api/v1/sacco/share-outs', $run, $h)->assertOk()->assertJsonPath('data.id', $r->json('data.id')); // a double tap
        $this->postJson('/api/v1/sacco/share-outs', ['uuid' => (string) Str::uuid()] + $run, $h)->assertStatus(422)->assertJsonPath('errors.code', 'shareout_exists');
        $this->assertSame(1, SaccoShareout::where('company_id', $cid)->count());
        $this->assertSame(0, (int) SaccoSavingsAccount::where('company_id', $cid)->sum('balance_minor'));
        $this->assertSame(6 * $par, Money::balances($cid)['total']);
        $after = (new ShareOut())->preview($cid);
        $this->assertSame(0, $after['profit_minor'], 'profit is never shared twice');

        $s = SaccoShareout::find($r->json('data.id'));
        try {
            $s->update(['notes' => 'x']);
            $this->fail('locked');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('shareout_locked', $e->errorCode());
        }
        $slip = $this->get('/api/v1/sacco/share-outs/'.$s->id.'/slips/'.$members[2]->id, $h);
        $slip->assertOk();
        $this->assertSame('application/pdf', $slip->headers->get('Content-Type'));
        $this->getJson('/api/v1/sacco/me/share-out', $this->memberHeaders($company, $members[0], $owner))->assertOk()->assertJsonPath('data.payout_minor', 0);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_an_amount_below_the_savings_pays_them_back_in_proportion(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $a = $this->saver($company, $owner, 100_001);
        $b = $this->saver($company, $owner, 200_002);
        $s = (new ShareOut())->run($company, $owner, ['amount_minor' => 150_000, 'method' => 'cash']);
        $lines = $s->lines->keyBy('member_id');
        $this->assertSame(150_000, (int) $s->lines->sum('payout_minor'));
        $this->assertSame([50_000, 100_000], [(int) $lines[$a->id]->savings_paid_minor, (int) $lines[$b->id]->savings_paid_minor]);
        $this->assertSame(0, (int) $s->profit_minor);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('forbidden', $this->errorCode(fn () => (new ShareOut())->run($company, $teller, ['amount_minor' => 1, 'method' => 'cash', 'share_on' => now()->subDay()->toDateString()])));
    }
}
