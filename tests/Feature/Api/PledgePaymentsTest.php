<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ContributionPayment;
use App\Models\ContributionRecord;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pledge payment history (roadmap 02 §6): append-only payments, reversals as new rows, paid_amount = the sum,
 * older apps' paid figures as payments of the difference, exact decimals, phone numbers, sync, isolation and
 * double submission. Concurrency on the sum: tests/Feature/Budget/ConcurrentPledgePaymentsTest.
 */
class PledgePaymentsTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function program(string $name = 'Wedding'): int
    {
        return (int) $this->postJson('/api/v1/budget-programs', ['name' => $name.' '.uniqid()], $this->h)->assertStatus(201)->json('data.id');
    }

    private function pledge(int $program, array $extra = []): array
    {
        return $this->postJson('/api/v1/contribution-records', $extra + ['budget_program_id' => $program, 'name' => 'Pledger '.uniqid(), 'amount' => 100000], $this->h)
            ->assertStatus(201)->json('data');
    }

    private function pay(int $pledgeId, array $body, ?array $h = null)
    {
        return $this->postJson("/api/v1/contribution-records/{$pledgeId}/payments", $body, $h ?? $this->h);
    }

    public function test_a_payment_is_recorded_listed_and_paid_amount_is_the_sum(): void
    {
        $program = $this->program();
        $p = $this->pledge($program);

        $r = $this->pay($p['id'], ['amount' => 30000, 'method' => 'mobile_money', 'reference' => 'MP123', 'paid_on' => now()->toDateString()])->assertStatus(201);
        $this->assertSame('30000.00', $r->json('data.payment.amount'));
        $this->assertStringStartsWith('PLG-'.now()->format('Y').'-', (string) $r->json('data.payment.receipt_no'));
        $this->assertSame('30000.00', $r->json('data.pledge.paid_amount'));
        $this->assertSame('70000.00', $r->json('data.pledge.not_paid_amount'));
        $this->pay($p['id'], ['amount' => '70000'])->assertStatus(201)->assertJsonPath('data.pledge.fully_paid', 'Yes');

        $list = $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $this->h)->assertOk();
        $this->assertCount(2, $list->json('data.payments'));
        $this->assertSame('70000.00', $list->json('data.payments.0.amount'), 'newest first');
        $this->assertSame('100000.00', $list->json('data.pledge.paid_amount'));
        $this->assertContains('record_payments', $list->json('data.abilities'));
        $this->assertSame('100000.00', (string) DB::table('budget_programs')->where('id', $program)->value('total_collected'), 'event totals follow');
    }

    public function test_more_than_is_owed_is_refused_on_the_api(): void
    {
        $p = $this->pledge($this->program());
        $this->pay($p['id'], ['amount' => 90000])->assertStatus(201);
        $this->pay($p['id'], ['amount' => 20000])->assertStatus(422)->assertJsonPath('errors.code', 'more_than_owed')->assertJsonPath('errors.owed', '10000.00');
        $this->pay($p['id'], ['amount' => 0])->assertStatus(422);
        $this->pay($p['id'], ['amount' => 100, 'method' => 'bitcoin'])->assertStatus(422)->assertJsonPath('errors.code', 'invalid_method');
        $this->pay($p['id'], ['amount' => 100, 'paid_on' => now()->addDays(5)->toDateString()])->assertStatus(422)->assertJsonPath('errors.code', 'invalid_date');
    }

    public function test_double_submit_with_the_same_uuid_or_idempotency_key_records_once(): void
    {
        $p = $this->pledge($this->program());
        $uuid = (string) Str::uuid();
        $this->pay($p['id'], ['amount' => 5000, 'uuid' => $uuid])->assertStatus(201);
        $this->pay($p['id'], ['amount' => 5000, 'uuid' => $uuid])->assertOk()->assertJsonPath('data.replayed', true);
        $other = $this->pledge($this->program());
        $this->pay($other['id'], ['amount' => 5000, 'uuid' => $uuid])->assertStatus(422)->assertJsonPath('errors.code', 'uuid_reused');

        $key = ['Idempotency-Key' => 'pay-'.uniqid()];
        $this->pay($p['id'], ['amount' => 2500], $this->h + $key)->assertStatus(201);
        $this->pay($p['id'], ['amount' => 2500], $this->h + $key)->assertStatus(201)->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(2, ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $p['id'])->count());
        $this->assertSame('7500.00', (string) ContributionRecord::withoutGlobalScopes()->find($p['id'])->paid_amount);
    }

    public function test_a_reversal_is_a_new_negative_row_and_the_original_stays(): void
    {
        $p = $this->pledge($this->program());
        $payId = $this->pay($p['id'], ['amount' => 40000])->json('data.payment.id');

        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", [], $this->h)->assertStatus(422);
        $r = $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Typed the wrong pledge'], $this->h)->assertStatus(201);
        $this->assertSame('-40000.00', $r->json('data.payment.amount'));
        $this->assertSame($payId, $r->json('data.payment.reverses_id'));
        $this->assertSame('0.00', $r->json('data.pledge.paid_amount'));
        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Again'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'already_reversed');
        $this->postJson('/api/v1/contribution-payments/'.$r->json('data.payment.id').'/reverse', ['reason' => 'Undo'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'cannot_reverse_reversal');

        $original = ContributionPayment::withoutGlobalScopes()->find($payId);
        $this->assertSame('40000.00', (string) $original->amount, 'never edited');
        $list = $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $this->h)->json('data.payments');
        $this->assertTrue(collect($list)->firstWhere('id', $payId)['is_reversed']);

        $this->expectException(BusinessRuleException::class);
        $original->amount = 1;
        $original->save();
    }

    public function test_older_apps_paid_amount_becomes_a_payment_of_the_difference(): void
    {
        $program = $this->program();
        $p = $this->pledge($program, ['paid_amount' => 60000]);
        $this->assertSame('60000.00', $p['paid_amount']);
        $rows = fn () => ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $p['id'])->orderBy('id')->get();
        $this->assertSame(['60000.00'], $rows()->pluck('amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('pledge_edit', $rows()->first()->source);

        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['paid_amount' => 80000], $this->h)->assertOk()->assertJsonPath('data.paid_amount', '80000.00');
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['paid_amount' => 80000, 'name' => 'Renamed '.uniqid()], $this->h)->assertOk();
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['paid_amount' => 50000], $this->h)->assertOk()->assertJsonPath('data.not_paid_amount', '50000.00');
        $this->assertSame(['60000.00', '20000.00', '-30000.00'], $rows()->pluck('amount')->map(fn ($v) => (string) $v)->all(), 'same figure again posts nothing');
        $this->assertNotNull($rows()->last()->reason);

        // Raising the pledge of a fully paid pledge never invents a payment.
        $q = $this->pledge($program, ['fully_paid' => 'Yes']);
        $this->assertSame('100000.00', $q['paid_amount']);
        $this->putJson("/api/v1/contribution-records/{$q['id']}", ['amount' => 150000], $this->h)->assertOk()->assertJsonPath('data.paid_amount', '100000.00');
    }

    public function test_money_is_exact_decimals_end_to_end(): void
    {
        $program = $this->program();
        $p = $this->pledge($program, ['amount' => '1000.50']);
        $this->pay($p['id'], ['amount' => '0.10'])->assertStatus(201);
        $this->pay($p['id'], ['amount' => '0.20'])->assertStatus(201);
        $r = $this->pay($p['id'], ['amount' => '250.25'])->assertStatus(201);
        $this->assertSame('250.55', $r->json('data.pledge.paid_amount'), '0.1 + 0.2 is exactly 0.30');
        $this->assertSame('749.95', $r->json('data.pledge.not_paid_amount'));
        $this->assertSame('250.55', (string) DB::table('budget_programs')->where('id', $program)->value('total_collected'));

        $cat = $this->postJson('/api/v1/budget-item-categories', ['name' => 'Food', 'budget_program_id' => $program], $this->h)->json('data.id');
        $this->postJson('/api/v1/budget-items', ['name' => 'Rice', 'budget_item_category_id' => $cat, 'unit_price' => '3333.33', 'quantity' => '3'], $this->h)->assertStatus(201)
            ->assertJsonPath('data.target_amount', '9999.99');
        $this->assertSame('9999.99', (string) DB::table('budget_programs')->where('id', $program)->value('budget_total'));
    }

    public function test_phone_numbers_on_pledges_are_e164_and_optional(): void
    {
        $program = $this->program();
        $p = $this->pledge($program, ['phone' => '0772 123 456']);
        $this->assertSame('+256772123456', $p['phone']);
        $this->assertNull($this->pledge($program)['phone'] ?? null);
        $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $program, 'name' => 'Bad phone', 'amount' => 10, 'phone' => '12'], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_phone');
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['phone' => ''], $this->h)->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_another_company_cannot_see_pay_or_reverse(): void
    {
        $p = $this->pledge($this->program());
        $payId = $this->pay($p['id'], ['amount' => 1000])->json('data.payment.id');
        $other = $this->auth($this->registerTenant()['token']);

        $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $other)->assertNotFound();
        $this->pay($p['id'], ['amount' => 1000], $other)->assertNotFound();
        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Not mine'], $other)->assertNotFound();
        $this->assertSame('1000.00', (string) ContributionRecord::withoutGlobalScopes()->find($p['id'])->paid_amount);
    }

    public function test_the_routes_belong_to_the_budget_module(): void
    {
        $p = $this->pledge($this->program());
        $company = Company::withoutGlobalScopes()->findOrFail($this->t['company_id']);
        $company->enabled_modules = ['shop', 'finance'];
        $company->save();

        $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $this->h)->assertStatus(403)->assertJsonPath('errors.code', 'module_off');
        $this->pay($p['id'], ['amount' => 1000])->assertStatus(403)->assertJsonPath('errors.code', 'module_off');
    }

    // ── Sync ─────────────────────────────────────────────────────────

    private ?array $deviceH = null;

    private function push(array $ops): array
    {
        if ($this->deviceH === null) {
            $device = (string) Str::uuid();
            $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->h)->assertOk();
            $this->deviceH = $this->h + ['X-Device-Id' => $device];
        }

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $this->deviceH)
            ->assertOk()->json('data.results.0');
    }

    private function op(string $table, array $data, ?string $uuid = null, string $action = 'insert'): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => $action,
            'client_updated_at' => SyncSequence::nowMs(), 'client_created_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
    }

    public function test_payments_made_offline_sync_once_and_come_back_down(): void
    {
        $prog = (string) Str::uuid();
        $pledge = (string) Str::uuid();
        $pay = (string) Str::uuid();
        $r = $this->push([
            $this->op('budget_programs', ['name' => 'Harambee '.uniqid()], $prog),
            $this->op('contribution_records', ['name' => 'Aunt Grace', 'program_uuid' => $prog, 'amount' => '50000', 'phone' => '0701 234567'], $pledge),
            $this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '20000', 'method' => 'cash', 'paid_on' => now()->toDateString()], $pay),
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        // The same op again (a lost answer): replayed, never twice.
        $again = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '20000'], $pay)]);
        $this->assertSame('replayed', $again['ops'][0]['status']);

        // Money received offline past the pledge is kept (never refused), and a reversal syncs too.
        $over = (string) Str::uuid();
        $this->assertSame('applied', $this->push([$this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '40000'], $over)])['status']);
        $rev = $this->push([$this->op('contribution_payments', ['reverses_uuid' => $over, 'reason' => 'Counted twice'])]);
        $this->assertSame('applied', $rev['status'], json_encode($rev));
        $noReason = $this->push([$this->op('contribution_payments', ['reverses_uuid' => $pay, 'reason' => ''])]);
        $this->assertSame('rejected', $noReason['status']);

        $rows = $this->getJson('/api/v1/sync/pull?table=contribution_payments&since_seq=0', $this->deviceH)->assertOk()->json('data.rows');
        $this->assertCount(3, $rows);
        $this->assertSame($pledge, $rows[0]['contribution_uuid']);
        $this->assertSame($prog, $rows[0]['program_uuid']);
        $this->assertSame($over, $rows[2]['reverses_uuid']);
        $pledges = $this->getJson('/api/v1/sync/pull?table=contribution_records&since_seq=0', $this->deviceH)->json('data.rows');
        $this->assertSame('20000.00', $pledges[0]['paid_amount']);
        $this->assertSame('+256701234567', $pledges[0]['phone']);

        $edit = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $pledge, 'amount' => '1'], $pay, 'update')]);
        $this->assertSame('immutable_event', $edit['ops'][0]['code']);
    }

    public function test_an_older_phone_sending_paid_amount_posts_the_difference(): void
    {
        $prog = (string) Str::uuid();
        $pledge = (string) Str::uuid();
        $this->push([
            $this->op('budget_programs', ['name' => 'Graduation '.uniqid()], $prog),
            $this->op('contribution_records', ['name' => 'Uncle Joe', 'program_uuid' => $prog, 'amount' => '300000', 'paid_amount' => '100000'], $pledge),
        ]);
        $v = (int) ContributionRecord::withoutGlobalScopes()->where('uuid', $pledge)->value('version');
        $r = $this->push([['op_uuid' => (string) Str::uuid(), 'table' => 'contribution_records', 'uuid' => $pledge, 'action' => 'update', 'client_updated_at' => SyncSequence::nowMs(),
            'version' => $v, 'changed_fields' => ['paid_amount'], 'data' => ['paid_amount' => '150000']]]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $id = ContributionRecord::withoutGlobalScopes()->where('uuid', $pledge)->value('id');
        $this->assertSame(['100000.00', '50000.00'], ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $id)->orderBy('id')->pluck('amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('150000.00', (string) ContributionRecord::withoutGlobalScopes()->find($id)->paid_amount);
    }

    public function test_the_migration_backfill_keeps_every_total(): void
    {
        $program = $this->program();
        $p = $this->pledge($program);
        // A pledge from before payment history: a paid figure and no payment rows.
        DB::table('contribution_records')->where('id', $p['id'])->update(['paid_amount' => 45000, 'not_paid_amount' => 55000]);
        $before = DB::table('contribution_records')->where('company_id', $this->t['company_id'])->sum('paid_amount');

        $migration = require database_path('migrations/2026_10_17_510002_create_contribution_payments_table.php');
        $migration->up();
        $migration->up(); // twice is harmless

        $rows = ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $p['id'])->get();
        $this->assertCount(1, $rows);
        $this->assertSame('opening', $rows[0]->source);
        $this->assertSame('45000.00', (string) $rows[0]->amount);
        $this->assertSame((string) $before, (string) DB::table('contribution_records')->where('company_id', $this->t['company_id'])->sum('paid_amount'));
        // And the history now agrees with the figure.
        $this->pay($p['id'], ['amount' => 5000])->assertStatus(201)->assertJsonPath('data.pledge.paid_amount', '50000.00');
    }
}
