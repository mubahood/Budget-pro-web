<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoMember;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/** /api/v1/kin/dues: lines built once, payments within the balance, reversals that keep history, the share text, who may write. */
class KinDuesTest extends ApiTestCase
{
    use MakesKin;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->co, , $token] = $this->kinAccount();
        $this->head = ['Authorization' => 'Bearer '.$token];
    }

    private function person(string $first, string $duty = 'compulsory'): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'male', 'dues_duty' => $duty])->id;
    }

    /** @return array<string, string> */
    private function as(string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->kinStaff($this->co, $role)->createToken('t')->plainTextToken];
    }

    public function test_monthly_dues_from_lines_to_reversal_and_share_text(): void
    {
        $amos = $this->person('Amos');
        $joel = $this->person('Joel');
        $this->person('Kid', 'exempt');
        $start = now()->subMonths(2)->startOfMonth()->toDateString();

        $p = $this->postJson('/api/v1/kin/dues', ['name' => 'Monthly subscription', 'kind' => 'recurring', 'period' => 'monthly', 'amount_mode' => 'fixed',
            'amount_minor' => 10_000, 'starts_on' => $start], $this->head)->assertCreated()->assertJsonPath('data.expected_minor', 60_000)->json('data');
        $id = $p['id'];
        // Three months for each of the two who must pay; running the job again makes nothing new.
        $this->assertSame(6, DB::table('sacco_dues_lines')->where('programme_id', $id)->count());
        $this->artisan('sacco:dues-prepare')->assertSuccessful();
        $this->artisan('sacco:dues-prepare', ['--company' => $this->co->id])->assertSuccessful();
        $this->assertSame(6, DB::table('sacco_dues_lines')->where('programme_id', $id)->count());

        // Part, over the balance (refused), the rest; then nothing more without advances.
        $pay = fn (int $who, int $amount, ?array $h = null) => $this->postJson("/api/v1/kin/dues/{$id}/payments", ['member_id' => $who, 'amount_minor' => $amount, 'method' => 'cash'], $h ?? $this->head);
        $first = $pay($amos, 15_000)->assertCreated()->assertJsonPath('data.amount_minor', 15_000)->json('data');
        $this->assertMatchesRegularExpression('/^DUE-\d{5}$/', $first['receipt_no']);
        $pay($amos, 100_000)->assertStatus(422)->assertJsonPath('errors.code', 'over_balance')->assertJsonPath('errors.balance_minor', 15_000);
        $pay($amos, 15_000)->assertCreated();
        $pay($amos, 1)->assertStatus(422)->assertJsonPath('errors.code', 'over_balance');
        $show = $this->getJson("/api/v1/kin/dues/{$id}", $this->head)->assertOk()->json('data');
        $people = collect($show['people'])->keyBy('member_id');
        $this->assertSame('paid', $people[$amos]['status']);
        $this->assertSame('owing', $people[$joel]['status']);
        $this->assertSame(30_000, $show['programme']['collected_minor']);
        $this->assertCount(3, $show['periods']);
        $this->assertSame(now()->format('Y-m'), $show['periods'][0]);

        // A payment ahead once advances are allowed; reversed, it stays in the history as two rows.
        $this->patchJson("/api/v1/kin/dues/{$id}", ['allow_advance' => true], $this->head)->assertOk()->assertJsonPath('data.allow_advance', true);
        $ahead = $pay($amos, 10_000)->assertCreated()->json('data.id');
        $this->assertSame('advance', collect($this->getJson("/api/v1/kin/dues/{$id}", $this->head)->json('data.people'))->firstWhere('member_id', $amos)['status']);
        $back = $this->postJson("/api/v1/kin/dues/payments/{$ahead}/reverse", ['reason' => 'Entered twice'], $this->head)->assertCreated()
            ->assertJsonPath('data.amount_minor', -10_000)->assertJsonPath('data.reverses_id', $ahead)->json('data.id');
        $this->postJson("/api/v1/kin/dues/payments/{$ahead}/reverse", ['reason' => 'Again'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'already_reversed');
        $this->postJson("/api/v1/kin/dues/payments/{$back}/reverse", ['reason' => 'Undo'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'is_reversal');
        $this->assertSame(4, DB::table('sacco_dues_payments')->where('programme_id', $id)->count());
        $this->assertNotNull(DB::table('sacco_dues_payments')->where('id', $ahead)->value('reversed_at'));
        $this->assertSame('paid', collect($this->getJson("/api/v1/kin/dues/{$id}", $this->head)->json('data.people'))->firstWhere('member_id', $amos)['status']);

        // Joel pays part: the share text lists each group, numbered, with what is owed.
        $pay($joel, 5_000)->assertCreated();
        $text = $this->getJson("/api/v1/kin/dues/{$id}/share", $this->head)->assertOk()->json('data.text');
        $this->assertStringStartsWith("*Monthly subscription*\n", $text);
        $this->assertStringContainsString("Paid (1):\n1. Amos Sample", $text);
        $this->assertStringContainsString("Part paid (1):\n1. Joel Sample: paid UGX 5,000, owes UGX 25,000", $text);
        $this->assertStringNotContainsString('Kid Sample', $text, 'the exempt are left out');
        $month = $this->getJson("/api/v1/kin/dues/{$id}/share?period=".now()->format('Y-m'), $this->head)->assertOk()->json('data.text');
        $this->assertStringContainsString(now()->format('F Y'), $month);

        $this->getJson('/api/v1/kin/dues', $this->head)->assertOk()->assertJsonPath('data.programmes.0.paid_count', 1)->assertJsonPath('data.programmes.0.part_count', 1);
        $rows = collect($this->getJson("/api/v1/kin/dues/{$id}", $this->head)->json('data.payments'))->keyBy('id');
        $this->assertSame('Amos Sample', $rows[$ahead]['member_name']);
        $this->assertTrue($rows[$ahead]['reversed']);
        $this->assertSame('Entered twice', $rows[$ahead]['reversal_reason']);
        $this->assertSame(now()->format('Y-m'), $rows[$ahead]['period'], 'unnamed: the month it came in');
    }

    public function test_a_payment_can_name_its_period(): void
    {
        $amos = $this->person('Amos');
        $start = now()->subMonths(2)->startOfMonth();
        $id = $this->postJson('/api/v1/kin/dues', ['name' => 'Monthly subscription', 'kind' => 'recurring', 'period' => 'monthly', 'amount_mode' => 'fixed',
            'amount_minor' => 10_000, 'starts_on' => $start->toDateString()], $this->head)->assertCreated()->json('data.id');
        $now = now()->format('Y-m');
        $first = $start->format('Y-m');
        $body = ['member_id' => $amos, 'method' => 'cash'];

        // Paid for this month: this month is paid, the first month still owes (no oldest-first for a named period).
        $pid = $this->postJson("/api/v1/kin/dues/{$id}/payments", $body + ['amount_minor' => 10_000, 'period' => $now], $this->head)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kin/dues/{$id}/payments", $body + ['amount_minor' => 10_000, 'period' => '1999-01'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'period_invalid');
        $person = fn (string $period) => collect($this->getJson("/api/v1/kin/dues/{$id}?period={$period}", $this->head)->assertOk()->json('data.people'))->firstWhere('member_id', $amos)['status'];
        $this->assertSame('paid', $person($now));
        $this->assertSame('owing', $person($first));
        $show = $this->getJson("/api/v1/kin/dues/{$id}?period={$now}", $this->head)->json('data');
        $this->assertSame($now, $show['period']);
        $this->assertSame($id, $show['programme']['id']);
        $this->assertSame([$pid], array_column($show['payments'], 'id'));
        $this->assertSame($now, $show['payments'][0]['period']);
        $this->assertSame([], $this->getJson("/api/v1/kin/dues/{$id}?period={$first}", $this->head)->json('data.payments'));

        // Reversed, the reversal keeps the period; the month owes again.
        $this->postJson("/api/v1/kin/dues/payments/{$pid}/reverse", ['reason' => 'Wrong person'], $this->head)->assertCreated()->assertJsonPath('data.period', $now);
        $this->assertSame('owing', $person($now));
    }

    public function test_who_may_write_my_dues_and_another_family(): void
    {
        $amos = $this->person('Amos');
        $id = $this->postJson('/api/v1/kin/dues', ['name' => 'Home renovation', 'kind' => 'once', 'amount_mode' => 'any', 'target_minor' => 1_000_000], $this->head)
            ->assertCreated()->assertJsonPath('data.period', null)->json('data.id');

        $member = $this->kinStaff($this->co, 'kin_member');
        $mh = ['Authorization' => 'Bearer '.$member->createToken('t')->plainTextToken];
        $body = ['member_id' => $amos, 'amount_minor' => 250_000, 'method' => 'mobile_money'];
        $this->getJson("/api/v1/kin/dues/{$id}", $mh)->assertOk();
        $this->postJson("/api/v1/kin/dues/{$id}/payments", $body, $mh)->assertForbidden();
        $this->postJson('/api/v1/kin/dues', ['name' => 'Nope', 'kind' => 'once', 'amount_mode' => 'any'], $this->as('kin_secretary'))->assertForbidden();
        $pid = $this->postJson("/api/v1/kin/dues/{$id}/payments", $body, $this->as('kin_treasurer'))->assertCreated()->json('data.id');
        // Any amount: no balance to go over.
        $this->postJson("/api/v1/kin/dues/{$id}/payments", ['amount_minor' => 900_000] + $body, $this->head)->assertCreated();
        $this->postJson("/api/v1/kin/dues/payments/{$pid}/reverse", ['reason' => 'x'], $mh)->assertForbidden();

        // "My dues": empty until the sign-in is tied to a person, then that person's own.
        $this->getJson('/api/v1/kin/dues/mine', $mh)->assertOk()->assertJsonPath('data.member', null)->assertJsonPath('data.lines', []);
        $this->patchJson("/api/v1/kin/people/{$amos}", ['user_id' => $member->id], $this->head)->assertOk()->assertJsonPath('data.user_id', $member->id);
        $mine = $this->getJson('/api/v1/kin/dues/mine', $mh)->assertOk()->json('data');
        $this->assertSame($amos, $mine['member']['id']);
        $this->assertSame('once', $mine['lines'][0]['period']);
        $this->assertSame($id, $mine['lines'][0]['programme_id']);
        $this->assertSame(1_150_000, $mine['paid_minor']);
        $this->assertCount(2, $mine['payments']);

        // Another family sees and touches none of it.
        [, , $other] = $this->kinAccount();
        $oh = ['Authorization' => 'Bearer '.$other];
        $this->getJson("/api/v1/kin/dues/{$id}", $oh)->assertNotFound();
        $this->getJson("/api/v1/kin/dues/{$id}/share", $oh)->assertNotFound();
        $this->postJson("/api/v1/kin/dues/{$id}/payments", $body, $oh)->assertNotFound();
        $this->postJson("/api/v1/kin/dues/payments/{$pid}/reverse", ['reason' => 'x'], $oh)->assertNotFound();
        $this->getJson('/api/v1/kin/dues', $oh)->assertOk()->assertJsonCount(0, 'data.programmes');
        $mineOwn = $this->postJson('/api/v1/kin/dues', ['name' => 'Ours', 'kind' => 'once', 'amount_mode' => 'any'], $oh)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kin/dues/{$mineOwn}/payments", $body, $oh)->assertNotFound()->assertJsonPath('errors.code', 'not_found');
    }
}
