<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Support\AppFamily;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/** The family purse (T1), announcements, a person's photo, and the tree's meta.me_id. Every name is made up. */
class KinPurseAnnouncePhotoTest extends ApiTestCase
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

    private function person(string $first): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'first_name' => $first,
            'last_name' => 'Sample', 'gender' => 'female'])->id;
    }

    /** @return array<string, string> */
    private function bearer(\App\Models\User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    }

    public function test_the_head_switches_the_purse_on_and_the_family_app_still_opens(): void
    {
        $ann = $this->person('Ann');
        $this->getJson('/api/v1/kin/purse', $this->head)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.can_enable', true)
            ->assertJsonPath('data.currency', 'UGX');
        $treasurer = $this->bearer($this->kinStaff($this->co, 'kin_treasurer'));
        $this->getJson('/api/v1/kin/purse', $treasurer)->assertOk()->assertJsonPath('data.can_enable', false);
        $this->postJson('/api/v1/kin/purse', ['enabled' => true], $treasurer)->assertForbidden();

        $this->postJson('/api/v1/kin/purse', ['enabled' => true, 'member_ids' => [$ann]], $this->head)->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.members', 1)->assertJsonPath('data.can_enable', false);
        $co = $this->co->fresh();
        $this->assertSame(['kin', 'sacco'], $co->modules());
        // T1: the family app still opens this account, and the SACCO screens work for it.
        $this->assertTrue(AppFamily::opens($co->modules(), 'family-roots'));
        $people = collect($this->getJson('/api/v1/kin/tree', $this->head)->assertOk()->json('data.people'))->keyBy('id');
        $this->assertTrue((bool) $people[$ann]['in_purse'], 'The tree says who is in the purse.');
        $this->getJson('/api/v1/sacco/members', $this->head)->assertOk();
        $this->getJson('/api/v1/sacco/savings-accounts', $this->head)->assertOk()->assertJsonPath('data.0.member_id', $ann);
        // Again: nothing doubles.
        $this->postJson('/api/v1/kin/purse', ['enabled' => true, 'member_ids' => [$ann]], $this->head)->assertOk()->assertJsonPath('data.members', 1);
    }

    public function test_announcements_head_and_secretary_post_everyone_reads_what_is_published(): void
    {
        $sec = $this->bearer($this->kinStaff($this->co, 'kin_secretary'));
        $member = $this->bearer($this->kinStaff($this->co, 'kin_member'));
        $treasurer = $this->bearer($this->kinStaff($this->co, 'kin_treasurer'));

        $now = $this->postJson('/api/v1/kin/announcements', ['title' => 'Family meeting', 'body' => 'Saturday at ten.'], $sec)->assertCreated()
            ->assertJsonPath('data.pinned', false)->json('data.id');
        $this->postJson('/api/v1/kin/announcements', ['title' => 'Pinned', 'pinned' => true], $this->head)->assertCreated();
        $later = $this->postJson('/api/v1/kin/announcements', ['title' => 'Next month', 'published_at' => now()->addWeek()->toIso8601String()], $this->head)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/kin/announcements', ['title' => 'Nope'], $member)->assertForbidden();
        $this->postJson('/api/v1/kin/announcements', ['title' => 'Nope'], $treasurer)->assertForbidden();
        $this->patchJson("/api/v1/kin/announcements/{$now}", ['title' => 'Nope'], $member)->assertForbidden();
        $this->deleteJson("/api/v1/kin/announcements/{$now}", [], $treasurer)->assertForbidden();

        $read = $this->getJson('/api/v1/kin/announcements', $member)->assertOk()->json('data');
        $this->assertSame(['Pinned', 'Family meeting'], array_column($read, 'title'), 'pinned first; nothing set for later');
        $this->getJson("/api/v1/kin/announcements/{$later}", $member)->assertNotFound();
        $this->assertCount(3, $this->getJson('/api/v1/kin/announcements', $sec)->json('data'));

        $this->patchJson("/api/v1/kin/announcements/{$now}", ['pinned' => true], $sec)->assertOk()->assertJsonPath('data.pinned', true);
        $this->deleteJson("/api/v1/kin/announcements/{$now}", [], $sec)->assertOk();
        $this->assertSoftDeleted('kin_announcements', ['id' => $now]);
        [, , $other] = $this->kinAccount();
        $this->getJson("/api/v1/kin/announcements/{$later}", ['Authorization' => 'Bearer '.$other])->assertNotFound();
    }

    /** A multipart upload, as the phone sends it (each request resolves its own token, as ApiTestCase::json does). */
    private function upload(int $id, UploadedFile $file, array $h): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post("/api/v1/kin/people/{$id}/photo", ['photo' => $file], $h + ['Accept' => 'application/json']);
    }

    public function test_a_photo_is_squared_stored_and_shown_in_the_tree(): void
    {
        Storage::fake('public');
        $ann = $this->person('Ann');
        $etag = $this->getJson('/api/v1/kin/tree', $this->head)->assertOk()->headers->get('ETag');

        $member = $this->bearer($this->kinStaff($this->co, 'kin_member'));
        $this->upload($ann, UploadedFile::fake()->image('ann.jpg', 800, 600), $member)->assertForbidden();
        $this->upload($ann, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), $this->head)->assertStatus(422);

        $url = $this->upload($ann, UploadedFile::fake()->image('ann.jpg', 800, 600), $this->head)
            ->assertOk()->json('data.photo_url');
        $this->assertNotEmpty($url);
        $path = \DB::table('files')->where('id', SaccoMember::withoutGlobalScopes()->find($ann)->photo_file_id)->value('path');
        Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([320, 320], [$w, $h]);

        $tree = $this->getJson('/api/v1/kin/tree', $this->head)->assertOk();
        $this->assertNotSame($etag, $tree->headers->get('ETag'));
        $this->assertSame($url, collect($tree->json('data.people'))->firstWhere('id', $ann)['photo_url']);
    }

    public function test_the_tree_says_which_person_is_me(): void
    {
        $ann = $this->person('Ann');
        $user = $this->kinStaff($this->co, 'kin_member');
        $h = $this->bearer($user);
        $this->getJson('/api/v1/kin/tree', $h)->assertOk()->assertJsonPath('meta.me_id', null);
        $this->patchJson("/api/v1/kin/people/{$ann}", ['user_id' => $user->id], $this->head)->assertOk();
        $this->getJson('/api/v1/kin/tree', $h)->assertOk()->assertJsonPath('meta.me_id', $ann);
        $this->getJson('/api/v1/kin/tree', $this->head)->assertOk()->assertJsonPath('meta.me_id', null);
        // A sign-in is one person: tying it to someone else unties the first.
        $bea = $this->person('Bea');
        $this->patchJson("/api/v1/kin/people/{$bea}", ['user_id' => $user->id], $this->head)->assertOk();
        $this->getJson('/api/v1/kin/tree', $h)->assertOk()->assertJsonPath('meta.me_id', $bea);
        $this->assertNull(SaccoMember::withoutGlobalScopes()->find($ann)->user_id);
    }
}
