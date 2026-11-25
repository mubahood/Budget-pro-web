<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\KinLink;
use App\Models\SaccoMember;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/** /api/v1/kin/…: officers write, members read, another family sees nothing. Every name is made up. */
class KinApiTest extends ApiTestCase
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

    /** @return array<string, string> */
    private function as(string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->kinStaff($this->co, $role)->createToken('t')->plainTextToken];
    }

    private function add(array $data, ?array $h = null): int
    {
        return (int) $this->postJson('/api/v1/kin/people', $data, $h ?? $this->head)->assertCreated()->json('data.id');
    }

    public function test_officers_build_the_tree_and_members_only_read(): void
    {
        $dad = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1950-02-01']);
        $mum = $this->add(['first_name' => 'Ruth', 'last_name' => 'Sample', 'gender' => 'female']);
        $sec = $this->as('kin_secretary');
        $kid = $this->add(['first_name' => 'Joel', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1980-05-05'], $sec);

        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $dad, 'role' => 'father'], $sec)->assertCreated()->assertJsonPath('data.kind', 'birth');
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $mum, 'role' => 'mother'], $this->head)->assertCreated();
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $kid, 'role' => 'parent'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'link_self');
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $dad, 'role' => 'father'], $this->head)->assertStatus(409);
        $spouse = $this->postJson('/api/v1/kin/spouses', ['person_id' => $dad, 'spouse_id' => $mum, 'married_on' => '1975-01-01'], $this->head)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/kin/spouses', ['person_id' => $dad, 'spouse_name' => 'A first wife', 'status' => 'ended'], $this->head)->assertCreated();
        $this->patchJson("/api/v1/kin/spouses/{$spouse}", ['status' => 'widowed'], $this->head)->assertOk()->assertJsonPath('data.status', 'widowed');

        $show = $this->getJson("/api/v1/kin/people/{$kid}", $this->head)->assertOk();
        $this->assertSame(2, $show->json('data.generation'));
        $this->assertCount(2, $show->json('data.parents'));

        $member = $this->as('kin_member');
        $this->getJson('/api/v1/kin/people', $member)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/kin/tree', $member)->assertOk();
        $this->postJson('/api/v1/kin/people', ['first_name' => 'Nope'], $member)->assertForbidden();
        $this->patchJson("/api/v1/kin/people/{$kid}", ['first_name' => 'Nope'], $member)->assertForbidden();
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $dad, 'role' => 'parent', 'kind' => 'guardian'], $member)->assertForbidden();
        $this->deleteJson("/api/v1/kin/spouses/{$spouse}", [], $member)->assertForbidden();
        $this->postJson('/api/v1/kin/branches', ['name' => 'Nope'], $member)->assertForbidden();
        $this->postJson('/api/v1/kin/branches', ['name' => 'Nope'], $this->as('kin_treasurer'))->assertForbidden();
        $this->postJson('/api/v1/kin/branches', ['name' => 'Nope'], $this->as('kin_branch_coordinator'))->assertForbidden();

        $link = KinLink::withoutGlobalScopes()->where('child_id', $kid)->where('role', 'mother')->value('id');
        $this->deleteJson("/api/v1/kin/links/{$link}", [], $sec)->assertOk();
        $this->assertCount(1, $this->getJson("/api/v1/kin/people/{$kid}", $member)->json('data.parents'));
    }

    public function test_another_family_sees_nothing_and_cannot_link_across(): void
    {
        $mine = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample']);
        [, , $token] = $this->kinAccount();
        $other = ['Authorization' => 'Bearer '.$token];
        $theirs = $this->add(['first_name' => 'Eve', 'last_name' => 'Other'], $other);

        $this->getJson("/api/v1/kin/people/{$mine}", $other)->assertNotFound();
        $this->getJson('/api/v1/kin/people', $other)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/kin/people/{$mine}", ['first_name' => 'X'], $other)->assertNotFound();
        $this->postJson('/api/v1/kin/links', ['child_id' => $theirs, 'parent_id' => $mine, 'role' => 'father'], $other)->assertStatus(422)->assertJsonPath('errors.code', 'not_in_family');
        $this->assertNotContains($mine, array_column($this->getJson('/api/v1/kin/tree', $other)->json('data.people'), 'id'));

        // A shop account has no kin module at all.
        $shop = $this->registerTenant();
        $this->getJson('/api/v1/kin/people', ['Authorization' => 'Bearer '.$shop['token']])->assertForbidden();
    }

    public function test_death_rules(): void
    {
        $id = $this->add(['first_name' => 'Old', 'last_name' => 'Sample', 'date_of_birth' => '1930-01-01']);
        $this->patchJson("/api/v1/kin/people/{$id}", ['date_of_death' => '2000-01-01'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'death_needs_status');
        $this->patchJson("/api/v1/kin/people/{$id}", ['status' => 'deceased', 'date_of_death' => '1920-01-01'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'death_before_birth');
        $this->patchJson("/api/v1/kin/people/{$id}", ['status' => 'deceased', 'date_of_death' => now()->addDays(3)->toDateString()], $this->head)->assertStatus(422);
        $this->patchJson("/api/v1/kin/people/{$id}", ['status' => 'deceased', 'date_of_death' => '2001-04-04', 'place_of_death' => 'Home'], $this->head)
            ->assertOk()->assertJsonPath('data.date_of_death', '2001-04-04');
        // Back to living clears the death.
        $this->patchJson("/api/v1/kin/people/{$id}", ['status' => 'active'], $this->head)->assertOk()->assertJsonPath('data.date_of_death', null);
        $this->postJson('/api/v1/kin/people', ['first_name' => 'X', 'in_purse' => true], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'purse_off');
    }

    public function test_the_tree_answers_304_until_something_changes(): void
    {
        $this->add(['first_name' => 'Amos', 'last_name' => 'Sample']);
        $r = $this->getJson('/api/v1/kin/tree', $this->head)->assertOk();
        $etag = $r->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(1, $r->json('data.generations'));
        $this->getJson('/api/v1/kin/tree', $this->head + ['If-None-Match' => $etag])->assertStatus(304);
        $this->add(['first_name' => 'Ruth', 'last_name' => 'Sample']);
        $this->getJson('/api/v1/kin/tree', $this->head + ['If-None-Match' => $etag])->assertOk()->assertJsonCount(2, 'data.people');
    }

    public function test_branches_crud_and_a_branch_in_use_stays(): void
    {
        $founder = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample']);
        $sec = $this->as('kin_secretary');
        $b = $this->postJson('/api/v1/kin/branches', ['name' => 'House of Amos', 'founder_id' => $founder], $sec)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/kin/branches/{$b}", ['name' => 'Amos House'], $sec)->assertOk()->assertJsonPath('data.name', 'Amos House');
        $this->patchJson("/api/v1/kin/people/{$founder}", ['branch_id' => $b], $this->head)->assertOk()->assertJsonPath('data.branch_id', $b);
        $this->getJson('/api/v1/kin/branches', $this->as('kin_member'))->assertOk()->assertJsonPath('data.0.people', 1);
        $this->deleteJson("/api/v1/kin/branches/{$b}", [], $sec)->assertStatus(422)->assertJsonPath('errors.code', 'branch_in_use');
        $this->patchJson("/api/v1/kin/people/{$founder}", ['branch_id' => null], $this->head)->assertOk();
        $this->deleteJson("/api/v1/kin/branches/{$b}", [], $sec)->assertOk();
        $this->getJson("/api/v1/kin/branches/{$b}", $sec)->assertNotFound();
    }

    public function test_a_phone_pushes_people_and_links_through_sync(): void
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->head)->assertOk();
        [$dad, $kid, $link] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $op = fn (string $table, string $uuid, array $data) => ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid, 'action' => 'insert',
            'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [
            $op('sacco_members', $dad, ['first_name' => 'Amos', 'last_name' => 'Sample', 'gender' => 'male', 'status' => 'deceased', 'date_of_death' => '2001-01-01']),
            $op('sacco_members', $kid, ['first_name' => 'Joel', 'last_name' => 'Sample']),
            $op('kin_links', $link, ['child_uuid' => $kid, 'parent_uuid' => $dad, 'role' => 'father', 'kind' => 'birth']),
        ]]]], $this->head + ['X-Device-Id' => $device])->assertOk();
        foreach ($r->json('data.results') as $res) {
            $this->assertSame('applied', $res['status'], json_encode($res));
        }
        $kidRow = SaccoMember::withoutGlobalScopes()->where('company_id', $this->co->id)->where('uuid', $kid)->first();
        $this->assertSame(2, (int) $kidRow->generation, 'the tree is worked out once the batch is in');
        $this->assertSame('deceased', SaccoMember::withoutGlobalScopes()->where('company_id', $this->co->id)->where('uuid', $dad)->value('status'));
    }

    public function test_a_member_cannot_push_tree_changes_and_a_sacco_phone_cannot_send_family_fields(): void
    {
        $member = $this->as('kin_member');
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $member)->assertOk();
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sacco_members', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'version' => 0,
                'data' => ['first_name' => 'Nope']],
        ]]]], $member + ['X-Device-Id' => $device])->assertOk();
        $this->assertNotSame('applied', $r->json('data.results.0.status'));
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $this->co->id)->count());

        $this->assertSame(['exclude'], \App\Support\Rules\SaccoMemberRules::kinSyncRules((int) $this->registerTenant(['modules' => ['sacco']])['company_id'])['status']);
    }
}
