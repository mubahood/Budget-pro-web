<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoMember;
use App\Models\SaccoTransaction;
use App\Models\SyncConflict;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.16 (04 §9, ADR 0001, AK-24): members and cash receipts from a phone. The same batch three times
 * posts once; a receipt for an unknown account is held in 2150 with a conflict item, never refused; a withdrawal from a
 * phone is `offline_not_allowed`; members are numbered by the server and never carry a NIN either way; products and
 * accounts come down as snapshots; an app that never asks for the sacco tables syncs as before; and a short soak of
 * devices with lost answers and out-of-order replays keeps every invariant.
 */
class SaccoSyncReplayTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    /** @return array<string, mixed> */
    private function phone(array $s, string $who = 'teller'): array
    {
        $device = (string) Str::uuid();
        $h = ['Authorization' => 'Bearer '.$s[$who]->createToken('p')->plainTextToken, 'Accept' => 'application/json'];
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Field phone', 'platform' => 'android'], $h)->assertOk();

        return $h + ['X-Device-Id' => $device];
    }

    /** @param list<array<string, mixed>> $ops */
    private function batch(array $ops): array
    {
        return ['batch_uuid' => (string) Str::uuid(), 'ops' => array_map(fn ($o) => ['op_uuid' => (string) Str::uuid(), 'action' => 'insert', 'uuid' => (string) Str::uuid()] + $o, $ops)];
    }

    private function receipt(int $memberId, ?int $accountId, int $minor, string $no, string $kind = 'deposit'): array
    {
        return ['table' => 'sacco_transactions', 'data' => ['kind' => $kind, 'channel' => 'cash', 'member_id' => $memberId, 'savings_account_id' => $accountId, 'amount_minor' => $minor,
            'value_date' => now()->toDateString(), 'receipt_no' => $no]];
    }

    public function test_the_same_batch_three_times_posts_once_and_an_unknown_account_is_held_never_refused(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $h = $this->phone($s);
        $m = $this->activeMember($c, $s['owner']);
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $batch = $this->batch([$this->receipt((int) $m->id, (int) $a->id, 25_000, 'FP-0001'), $this->receipt((int) $m->id, 99999999, 7_000, 'FP-0002')]);
        foreach ([1, 2, 3] as $i) {
            $r = $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $h)->assertOk()->json('data.results.0');
            $this->assertContains($r['status'], ['applied', 'conflict'], json_encode($r));
        }
        $this->assertSame(1, SaccoTransaction::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'posted')->count());
        $this->assertSame(25_000, $this->balanceOf((int) $a->id));
        $held = SaccoTransaction::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'held')->firstOrFail();
        $this->assertSame(['unknown_account', 'FP-0002'], [$held->hold_reason, $held->device_receipt_no]);
        $this->assertSame(7_000, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'suspense.receipts'))->value('balance_minor'));
        $this->assertSame(1, SyncConflict::withoutGlobalScopes()->where('company_id', $c->id)->where('code', 'held_receipt')->count());
        $this->assertSame(2, SaccoTransaction::withoutGlobalScopes()->where('company_id', $c->id)->count());
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $c->id)));
    }

    public function test_money_out_from_a_phone_is_refused_and_a_decimal_amount_never_posts(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $h = $this->phone($s);
        $m = $this->activeMember($c, $s['owner']);
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $this->deposit($c, $a, 100_000, $s['teller']);
        foreach (['withdrawal', 'internal_transfer', 'reversal'] as $kind) {
            $r = $this->postJson('/api/v1/sync/push', ['batches' => [$this->batch([$this->receipt((int) $m->id, (int) $a->id, 5_000, 'FP-9', $kind)])]], $h)->assertOk()->json('data.results.0');
            $this->assertSame('offline_not_allowed', $r['ops'][0]['code'] ?? null, $kind.': '.json_encode($r));
        }
        $bank = $this->batch([['table' => 'sacco_transactions', 'data' => ['kind' => 'deposit', 'channel' => 'momo', 'member_id' => $m->id, 'savings_account_id' => $a->id, 'amount_minor' => 1]]]);
        $this->assertSame('offline_not_allowed', $this->postJson('/api/v1/sync/push', ['batches' => [$bank]], $h)->json('data.results.0.ops.0.code'));
        $dec = $this->batch([['table' => 'sacco_transactions', 'data' => ['kind' => 'deposit', 'member_id' => $m->id, 'savings_account_id' => $a->id, 'amount_minor' => 10.5]]]);
        $this->assertSame('validation', $this->postJson('/api/v1/sync/push', ['batches' => [$dec]], $h)->json('data.results.0.ops.0.code'));
        $this->assertSame(100_000, $this->balanceOf((int) $a->id));
    }

    public function test_members_from_a_phone_are_numbered_by_the_server_and_no_nin_travels(): void
    {
        $s = $this->saccoCompany('group');
        $c = $s['company'];
        $teller = $this->phone($s);
        $h = $this->phone($s, 'treasurer'); // registering members needs sacco_members
        $uuid = (string) Str::uuid();
        $op = ['table' => 'sacco_members', 'action' => 'upsert', 'uuid' => $uuid, 'data' => ['first_name' => 'Field', 'last_name' => 'Member', 'phone_e164' => '+256772000111',
            'consent_data_at' => now()->toDateTimeString(), 'nin_hash' => str_repeat('a', 64), 'member_no' => 'M-999999', 'status' => 'exited']];
        $this->assertSame('forbidden', $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [$op + ['op_uuid' => (string) Str::uuid()]]]]], $teller)
            ->json('data.results.0.ops.0.code'), 'a teller does not register members');
        $res = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [$op + ['op_uuid' => (string) Str::uuid()]]]]], $h)->assertOk();
        $this->assertSame('applied', $res->json('data.results.0.status'), json_encode($res->json('data.results.0.ops')));
        $m = SaccoMember::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $this->assertMatchesRegularExpression('/^M-\d{6}$/', $m->member_no);
        $this->assertNotSame('M-999999', $m->member_no);
        $this->assertSame(['active', 'pending', null], [$m->status, $m->kyc_status, $m->nin_hash], 'a group member is active at once; the phone sets no number, status or NIN');
        $noConsent = ['table' => 'sacco_members', 'action' => 'upsert', 'uuid' => (string) Str::uuid(), 'data' => ['first_name' => 'No', 'last_name' => 'Consent'], 'op_uuid' => (string) Str::uuid()];
        $this->assertSame('consent_required', $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [$noConsent]]]], $h)->json('data.results.0.ops.0.code'));

        // A receipt for the member the phone registered, named by its uuid.
        $acc = $this->savingsAccount($c, $m, $s['teller'], 'GRP');
        $r = $this->batch([['table' => 'sacco_transactions', 'data' => ['kind' => 'deposit', 'member_uuid' => $uuid, 'savings_account_id' => $acc->id, 'amount_minor' => 3_000]]]);
        $this->postJson('/api/v1/sync/push', ['batches' => [$r]], $h)->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $this->assertSame(3_000, $this->balanceOf((int) $acc->id));

        app(\App\Services\Sacco\Members\MemberService::class)->update($c, (int) $m->id, ['nin' => 'CM9001019876543Z'], $s['owner']);
        $rows = $this->getJson('/api/v1/sync/pull?table=sacco_members&since_seq=0', $h)->assertOk()->json('data.rows');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('nin_encrypted', $row);
            $this->assertArrayNotHasKey('nin_hash', $row);
        }
        $snap = $this->getJson('/api/v1/sync/pull?table=sacco_savings_accounts&since_seq=0', $h)->assertOk()->json('data');
        $this->assertTrue($snap['snapshot']);
        $this->assertSame($uuid, collect($snap['rows'])->firstWhere('id', $acc->id)['member_uuid']);
        $this->assertSame(3_000, (int) collect($snap['rows'])->firstWhere('id', $acc->id)['balance_minor']);
        $this->assertTrue($this->getJson('/api/v1/sync/pull?table=sacco_savings_accounts&since_seq='.$snap['next_seq'], $h)->json('data.unchanged'));
        $this->getJson('/api/v1/sync/pull?table=sacco_savings_products&since_seq=0', $h)->assertOk()->assertJsonPath('data.rows.0.code', 'GRP');
        $this->getJson('/api/v1/sync/pull?table=sacco_transactions&since_seq=0', $h)->assertOk()->assertJsonCount(1, 'data.rows');
    }

    public function test_an_app_without_the_sacco_tables_syncs_as_before(): void
    {
        $shop = $this->registerTenant();
        $device = (string) Str::uuid();
        $h = ['Authorization' => 'Bearer '.$shop['token'], 'Accept' => 'application/json'];
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Till', 'platform' => 'android'], $h)->assertOk();
        $h['X-Device-Id'] = $device;
        $this->postJson('/api/v1/sync/push', ['batches' => [$this->batch([['table' => 'categories', 'action' => 'upsert', 'data' => ['name' => 'Drinks']]])]], $h)->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied');
        $this->getJson('/api/v1/sync/pull?table=categories&since_seq=0', $h)->assertOk()->assertJsonCount(1, 'data.rows');
        $this->assertSame([], $this->getJson('/api/v1/sync/pull?table=sacco_transactions&since_seq=0', $h)->json('data.rows'), 'a shop has none');
    }

    public function test_a_short_soak_of_phones_with_lost_answers_and_replays_keeps_every_invariant(): void
    {
        mt_srand(1021);
        $s = $this->saccoCompany();
        $c = $s['company'];
        $phones = [$this->phone($s), $this->phone($s), $this->phone($s)];
        $accounts = [];
        foreach (range(1, 4) as $i) {
            $m = $this->activeMember($c, $s['owner'], 'Soak'.$i);
            $accounts[] = $this->savingsAccount($c, $m, $s['teller']);
        }
        $expected = array_fill_keys(array_map(fn ($a) => (int) $a->id, $accounts), 0);
        $heldTotal = 0;
        $sent = [];
        $lost = [];
        for ($i = 0; $i < 40; $i++) {
            $a = $accounts[array_rand($accounts)];
            $minor = mt_rand(1, 50) * 1000;
            $unknown = mt_rand(1, 8) === 1;
            $batch = $this->batch([$this->receipt((int) $a->member_id, $unknown ? 88888888 : (int) $a->id, $minor, 'S-'.$i)]);
            $unknown ? $heldTotal += $minor : $expected[(int) $a->id] += $minor;
            $h = $phones[array_rand($phones)];
            if (mt_rand(1, 4) === 1) {
                $lost[] = [$h, $batch]; // the answer never reached the phone: it sends again later, out of order
            }
            $this->postJson('/api/v1/sync/push', ['batches' => [$batch]], $h)->assertOk();
            $sent[] = [$h, $batch];
            if ($lost !== [] && mt_rand(1, 3) === 1) {
                [$lh, $lb] = array_splice($lost, mt_rand(0, count($lost) - 1), 1)[0];
                $this->postJson('/api/v1/sync/push', ['batches' => [$lb]], $lh)->assertOk();
            }
        }
        foreach (array_merge($lost, array_slice($sent, 0, 10)) as [$lh, $lb]) {
            $this->postJson('/api/v1/sync/push', ['batches' => [$lb]], $lh)->assertOk();
        }
        foreach ($expected as $id => $minor) {
            $this->assertSame($minor, $this->balanceOf($id), "account {$id}");
        }
        $this->assertSame(40, SaccoTransaction::withoutGlobalScopes()->where('company_id', $c->id)->count(), 'every receipt exactly once');
        $this->assertSame($heldTotal, (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id((int) $c->id, 'suspense.receipts'))->value('balance_minor'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $c->id)));
    }
}
