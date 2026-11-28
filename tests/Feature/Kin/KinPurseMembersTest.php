<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoMember;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesKin;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/**
 * Doc 13.1 (T3): in a family, the purse's lists hold only the people the head put in the purse; the head joins when the
 * purse is switched on; POST kin/purse/members adds and removes one person; a plain savings group is unchanged.
 */
class KinPurseMembersTest extends ApiTestCase
{
    use MakesKin;
    use MakesSimpleSacco;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    private function person(string $first, array $extra = []): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create($extra + ['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'female'])->id;
    }

    /** @return list<int> */
    private function listed(string $url): array
    {
        return array_map('intval', array_column($this->getJson($url, $this->head)->assertOk()->json('data'), 'id'));
    }

    public function test_family_lists_hold_only_purse_people_and_the_head_joins(): void
    {
        [$this->co, $headUser, $token] = $this->kinAccount();
        $this->head = ['Authorization' => 'Bearer '.$token];
        $me = $this->person('Peter', ['user_id' => $headUser->id, 'gender' => 'male']);
        $ann = $this->person('Ann');
        $bob = $this->person('Bob', ['gender' => 'male']);
        $late = $this->person('Old', ['status' => 'deceased', 'date_of_death' => '1990-01-01']);

        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $ann, 'in_purse' => true], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'purse_off');
        $this->postJson('/api/v1/kin/purse', ['enabled' => true, 'member_ids' => [$ann]], $this->head)->assertOk()->assertJsonPath('data.members', 2);
        $this->assertTrue((bool) SaccoMember::withoutGlobalScopes()->find($me)->in_purse, 'the head joins the purse when it is switched on');

        $members = $this->listed('/api/v1/sacco/members');
        sort($members);
        $this->assertSame([$me, $ann], $members, 'Bob and the departed are family, not purse people');
        $this->assertNotContains($bob, array_map('intval', array_column($this->getJson('/api/v1/sacco/savings-accounts', $this->head)->json('data'), 'member_id')));

        // Add Bob, then take him out again (no money yet).
        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $bob, 'in_purse' => true], $this->head)->assertOk()
            ->assertJsonPath('data.member_id', $bob)->assertJsonPath('data.in_purse', true);
        $this->assertContains($bob, $this->listed('/api/v1/sacco/members'));
        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $bob, 'in_purse' => false], $this->head)->assertOk()->assertJsonPath('data.in_purse', false);
        $this->assertNotContains($bob, $this->listed('/api/v1/sacco/members'));

        // Refusals: the departed, someone with money in the purse, and anyone but the purse's keepers.
        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $late, 'in_purse' => true], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'not_living');
        DB::table('sacco_savings_accounts')->where('company_id', $this->co->id)->where('member_id', $ann)->update(['balance_minor' => 5_000]);
        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $ann, 'in_purse' => false], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'purse_has_money');
        $member = ['Authorization' => 'Bearer '.$this->kinStaff($this->co, 'kin_member')->createToken('t')->plainTextToken];
        $this->postJson('/api/v1/kin/purse/members', ['member_id' => $bob, 'in_purse' => true], $member)->assertForbidden();
    }

    public function test_a_savings_group_lists_every_member_as_before(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $a = $this->saver($company, $owner, 0, 'Alpha');
        $b = $this->saver($company, $owner, 0, 'Beta');
        SaccoMember::withoutGlobalScopes()->whereKey([$a->id, $b->id])->update(['in_purse' => 0]);

        $ids = array_map('intval', array_column($this->getJson('/api/v1/sacco/members', $this->staffHeaders($owner))->assertOk()->json('data'), 'id'));
        $this->assertContains((int) $a->id, $ids);
        $this->assertContains((int) $b->id, $ids);
    }
}
