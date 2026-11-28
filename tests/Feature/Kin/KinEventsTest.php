<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\User;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * Doc 13.2: gatherings. The head and the secretary plan; everyone answers for themself; the register and the minutes
 * close a meeting; a cancelled one takes no answers; an introduction carries its own contribution and pledges; the home
 * stats show the next gathering. Every name is made up.
 */
class KinEventsTest extends ApiTestCase
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

    private function person(string $first, ?User $user = null): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'female', 'user_id' => $user?->id])->id;
    }

    /** @return array<string, string> */
    private function bearer(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    }

    public function test_plan_answer_register_minutes_and_cancel(): void
    {
        $secUser = $this->kinStaff($this->co, 'kin_secretary');
        $memUser = $this->kinStaff($this->co, 'kin_member');
        $sec = $this->bearer($secUser);
        $member = $this->bearer($memUser);
        $ann = $this->person('Ann', $memUser);
        $bob = $this->person('Bob');

        $this->getJson('/api/v1/kin/stats', $this->head)->assertOk()->assertJsonPath('data.next_event', null);

        $body = ['kind' => 'meeting', 'title' => 'Family meeting', 'starts_at' => now()->addDays(5)->setTime(10, 0)->toDateTimeString(), 'venue' => 'The old home', 'agenda' => '1. Prayer'];
        $this->postJson('/api/v1/kin/events', $body, $member)->assertForbidden();
        $this->postJson('/api/v1/kin/events', ['ends_at' => now()->addDays(4)->toDateTimeString()] + $body, $sec)->assertStatus(422)->assertJsonPath('errors.code', 'dates_invalid');
        $id = $this->postJson('/api/v1/kin/events', $body, $sec)->assertCreated()->assertJsonPath('data.event.agenda', '1. Prayer')->json('data.event.id');

        // Everyone answers for themself; a sign-in with no person in the tree cannot.
        $this->postJson("/api/v1/kin/events/{$id}/rsvp", ['rsvp' => 'going'], $member)->assertOk();
        $this->postJson("/api/v1/kin/events/{$id}/rsvp", ['rsvp' => 'going'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'no_person');
        $list = $this->getJson('/api/v1/kin/events', $member)->assertOk()->json('data.events');
        $this->assertSame([[$id, 1, 'going']], array_map(fn ($e) => [$e['id'], $e['going'], $e['my_rsvp']], $list));
        $this->assertSame([], $this->getJson('/api/v1/kin/events?when=past', $member)->json('data.events'));
        $next = $this->getJson('/api/v1/kin/stats', $member)->assertOk()->json('data.next_event');
        $this->assertSame([$id, 'Family meeting', 'meeting', 'The old home', 1, 'going'], [$next['id'], $next['title'], $next['kind'], $next['venue'], $next['going'], $next['my_rsvp']]);
        $this->assertNull($this->getJson('/api/v1/kin/stats', $this->head)->json('data.next_event.my_rsvp'), 'The head has no answer of their own.');

        // The register and the minutes: secretary only; the meeting is then held.
        $lines = ['lines' => [['member_id' => $ann, 'attendance' => 'present'], ['member_id' => $bob, 'attendance' => 'apology']]];
        $this->postJson("/api/v1/kin/events/{$id}/attendance", $lines, $member)->assertForbidden();
        $this->postJson("/api/v1/kin/events/{$id}/attendance", $lines, $sec)->assertOk();
        $this->putJson("/api/v1/kin/events/{$id}/minutes", ['minutes' => 'We agreed on the roof.'], $sec)->assertOk()->assertJsonPath('data.event.status', 'held');
        $d = $this->getJson("/api/v1/kin/events/{$id}", $member)->assertOk()->assertJsonPath('data.event.minutes', 'We agreed on the roof.')->assertJsonPath('data.event.present', 1)->json('data');
        $this->assertSame('apology', collect($d['people'])->firstWhere('member_id', $bob)['attendance'] ?? null);
        $this->postJson("/api/v1/kin/events/{$id}/rsvp", ['rsvp' => 'no'], $member)->assertStatus(422)->assertJsonPath('errors.code', 'event_over');
        $this->postJson("/api/v1/kin/events/{$id}/cancel", ['reason' => 'x'], $sec)->assertStatus(422)->assertJsonPath('errors.code', 'event_over');
        $this->getJson('/api/v1/kin/stats', $member)->assertJsonPath('data.next_event', null);

        // Cancelled: no answers.
        $other = $this->postJson('/api/v1/kin/events', ['title' => 'Picnic', 'kind' => 'gathering'] + $body, $this->head)->assertCreated()->json('data.event.id');
        $this->postJson("/api/v1/kin/events/{$other}/cancel", ['reason' => 'Rain'], $sec)->assertOk()->assertJsonPath('data.event.status', 'cancelled')->assertJsonPath('data.event.cancel_reason', 'Rain');
        $this->postJson("/api/v1/kin/events/{$other}/rsvp", ['rsvp' => 'going'], $member)->assertStatus(422)->assertJsonPath('errors.code', 'event_cancelled');

        // Another family's gathering is not found.
        [, , $otherToken] = $this->kinAccount();
        $this->getJson("/api/v1/kin/events/{$id}", ['Authorization' => 'Bearer '.$otherToken])->assertNotFound();
    }

    public function test_an_introduction_carries_a_contribution_and_pledges(): void
    {
        $memUser = $this->kinStaff($this->co, 'kin_member');
        $member = $this->bearer($memUser);
        $ann = $this->person('Ann', $memUser);
        $bob = $this->person('Bob');

        $e = $this->postJson('/api/v1/kin/events', ['kind' => 'introduction', 'title' => 'Introduction', 'starts_at' => now()->addMonth()->toDateTimeString(),
            'with_contribution' => ['name' => 'Introduction gifts', 'target_minor' => 1_000_000]], $this->head)->assertCreated()->json('data');
        $pid = $e['event']['dues_programme_id'];
        $this->assertNotNull($pid);
        $this->assertSame('Introduction gifts', $e['contribution']['name']);

        $this->postJson("/api/v1/kin/dues/{$pid}/pledge", ['member_id' => $ann, 'amount_minor' => 200_000], $member)->assertOk()
            ->assertJsonPath('data.pledged_minor', 200_000)->assertJsonPath('data.balance_minor', 200_000);
        $this->postJson("/api/v1/kin/dues/{$pid}/pledge", ['member_id' => $bob, 'amount_minor' => 100_000], $member)->assertStatus(403);
        $this->postJson("/api/v1/kin/dues/{$pid}/pledge", ['member_id' => $bob, 'amount_minor' => 100_000], $this->head)->assertOk();
        $this->postJson("/api/v1/kin/dues/{$pid}/payments", ['member_id' => $ann, 'amount_minor' => 50_000, 'method' => 'cash', 'paid_on' => now()->toDateString()], $this->head)->assertCreated();
        $this->postJson("/api/v1/kin/dues/{$pid}/pledge", ['member_id' => $ann, 'amount_minor' => 10_000], $member)->assertStatus(422)->assertJsonPath('errors.code', 'pledge_below_paid');
        $this->postJson("/api/v1/kin/dues/{$pid}/pledge", ['member_id' => $ann, 'amount_minor' => 150_000], $member)->assertOk()->assertJsonPath('data.balance_minor', 100_000);
    }
}
