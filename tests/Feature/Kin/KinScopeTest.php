<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\KinBranch;
use App\Models\SaccoMember;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * A branch coordinator looks after their branch's records only (family-roots docs 02 #11), on the API and through sync;
 * brothers and sisters are added in one step; a marriage's status never hides the spouse's own. Every name is made up.
 */
class KinScopeTest extends ApiTestCase
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

    private function add(array $data, ?array $h = null): int
    {
        return (int) $this->postJson('/api/v1/kin/people', $data, $h ?? $this->head)->assertCreated()->json('data.id');
    }

    private function branch(string $name, ?int $coordinator = null): int
    {
        $b = new KinBranch(['company_id' => $this->co->id, 'name' => $name, 'coordinator_user_id' => $coordinator]);
        $b->save();

        return (int) $b->id;
    }

    public function test_a_coordinator_changes_only_their_branch(): void
    {
        $user = $this->kinStaff($this->co, 'kin_branch_coordinator');
        $coord = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
        $mine = $this->branch('Branch of Amos', $user->id);
        $theirs = $this->branch('Branch of Ruth');
        $inMine = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample', 'branch_id' => $mine]);
        $inTheirs = $this->add(['first_name' => 'Ruth', 'last_name' => 'Sample', 'branch_id' => $theirs]);

        $this->getJson('/api/v1/kin/tree', $coord)->assertOk()->assertJsonPath('meta.access.people', 'branch')->assertJsonPath('meta.access.branch_ids', [$mine]);
        $this->getJson('/api/v1/kin/tree', $this->head)->assertOk()->assertJsonPath('meta.access.people', 'all');
        $this->getJson('/api/v1/kin/tree', ['Authorization' => 'Bearer '.$this->kinStaff($this->co, 'kin_member')->createToken('t')->plainTextToken])
            ->assertJsonPath('meta.access.people', 'none');

        $this->patchJson("/api/v1/kin/people/{$inMine}", ['occupation' => 'Teacher'], $coord)->assertOk();
        $this->patchJson("/api/v1/kin/people/{$inTheirs}", ['occupation' => 'Teacher'], $coord)->assertForbidden();
        $this->patchJson("/api/v1/kin/people/{$inMine}", ['branch_id' => $theirs], $coord)->assertForbidden();

        $kid = $this->add(['first_name' => 'Joel', 'last_name' => 'Sample'], $coord);
        $this->assertSame($mine, (int) SaccoMember::withoutGlobalScopes()->find($kid)->branch_id, 'a coordinator\'s new person lands in their branch');
        $this->postJson('/api/v1/kin/people', ['first_name' => 'Nope', 'branch_id' => $theirs], $coord)->assertForbidden();

        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $inMine, 'role' => 'father'], $coord)->assertCreated();
        $outside = $this->add(['first_name' => 'Esther', 'branch_id' => $theirs]);
        $this->postJson('/api/v1/kin/links', ['child_id' => $outside, 'parent_id' => $inTheirs, 'role' => 'mother'], $coord)->assertForbidden();
        $s = $this->postJson('/api/v1/kin/spouses', ['person_id' => $inTheirs, 'spouse_name' => 'A husband'], $this->head)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/kin/spouses/{$s}", [], $coord)->assertForbidden();
        $this->postJson('/api/v1/kin/spouses', ['person_id' => $inMine, 'spouse_id' => $inTheirs], $coord)->assertCreated();

        // The same rule on the phone's sync door.
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $coord)->assertOk();
        $push = fn (string $uuid, string $action, array $data) => $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sacco_members', 'uuid' => $uuid, 'action' => $action, 'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data],
        ]]]], $coord + ['X-Device-Id' => $device])->assertOk()->json('data.results.0');
        $uuidOf = fn (int $id) => (string) SaccoMember::withoutGlobalScopes()->whereKey($id)->value('uuid');
        $this->assertSame('rejected', $push($uuidOf($inTheirs), 'update', ['first_name' => 'Changed'])['status']);
        $this->assertSame('rejected', $push((string) Str::uuid(), 'insert', ['first_name' => 'New'])['status']);
        $this->assertSame('Ruth', SaccoMember::withoutGlobalScopes()->find($inTheirs)->first_name);
    }

    public function test_a_sibling_gets_the_persons_parents_in_one_step(): void
    {
        $dad = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample', 'gender' => 'male']);
        $mum = $this->add(['first_name' => 'Ruth', 'last_name' => 'Sample', 'gender' => 'female']);
        $kid = $this->add(['first_name' => 'Joel', 'last_name' => 'Sample']);
        $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'person' => ['first_name' => 'Too soon']], $this->head)
            ->assertStatus(422)->assertJsonPath('errors.code', 'no_parents');
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $dad, 'role' => 'father'], $this->head)->assertCreated();
        $this->postJson('/api/v1/kin/links', ['child_id' => $kid, 'parent_id' => $mum, 'role' => 'mother'], $this->head)->assertCreated();

        $new = $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'person' => ['first_name' => 'Sara', 'last_name' => 'Sample', 'gender' => 'female']], $this->head)
            ->assertCreated()->assertJsonCount(2, 'data.links')->json('data.sibling.id');
        $existing = $this->add(['first_name' => 'Paul', 'last_name' => 'Sample']);
        $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'sibling_id' => $existing], $this->head)->assertCreated()->assertJsonCount(2, 'data.links');
        $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'sibling_id' => $existing], $this->head)->assertCreated()->assertJsonCount(0, 'data.links');
        $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'sibling_id' => $kid], $this->head)->assertStatus(422);
        $this->postJson('/api/v1/kin/siblings', ['person_id' => $kid, 'person' => ['first_name' => 'Nope']], $this->as('kin_member'))->assertForbidden();

        $siblings = collect($this->getJson("/api/v1/kin/people/{$kid}", $this->head)->json('data.siblings'));
        $this->assertEqualsCanonicalizing([$new, $existing], $siblings->pluck('id')->all());
        $this->assertSame(['full'], $siblings->pluck('relation')->unique()->values()->all());
    }

    public function test_a_spouse_keeps_their_own_status_beside_the_marriages(): void
    {
        $dad = $this->add(['first_name' => 'Amos', 'last_name' => 'Sample']);
        $mum = $this->add(['first_name' => 'Ruth', 'last_name' => 'Sample', 'status' => 'deceased', 'date_of_death' => '2010-01-01']);
        $this->postJson('/api/v1/kin/spouses', ['person_id' => $dad, 'spouse_id' => $mum, 'status' => 'widowed'], $this->head)->assertCreated();
        $this->postJson('/api/v1/kin/spouses', ['person_id' => $dad, 'spouse_name' => 'A first wife', 'status' => 'ended'], $this->head)->assertCreated();

        $spouses = collect($this->getJson("/api/v1/kin/people/{$dad}", $this->head)->json('data.spouses'))->keyBy('name');
        $this->assertSame('deceased', $spouses->first(fn ($s) => $s['id'] === $mum)['status']);
        $this->assertSame('widowed', $spouses->first(fn ($s) => $s['id'] === $mum)['marriage_status']);
        $this->assertNull($spouses['A first wife']['status']);
        $this->assertSame('ended', $spouses['A first wife']['marriage_status']);
    }

    /** @return array<string, string> */
    private function as(string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->kinStaff($this->co, $role)->createToken('t')->plainTextToken];
    }
}
