<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * Doc 13.3: memories. The head and the secretary add photos, voice notes and documents; members add stories in words;
 * a coordinator tags their own branch's people only; file types and sizes are checked; a full plan refuses with
 * storage_full; usage is reported; deleting frees the room; the person page lists their memories. Every name is made up.
 */
class KinMemoriesTest extends ApiTestCase
{
    use MakesKin;

    private Company $co;

    /** @var array<string, string> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        [$this->co, , $token] = $this->kinAccount();
        $this->head = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function person(string $first): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'female'])->id;
    }

    /** @return array<string, string> */
    private function bearer(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** A multipart post (files go through the real request, not JSON). */
    private function upload(array $data, array $h)
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/kin/memories', $data, $h);
    }

    public function test_photos_stories_limits_and_the_person_page(): void
    {
        $ann = $this->person('Ann');
        $member = $this->bearer($this->kinStaff($this->co, 'kin_member'));

        $id = $this->upload(['kind' => 'photo', 'title' => 'The old home', 'people' => [$ann], 'happened_year' => 1990,
            'files' => [UploadedFile::fake()->image('home.jpg', 1600, 1000), UploadedFile::fake()->image('gate.png', 800, 600)]], $this->head)
            ->assertCreated()->assertJsonCount(2, 'data.files')->assertJsonPath('data.people.0.id', $ann)->json('data.id');
        $files = $this->getJson("/api/v1/kin/memories/{$id}", $this->head)->assertOk()->json('data.files');
        $this->assertNotNull($files[0]['thumb_url'], 'a photo has a small copy');
        $this->assertSame(4, StoredFile::withoutGlobalScopes()->where('company_id', $this->co->id)->whereIn('purpose', ['kin_memory', 'kin_memory_thumb'])->count());

        // Members: stories in words only.
        $this->upload(['kind' => 'story', 'title' => 'Grandmother\'s bread', 'body' => 'Millet, water and patience.', 'people' => [$ann]], $member)->assertCreated();
        $this->upload(['kind' => 'photo', 'title' => 'Mine', 'files' => [UploadedFile::fake()->image('a.jpg')]], $member)->assertForbidden();

        // Types and sizes.
        $this->upload(['kind' => 'photo', 'title' => 'No file'], $this->head)->assertStatus(422)->assertJsonPath('errors.code', 'files_required');
        $this->upload(['kind' => 'document', 'title' => 'Exe', 'files' => [UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')]], $this->head)
            ->assertStatus(422)->assertJsonPath('errors.code', 'file_type');
        $this->upload(['kind' => 'document', 'title' => 'Big', 'files' => [UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')]], $this->head)
            ->assertStatus(422)->assertJsonPath('errors.code', 'file_too_large');
        $this->upload(['kind' => 'voice', 'title' => 'Song', 'files' => [UploadedFile::fake()->create('song.mp3', 300, 'audio/mpeg')]], $this->head)->assertCreated();

        // The person page and the filters.
        $p = $this->getJson("/api/v1/kin/people/{$ann}", $member)->assertOk()->json('data');
        $this->assertSame(2, $p['memories_count']);
        $this->assertCount(2, $p['memories']);
        $this->assertCount(1, $this->getJson("/api/v1/kin/memories?person={$ann}&kind=photo", $member)->json('data.memories'));
        $this->assertCount(3, $this->getJson('/api/v1/kin/memories', $member)->json('data.memories'));

        // Usage, then delete: the files go and the room comes back.
        $before = $this->getJson('/api/v1/kin/memories/usage', $this->head)->assertOk()->json('data');
        $this->assertGreaterThan(0, $before['used_bytes']);
        $this->assertSame(20000 * 1048576, $before['limit_bytes']);
        $this->deleteJson("/api/v1/kin/memories/{$id}", [], $member)->assertForbidden();
        $this->deleteJson("/api/v1/kin/memories/{$id}", [], $this->head)->assertOk();
        $this->assertLessThan($before['used_bytes'], $this->getJson('/api/v1/kin/memories/usage', $this->head)->json('data.used_bytes'));
        $this->getJson("/api/v1/kin/memories/{$id}", $this->head)->assertNotFound();
    }

    public function test_a_full_plan_refuses_with_storage_full_and_stores_nothing(): void
    {
        $limit = 20000 * 1048576;
        foreach ([4_000_000_000, 4_000_000_000, 4_000_000_000, 4_000_000_000, 4_000_000_000, $limit - 20_000_000_000 - 10] as $bytes) { // the column holds up to 4 GB a row
            StoredFile::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'uuid' => (string) Str::uuid(), 'purpose' => 'kin_photo', 'path' => 'files/x.jpg',
                'mime' => 'image/jpeg', 'size_bytes' => $bytes]);
        }
        $count = StoredFile::withoutGlobalScopes()->where('company_id', $this->co->id)->count();

        $r = $this->upload(['kind' => 'photo', 'title' => 'Too much', 'files' => [UploadedFile::fake()->image('a.jpg', 400, 300)]], $this->head)
            ->assertStatus(422)->assertJsonPath('errors.code', 'storage_full')->json('errors');
        $this->assertSame([$limit - 10, $limit], [$r['used_bytes'], $r['limit_bytes']]);
        $this->assertSame($count, StoredFile::withoutGlobalScopes()->where('company_id', $this->co->id)->count());
        // A story in words takes no room and still goes in.
        $this->upload(['kind' => 'story', 'title' => 'Words only', 'body' => 'Still fine.'], $this->head)->assertCreated();
    }

    public function test_a_coordinator_tags_their_own_branch_only(): void
    {
        $coordUser = $this->kinStaff($this->co, 'kin_branch_coordinator');
        $branch = (int) \App\Models\KinBranch::create(['company_id' => $this->co->id, 'name' => 'House of Sample', 'coordinator_user_id' => $coordUser->id])->id;
        $mine = $this->person('Mine');
        SaccoMember::withoutGlobalScopes()->whereKey($mine)->update(['branch_id' => $branch]);
        $theirs = $this->person('Theirs');
        $coord = $this->bearer($coordUser);
        $pic = fn () => [UploadedFile::fake()->image('p.jpg', 300, 300)];

        $this->upload(['kind' => 'photo', 'title' => 'Ours', 'people' => [$mine], 'files' => $pic()], $coord)->assertCreated();
        $this->upload(['kind' => 'photo', 'title' => 'Theirs', 'people' => [$theirs], 'files' => $pic()], $coord)->assertForbidden();
        $this->upload(['kind' => 'photo', 'title' => 'Nobody', 'files' => $pic()], $coord)->assertForbidden();
    }
}
