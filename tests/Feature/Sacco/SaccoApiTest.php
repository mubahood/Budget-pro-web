<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoPeriod;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Members\MemberLogin;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.15 (04 §13): the API v1 for P1. Per controller the happy path, a refusal code and the 403 for a
 * role without the permission; money only as integer *_minor (a decimal → 422); every {id} route answers 404 to
 * another SACCO; the module gate; the member's own sign-in and token, which never opens staff routes or another
 * member's records.
 */
class SaccoApiTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_the_teller_desk_end_to_end_through_the_api(): void
    {
        $s = $this->saccoCompany();
        [$teller, $treasurer, $auditor] = [$this->h($s['teller']), $this->h($s['treasurer']), $this->h($this->saccoStaff($s['company'], 'sacco_auditor'))];
        $owner = ['Authorization' => 'Bearer '.$s['token'], 'Accept' => 'application/json'];

        $this->getJson('/api/v1/sacco/overview', $teller)->assertOk()->assertJsonPath('data.currency', 'UGX');
        $this->getJson('/api/v1/sacco/settings', $teller)->assertOk()->assertJsonPath('data.mode', 'office');
        $this->putJson('/api/v1/sacco/settings', ['short_name' => 'TTS'], $teller)->assertStatus(403)->assertJsonPath('errors.permission', 'sacco_settings');
        $this->putJson('/api/v1/sacco/settings', ['short_name' => 'TTS', 'entrance_fee_minor' => 2.5], $owner)->assertStatus(422);

        $m = $this->postJson('/api/v1/sacco/members', ['first_name' => 'Kato', 'last_name' => 'Paul', 'consent_data' => true, 'phone' => '0772123456'], $treasurer)->assertCreated()->json('data.member');
        $this->postJson('/api/v1/sacco/members', ['first_name' => 'X'], $teller)->assertStatus(403);
        $this->getJson('/api/v1/sacco/members?q=Kato', $teller)->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/sacco/members/{$m['id']}/kyc", ['decision' => 'verified'], $treasurer)->assertStatus(403)->assertJsonPath('errors.permission', 'sacco_kyc');
        $this->assertSame('applicant', $m['status'], 'office mode: an applicant until the fee, the shares and the ID check');
        $m = $this->activeMember($s['company'], $s['owner'], 'Okot')->toArray(); // money out needs an active member

        $products = $this->getJson('/api/v1/sacco/savings-products', $teller)->assertOk()->json('data');
        $ord = collect($products)->firstWhere('code', 'ORD');
        $acc = $this->postJson('/api/v1/sacco/savings-accounts', ['member_id' => $m['id'], 'product_id' => $ord['id']], $teller)->assertCreated()->json('data');
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/sacco/transactions', ['uuid' => $uuid, 'kind' => 'deposit', 'savings_account_id' => $acc['id'], 'amount_minor' => 1500.5], $teller)
            ->assertStatus(422);
        $dep = $this->postJson('/api/v1/sacco/transactions', ['uuid' => $uuid, 'kind' => 'deposit', 'savings_account_id' => $acc['id'], 'amount_minor' => 2_500_000], $teller)
            ->assertCreated()->assertJsonPath('data.status', 'posted')->json('data');
        $this->postJson('/api/v1/sacco/transactions', ['uuid' => $uuid, 'kind' => 'deposit', 'savings_account_id' => $acc['id'], 'amount_minor' => 2_500_000], $teller)
            ->assertOk()->assertJsonPath('message', 'Already recorded.');
        $this->postJson('/api/v1/sacco/transactions', ['kind' => 'deposit', 'savings_account_id' => $acc['id'], 'amount_minor' => 10], $auditor)->assertStatus(403);

        $w = $this->postJson('/api/v1/sacco/withdrawals', ['uuid' => (string) Str::uuid(), 'savings_account_id' => $acc['id'], 'amount_minor' => 1_200_000, 'channel' => 'cash'], $teller)
            ->assertStatus(202)->assertJsonPath('data.status', 'pending_approval')->json('data');
        $this->postJson('/api/v1/sacco/withdrawals', ['savings_account_id' => $acc['id'], 'amount_minor' => 9_000_000], $teller)
            ->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_available');
        $list = $this->getJson('/api/v1/sacco/approvals?status=pending', $treasurer)->assertOk()->json('data');
        $this->assertSame('withdrawal', $list[0]['action']);
        $this->assertStringContainsString('Withdraw UGX 1,200,000 from '.$acc['account_no'], $list[0]['summary']);
        $this->assertFalse($list[0]['is_mine']);
        $this->assertTrue($this->getJson('/api/v1/sacco/approvals?status=pending', $teller)->json('data.0.is_mine'));
        $this->assertNotEmpty($list[0]['requested_by_name']);
        $this->postJson("/api/v1/sacco/approvals/{$w['approval_id']}/approve", [], $teller)->assertStatus(403);
        $this->postJson("/api/v1/sacco/approvals/{$w['approval_id']}/approve", [], $treasurer)->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame('posted', SaccoTransaction::find($w['id'])->status);

        $rev = $this->postJson("/api/v1/sacco/transactions/{$dep['id']}/reverse", ['reason' => 'Wrong account'], $teller)->assertStatus(202)->json('data');
        $this->postJson("/api/v1/sacco/approvals/{$rev['id']}/approve", [], $treasurer)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_balance');
        $this->postJson("/api/v1/sacco/approvals/{$rev['id']}/cancel", [], $treasurer)->assertStatus(403)->assertJsonPath('errors.code', 'forbidden');
        $this->postJson("/api/v1/sacco/approvals/{$rev['id']}/cancel", [], $teller)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $m['id'], 'shares_count' => 5], $teller)->assertCreated()->assertJsonPath('data.amount_minor', 50_000);
        $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $m['id'], 'amount_minor' => 15_000], $teller)->assertStatus(422)->assertJsonPath('errors.code', 'not_whole_shares');
        $this->getJson('/api/v1/sacco/share-accounts?member_id='.$m['id'], $teller)->assertOk()->assertJsonPath('data.0.shares_count', 5);

        $this->getJson('/api/v1/sacco/reports', $teller)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sacco/reports/trial_balance', $teller)->assertStatus(403);
        $this->getJson('/api/v1/sacco/reports/trial_balance', $auditor)->assertOk()->assertJsonPath('data.meta.balanced', true);
        $this->getJson('/api/v1/sacco/reports/trial_balance?format=pdf', $auditor)->assertOk()->assertJsonPath('data.expires_in', 1800);
        $this->getJson('/api/v1/sacco/reports/nope', $auditor)->assertStatus(404);
        $this->getJson("/api/v1/sacco/members/{$m['id']}/statement", $treasurer)->assertOk()->assertJsonPath('data.member.id', $m['id']);
        $this->getJson('/api/v1/sacco/ledger/verify', $auditor)->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson('/api/v1/sacco/ledger/verify', $teller)->assertStatus(403);
        $this->getJson('/api/v1/sacco/audit', $auditor)->assertOk();
    }

    public function test_the_books_routes_go_through_a_second_officer(): void
    {
        $s = $this->saccoCompany();
        $cid = (int) $s['company']->id;
        [$treasurer, $teller] = [$this->h($s['treasurer']), $this->h($s['teller'])];
        $owner = ['Authorization' => 'Bearer '.$s['token'], 'Accept' => 'application/json'];
        $lines = [['account_id' => ChartOfAccounts::id($cid, 'exp.bank'), 'debit_minor' => 5_000], ['account_id' => ChartOfAccounts::id($cid, 'bank.main'), 'credit_minor' => 5_000]];
        $j = $this->postJson('/api/v1/sacco/ledger/journal', ['memo' => 'Bank charges', 'lines' => $lines], $treasurer)->assertStatus(202)->json('data');
        $this->postJson('/api/v1/sacco/ledger/journal', ['memo' => 'Bank charges', 'lines' => $lines], $teller)->assertStatus(403);
        $this->postJson('/api/v1/sacco/ledger/journal', ['memo' => 'x', 'lines' => [['account_id' => $lines[0]['account_id'], 'debit_minor' => 1.5], $lines[1]]], $treasurer)->assertStatus(422);
        $this->postJson("/api/v1/sacco/approvals/{$j['id']}/approve", [], $owner)->assertOk()->assertJsonPath('data.status', 'executed');
        $entry = $this->getJson('/api/v1/sacco/ledger/journal?kind=manual', $treasurer)->assertOk()->json('data.0');
        $this->getJson("/api/v1/sacco/ledger/journal/{$entry['id']}", $treasurer)->assertOk()->assertJsonCount(2, 'data.lines');
        $this->getJson('/api/v1/sacco/ledger/accounts', $teller)->assertOk();
        $this->postJson('/api/v1/sacco/ledger/accounts', ['code' => '1105', 'name' => 'Centenary bank', 'type' => 'asset'], $treasurer)->assertStatus(202);
        $this->getJson('/api/v1/sacco/periods', $teller)->assertOk();
        $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->orderBy('starts_on')->first();
        $this->postJson("/api/v1/sacco/periods/{$period->id}/close", [], $treasurer)->assertStatus(202);
        $this->postJson('/api/v1/sacco/opening-balances/import', ['kind' => 'ledger', 'csv' => "account_code,debit,credit\n1010,1000,\n"], $treasurer)->assertOk()
            ->assertJsonPath('data.totals.debit_minor', 1000);
        $this->postJson('/api/v1/sacco/opening-balances/import', ['kind' => 'ledger', 'csv' => "account_code,debit,credit\n1010,1000,\n", 'dry_run' => false], $treasurer)->assertStatus(202)
            ->assertJsonPath('data.action', 'opening_balances');
        $this->getJson('/api/v1/sacco/officers', $teller)->assertOk()->assertJsonPath('data.0.position', 'chairperson');
        $prod = $this->getJson('/api/v1/sacco/savings-products', $teller)->json('data.0');
        $this->putJson("/api/v1/sacco/savings-products/{$prod['id']}", ['interest_rate_bp' => 300], $owner)->assertStatus(202)->assertJsonPath('data.approval.action', 'product_terms_change');
        $this->putJson("/api/v1/sacco/savings-products/{$prod['id']}", ['name' => 'Renamed'], $teller)->assertStatus(403);
    }

    public function test_every_id_route_answers_404_to_another_sacco_and_a_shop_is_refused_the_module(): void
    {
        $a = $this->saccoCompany();
        $b = $this->saccoCompany();
        $m = $this->activeMember($a['company'], $a['owner']);
        $acc = $this->savingsAccount($a['company'], $m, $a['teller']);
        $tx = $this->deposit($a['company'], $acc, 10_000, $a['teller']);
        $shares = app(\App\Services\Sacco\Shares\ShareService::class)->purchase($a['company'], ['member_id' => $m->id, 'shares_count' => 1], $a['teller'])['transaction'];
        $w = $this->withdraw($a['company'], $acc, 9_000, $a['teller']);
        $approval = app(\App\Services\Sacco\SaccoTransactions::class)->reverse($a['company'], (int) $tx->id, 'Test', $a['teller']);
        $entry = (int) $tx->journal_entry_id;
        $product = $this->product($a['company']);
        $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $a['company']->id)->first();
        $officer = \App\Models\SaccoOfficer::withoutGlobalScopes()->where('company_id', $a['company']->id)->first();
        $rel = app(\App\Services\Sacco\Members\MemberService::class)->addNextOfKin($a['company'], (int) $m->id, 'Kin', null, null, $a['owner']);
        $this->assertNotNull($shares->share_account_id);
        $bOwner = ['Authorization' => 'Bearer '.$b['token'], 'Accept' => 'application/json'];
        $routes = [
            ['get', "members/{$m->id}"], ['put', "members/{$m->id}"], ['post', "members/{$m->id}/kyc", ['decision' => 'verified']], ['post', "members/{$m->id}/status", ['status' => 'dormant']],
            ['get', "members/{$m->id}/relations"], ['post', "members/{$m->id}/relations", ['name' => 'X']], ['delete', "members/{$m->id}/relations/{$rel->id}"],
            ['get', "members/{$m->id}/statement"], ['get', "savings-accounts/{$acc->id}"], ['post', "savings-accounts/{$acc->id}/freeze", ['reason' => 'x']],
            ['post', "savings-accounts/{$acc->id}/unfreeze"], ['post', "savings-accounts/{$acc->id}/close", ['reason' => 'x']], ['get', "savings-products/{$product->id}"],
            ['put', "savings-products/{$product->id}", ['name' => 'x']], ['get', "transactions/{$tx->id}"], ['post', "transactions/{$tx->id}/reverse", ['reason' => 'xyz']],
            ['get', "approvals/{$approval->id}"], ['post', "approvals/{$approval->id}/approve"], ['post', "approvals/{$approval->id}/reject", ['note' => 'x']],
            ['post', "approvals/{$approval->id}/cancel"], ['post', "approvals/{$approval->id}/execute"], ['get', "ledger/journal/{$entry}"],
            ['post', "ledger/journal/{$entry}/reverse", ['reason' => 'x']], ['put', 'ledger/accounts/'.ChartOfAccounts::id((int) $a['company']->id, 'cash.office'), ['name' => 'x']],
            ['post', "periods/{$period->id}/close"], ['post', "periods/{$period->id}/reopen", ['reason' => 'x']], ['get', "officers/{$officer->id}"], ['post', "officers/{$officer->id}/end"],
        ];
        foreach ($routes as $r) {
            $res = $this->json(strtoupper($r[0]), '/api/v1/sacco/'.$r[1], $r[2] ?? [], $bOwner);
            $this->assertSame(404, $res->status(), strtoupper($r[0]).' '.$r[1].' → '.$res->status().' '.$res->getContent());
        }
        $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $m->id, 'shares_count' => 1], $bOwner)->assertStatus(404);
        $this->postJson('/api/v1/sacco/withdrawals', ['savings_account_id' => $acc->id, 'amount_minor' => 1], $bOwner)->assertStatus(404);
        $this->assertSame(0, (int) $this->getJson('/api/v1/sacco/transactions', $bOwner)->json('meta.total'));
        $this->assertSame('posted', $w->fresh()->status, 'another SACCO touched nothing');

        $shop = $this->registerTenant();
        $this->getJson('/api/v1/sacco/overview', ['Authorization' => 'Bearer '.$shop['token']])->assertStatus(403)->assertJsonPath('errors.code', 'module_off');
    }

    public function test_a_member_signs_in_with_a_code_and_sees_only_their_own_records(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = app(\App\Services\Sacco\Members\MemberService::class)->register($c, ['first_name' => 'Self', 'last_name' => 'Service', 'email' => 'self@example.com', 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $other = $this->activeMember($c, $s['owner']);
        app(MemberLogin::class)->enable($c, (int) $m->id, $s['owner'], 'self@example.com');
        $mine = $this->savingsAccount($c, $m, $s['teller']);
        $this->deposit($c, $mine, 40_000, $s['teller']);
        $theirs = $this->deposit($c, $this->savingsAccount($c, $other, $s['teller']), 70_000, $s['teller']);

        $this->postJson('/api/v1/sacco/member-auth/otp', ['email' => 'self@example.com'])->assertOk();
        $code = $this->latestCode('sacco:self@example.com');
        $this->postJson('/api/v1/sacco/member-auth/verify', ['email' => 'self@example.com', 'code' => '000000'])->assertStatus(422);
        $v = $this->postJson('/api/v1/sacco/member-auth/verify', ['email' => 'self@example.com', 'code' => $code])->assertOk()->json('data');
        $this->postJson('/api/v1/sacco/member-auth/token', ['ticket' => $v['ticket'], 'company_id' => $c->id, 'member_id' => $other->id])->assertStatus(401);
        $v = $this->reverify($code);
        $t = $this->postJson('/api/v1/sacco/member-auth/token', ['ticket' => $v['ticket'], 'company_id' => $c->id, 'member_id' => $m->id])->assertCreated()->json('data.token');
        $hm = ['Authorization' => 'Bearer '.$t, 'Accept' => 'application/json'];

        $this->getJson('/api/v1/sacco/me', $hm)->assertOk()->assertJsonPath('data.member_no', $m->member_no)->assertJsonPath('data.sacco.legal_name', 'Test Teachers SACCO');
        $this->getJson('/api/v1/sacco/me/accounts', $hm)->assertOk()->assertJsonCount(1, 'data.savings')->assertJsonPath('data.savings.0.balance_minor', 40_000);
        $this->getJson('/api/v1/sacco/me/statement', $hm)->assertOk()->assertJsonPath('data.member.id', $m->id);
        $this->getJson("/api/v1/sacco/me/receipts/{$theirs->id}", $hm)->assertStatus(404);
        $this->getJson('/api/v1/sacco/overview', $hm)->assertStatus(401);
        $this->getJson('/api/v1/sacco/me', ['Authorization' => 'Bearer '.$s['token']])->assertStatus(401);
        $this->postJson('/api/v1/sacco/me/logout', [], $hm)->assertOk();
        $this->getJson('/api/v1/sacco/me', $hm)->assertStatus(401);
    }

    private function latestCode(string $identifier): string
    {
        $row = \Illuminate\Support\Facades\DB::table('message_log')->where('to', 'self@example.com')->orderByDesc('id')->first();
        preg_match('/code is (\d{4,8})/', (string) $row?->body, $mm);
        $this->assertNotEmpty($mm[1] ?? null, 'the code went out through message_log');

        return $mm[1];
    }

    /** A second code (a code opens once). @return array<string, mixed> */
    private function reverify(string $old): array
    {
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/sacco/member-auth/otp', ['email' => 'self@example.com'])->assertOk();

        return $this->postJson('/api/v1/sacco/member-auth/verify', ['email' => 'self@example.com', 'code' => $this->latestCode('x')])->assertOk()->json('data');
    }
}
