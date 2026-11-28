<?php

namespace Tests\Feature\Learn;

use App\Models\Learn\LearnLesson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Admin\AdminTestCase;

/** Platform › Learn videos: platform admins only, a 404 on a standalone copy; add, target apps, publish, order, upload, import. */
class LearnAdminTest extends AdminTestCase
{
    use RunsAsStandalone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('admin');
    }

    public function test_platform_admin_manages_lessons_and_others_cannot(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $owner = $this->makeTenant('company')['user'];
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'How to add a member'])]);

        $this->asAdmin($admin)->get('/learn-videos')->assertOk()->assertSee('Add a lesson');
        $this->asAdmin($admin)->get('/learn-videos/create')->assertOk()->assertSee('All apps');
        $this->asAdmin($admin)->getJson('/learn-videos/youtube?url='.urlencode('https://youtu.be/abcdefghijk'))
            ->assertOk()->assertJsonPath('title', 'How to add a member')->assertJsonPath('youtube_id', 'abcdefghijk');

        // An app or "All apps" is required.
        $this->asAdmin($admin)->post('/learn-videos', ['title' => 'X', 'audience' => 'everyone', 'language' => 'en', 'source_type' => 'youtube', 'youtube' => 'abcdefghijk'])
            ->assertSessionHasErrors('apps');

        $this->asAdmin($admin)->post('/learn-videos', [
            'title' => 'Add a member', 'section' => 'Getting started', 'apps' => ['family-roots', 'akiba-ledger'], 'areas' => 'members, contributions',
            'audience' => 'officers', 'language' => 'en', 'source_type' => 'youtube', 'youtube' => 'https://www.youtube.com/watch?v=abcdefghijk', 'duration' => '2:05',
        ])->assertRedirect();
        $l = LearnLesson::query()->where('title', 'Add a member')->firstOrFail();
        $this->assertSame(['akiba-ledger', 'family-roots'], $l->apps());
        $this->assertSame(['members', 'contributions'], $l->areas);
        $this->assertSame(125, $l->duration_seconds);
        $this->assertFalse($l->published);
        $this->assertSame('getting-started', $l->section->slug);

        $this->asAdmin($admin)->get("/learn-videos/{$l->id}/edit")->assertOk()->assertSee('Add a member');
        $this->asAdmin($admin)->post("/learn-videos/{$l->id}/publish")->assertRedirect();
        $this->assertTrue($l->fresh()->published);
        $this->assertNotNull($l->fresh()->published_at);
        $this->asAdmin($admin)->get('/learn-videos?app=family-roots')->assertOk()->assertSee('Add a member');
        $this->asAdmin($admin)->get('/learn-videos?app=shelfie')->assertOk()->assertDontSee('Add a member');

        // Order: up/down and drag.
        $l2 = LearnLesson::query()->create(['title' => 'Second', 'section_id' => $l->section_id, 'all_apps' => true, 'sort' => 9, 'youtube_id' => 'abcdefghijk']);
        $this->asAdmin($admin)->post("/learn-videos/{$l2->id}/move", ['dir' => 'up'])->assertRedirect();
        $this->assertLessThan($l->fresh()->sort, $l2->fresh()->sort);
        $this->asAdmin($admin)->postJson('/learn-videos/reorder', ['ids' => [$l->id, $l2->id]])->assertOk();
        $this->assertLessThan($l2->fresh()->sort, $l->fresh()->sort);

        // A file lesson cannot go live before its file is in; the chunked upload makes it a file lesson.
        $bytes = 'not really a video';
        $q = "/learn-videos/{$l2->id}/upload/video?upload=".str_repeat('b', 32).'&offset=0&size='.strlen($bytes).'&sha1='.sha1($bytes).'&ext=mp4';
        $this->asAdmin($admin)->call('POST', $q, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)
            ->assertOk()->assertJsonPath('done', true);
        $this->assertSame('file', $l2->fresh()->source_type);
        Storage::disk('admin')->assertExists($l2->fresh()->file_path);

        // Shop owners never reach it; a standalone copy has no such page.
        $this->asAdmin($owner)->get('/learn-videos')->assertForbidden();
        $this->asAdmin($owner)->post("/learn-videos/{$l->id}/publish")->assertForbidden();
        $this->asStandalone();
        $this->asAdmin($admin)->get('/learn-videos')->assertNotFound();
    }

    public function test_import_adds_then_updates_by_key(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'learn').'.json';
        file_put_contents($file, json_encode([
            'sections' => [['slug' => 'imp-start', 'title' => 'Start here']],
            'lessons' => [
                ['key' => 'imp.one', 'section' => 'imp-start', 'title' => 'One', 'apps' => 'all', 'youtube' => 'abcdefghijk'],
                ['key' => 'imp.two', 'section' => 'imp-start', 'title' => 'Two', 'apps' => ['kin'], 'youtube' => 'https://youtu.be/abcdefghijk', 'audience' => 'members'],
                ['key' => 'imp.bad', 'title' => 'Bad', 'apps' => ['nowhere'], 'youtube' => 'abcdefghijk'],
            ],
        ]));
        $this->assertSame(1, Artisan::call('learn:import', ['file' => $file, '--dry-run' => true]));
        $this->assertNull(LearnLesson::query()->where('key', 'imp.one')->first());

        Artisan::call('learn:import', ['file' => $file]);
        Artisan::call('learn:import', ['file' => $file, '--publish' => true]);
        $this->assertSame(1, LearnLesson::query()->where('key', 'imp.two')->count());
        $two = LearnLesson::query()->where('key', 'imp.two')->firstOrFail();
        $this->assertSame(['family-roots'], $two->apps());
        $this->assertTrue($two->published);
        $this->assertTrue(LearnLesson::query()->where('key', 'imp.one')->value('all_apps'));
        @unlink($file);
    }
}
