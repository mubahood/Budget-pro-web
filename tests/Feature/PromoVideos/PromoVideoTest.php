<?php

namespace Tests\Feature\PromoVideos;

use App\Models\PromoVideo;
use App\Models\PromoVideoRender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Promo videos (config/promo_videos.php): the studio API behind its token, scripts that never lose website edits,
 * chunked files checked on arrival, the render queue, publishing only a current render, the public feed showing only
 * what is live, and the admin screens for platform admins only.
 */
class PromoVideoTest extends AdminTestCase
{
    use RunsAsStandalone;

    private const TOKEN = 'studio-test-token-0123456789abcdef';

    private const API = '/api/studio-api';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('admin');
        Cache::forget('promo-videos:worker-seen');
        config(['promo_videos.token_sha256' => hash('sha256', self::TOKEN)]);
    }

    private function studio(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json']);
    }

    private function script(string $last = 'Every shilling counts.'): array
    {
        return [['id' => 'cold', 'template' => 'cold_open', 'lines' => [['speaker' => 'chairman', 'text' => 'Kale. Where do we start?']]],
            ['id' => 'next', 'template' => 'series_next', 'lines' => [['speaker' => 'narrator', 'text' => $last]]]];
    }

    private function push(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->studio()->putJson(self::API.'/videos/pledge-and-budget/get-started', $extra + [
            'title' => 'Get Started', 'tagline' => 'Ready in minutes.', 'series' => ['key' => 'how-it-works', 'part' => 7, 'total' => 7],
            'script' => $this->script(), 'spec' => ['id' => '07-get-started'],
        ]);
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 3000);
    }

    private function send(string $kind, string $bytes, string $ext, int $chunk = 1024): \Illuminate\Testing\TestResponse
    {
        $up = bin2hex(random_bytes(16));
        $sha = sha1($bytes);
        $r = null;
        for ($off = 0; $off < strlen($bytes); $off += $chunk) {
            $r = $this->studio()->call('POST', self::API."/videos/pledge-and-budget/get-started/files/{$kind}?upload={$up}&offset={$off}&size="
                .strlen($bytes)."&sha1={$sha}&ext={$ext}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                    'HTTP_ACCEPT' => 'application/json'], substr($bytes, $off, $chunk));
        }

        return $r;
    }

    private function rendered(int $rev): void
    {
        $this->send('video', $this->mp4(), 'mp4')->assertOk()->assertJson(['done' => true]);
        $this->studio()->postJson(self::API.'/videos/pledge-and-budget/get-started/rendered', [
            'script_rev' => $rev, 'duration' => 124.13, 'qa' => ['pass' => true, 'lufs' => -14.1],
            'transcript' => [['scene' => 'cold', 'start' => 1.2, 'dur' => 2.9, 'speaker' => 'chairman', 'text' => 'Kale. Where do we start?']],
        ])->assertOk();
    }

    public function test_the_studio_api_needs_its_token_and_is_off_without_one(): void
    {
        $this->getJson(self::API.'/videos')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer wrong'])->getJson(self::API.'/videos')->assertUnauthorized();
        $this->flushHeaders()->withHeaders(['X-Studio-Token' => self::TOKEN, 'Accept' => 'application/json'])->getJson(self::API.'/videos')->assertOk(); // where Apache drops Authorization
        $this->flushHeaders()->withHeaders(['X-Studio-Token' => 'wrong', 'Accept' => 'application/json'])->getJson(self::API.'/videos')->assertUnauthorized();
        $this->flushHeaders();
        $this->studio()->getJson(self::API.'/videos')->assertOk();
        config(['promo_videos.token_sha256' => null]);
        $this->studio()->getJson(self::API.'/videos')->assertStatus(503);
    }

    public function test_website_edits_are_never_overwritten_by_the_studio_without_force(): void
    {
        $this->push()->assertOk()->assertJsonPath('video.script_rev', 1);
        $this->push(['base_rev' => 1])->assertOk()->assertJsonPath('video.script_rev', 1);          // same words: same revision
        $this->push(['base_rev' => 1, 'script' => $this->script('New words.')])->assertOk()->assertJsonPath('video.script_rev', 2);

        $admin = $this->makeTenant('admin')['user'];
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/script", ['lines' => [1 => [0 => 'Edited on the website.']]])->assertRedirect();
        $this->assertSame(3, $v->fresh()->script_rev);

        $this->push(['base_rev' => 2, 'script' => $this->script('Studio words.')])->assertStatus(409);
        $this->studio()->getJson(self::API.'/videos/pledge-and-budget/get-started')->assertJsonPath('video.script.1.lines.0.text', 'Edited on the website.');
        $this->push(['base_rev' => 2, 'script' => $this->script('Studio words.'), 'force' => true])->assertOk()->assertJsonPath('video.script_rev', 4);
    }

    public function test_files_arrive_in_order_whole_and_of_the_right_kind(): void
    {
        $this->push()->assertOk();
        $bytes = $this->mp4();
        $up = bin2hex(random_bytes(16));
        $q = fn (int $off, string $sha) => self::API."/videos/pledge-and-budget/get-started/files/video?upload={$up}&offset={$off}&size=".strlen($bytes)."&sha1={$sha}&ext=mp4";
        $srv = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', $q(1024, sha1($bytes)), [], [], [], $srv, substr($bytes, 1024, 1024))->assertStatus(409)->assertJsonFragment(['message' => 'Out of order: received 0']);
        $this->call('POST', $q(0, sha1('other')), [], [], [], $srv, $bytes)->assertStatus(422);         // damaged
        $this->send('video', 'just some text, not a film', 'mp4')->assertStatus(422);                   // not an mp4
        $this->send('video', $bytes, 'exe')->assertStatus(422);

        $this->send('video', $bytes, 'mp4')->assertOk()->assertJson(['done' => true]);
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();
        Storage::disk('admin')->assertExists($v->video_path);
        $this->assertSame(sha1($bytes), $v->video_sha1);
    }

    public function test_only_a_current_render_is_published_and_the_public_sees_only_the_live_copy(): void
    {
        $this->push()->assertOk();
        $admin = $this->makeTenant('admin')['user'];
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();

        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/publish")->assertRedirect();
        $this->assertFalse($v->fresh()->isPublished());                                               // nothing filmed yet

        $this->rendered(1);
        $this->assertSame('ready', $v->fresh()->state());
        $this->getJson('/api/v1/videos?app=pledge-and-budget')->assertOk()->assertJsonCount(0, 'data');

        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/publish")->assertRedirect();
        $live = $v->fresh();
        $this->assertSame('live', $live->state());
        $this->getJson('/api/v1/videos?app=pledge-and-budget')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'get-started')->assertJsonPath('data.0.part', 7)->assertJsonMissingPath('data.0.script');
        $this->get('/api/v1/videos/pledge-and-budget/get-started/captions.vtt')->assertOk()->assertSee('00:00:01.200 --> 00:00:04.350', false);
        $this->getJson('/api/v1/videos?app=kuku-tally')->assertJsonCount(0, 'data');

        // New words: the live film stays as it was until the new render is published.
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/script", ['lines' => [0 => [0 => 'Kale. So, where do we begin?']]]);
        $this->assertSame('draft', $v->fresh()->state());
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/publish");
        $this->assertSame($live->live_video_path, $v->fresh()->live_video_path);
        $this->getJson('/api/v1/videos?app=pledge-and-budget')->assertJsonCount(1, 'data');

        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/unpublish")->assertRedirect();
        $this->getJson('/api/v1/videos?app=pledge-and-budget')->assertJsonCount(0, 'data');
        $this->get('/api/v1/videos/pledge-and-budget/get-started/captions.vtt')->assertNotFound();
    }

    public function test_the_render_queue_gives_each_job_once(): void
    {
        $this->push()->assertOk();
        $admin = $this->makeTenant('admin')['user'];
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();

        $this->studio()->postJson(self::API.'/renders/claim', ['worker' => 'mac:1'])->assertOk()->assertJson(['render' => null]);
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/render")->assertRedirect();
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/render");
        $this->assertSame(1, PromoVideoRender::query()->where('promo_video_id', $v->id)->count());   // one at a time
        $this->assertSame('queued', $v->fresh()->state());

        $id = $this->studio()->postJson(self::API.'/renders/claim', ['worker' => 'mac:1'])->assertOk()
            ->assertJsonPath('render.slug', 'get-started')->assertJsonPath('render.script_rev', 1)->json('render.id');
        $this->studio()->postJson(self::API.'/renders/claim', ['worker' => 'mac:2'])->assertJson(['render' => null]);
        $this->assertSame('rendering', $v->fresh()->state());
        $this->studio()->postJson(self::API."/renders/{$id}", ['status' => 'done', 'log' => 'ok'])->assertOk();
        $this->studio()->postJson(self::API."/renders/{$id}", ['status' => 'failed'])->assertStatus(422);
        $this->assertNotNull(Cache::get('promo-videos:worker-seen'));
    }

    public function test_only_platform_admins_reach_the_screens_and_a_standalone_copy_has_none(): void
    {
        $this->push()->assertOk();
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();
        $admin = $this->makeTenant('admin')['user'];
        $owner = $this->makeTenant('company')['user'];

        $this->asAdmin($admin)->get('/promo-videos')->assertOk()->assertSee('Get Started')->assertSee('not running');
        $this->asAdmin($admin)->get("/promo-videos/{$v->id}")->assertOk()->assertSee('Kale. Where do we start?')->assertSee('Queue a render');
        $this->asAdmin($owner)->get('/promo-videos')->assertForbidden();
        $this->asAdmin($owner)->post("/promo-videos/{$v->id}/publish")->assertForbidden();
        $this->asAdmin($owner)->post("/promo-videos/{$v->id}/script", ['lines' => [0 => [0 => 'x']]])->assertForbidden();

        $this->asStandalone();
        $this->asAdmin($admin)->get('/promo-videos')->assertNotFound();
    }

    /** An admin's chunked upload, as the page's uploader sends it. */
    private function adminSend(\App\Models\User $user, int $id, string $kind, string $bytes, string $ext, int $chunk = 700): \Illuminate\Testing\TestResponse
    {
        $up = bin2hex(random_bytes(16));
        $r = null;
        for ($off = 0; $off < strlen($bytes); $off += $chunk) {
            $r = $this->asAdmin($user)->call('POST', "/promo-videos/{$id}/upload/{$kind}?upload={$up}&offset={$off}&size=".strlen($bytes).'&sha1='.sha1($bytes)."&ext={$ext}",
                [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $off, $chunk));
        }

        return $r;
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(54, 96);
        ob_start();
        imagejpeg($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    public function test_an_admin_uploads_a_film_its_poster_and_captions_and_publishes_with_no_studio(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $this->asAdmin($admin)->post('/promo-videos', ['app' => 'estate-ledger', 'slug' => 'tour', 'title' => 'The tour', 'part' => 1])->assertRedirect();
        $v = PromoVideo::query()->where('app', 'estate-ledger')->where('slug', 'tour')->firstOrFail();
        $this->asAdmin($admin)->get("/promo-videos/{$v->id}")->assertOk()->assertSee('Upload straight from this computer')->assertDontSee('Queue a render');

        // A film with its index at the end (as many editors export it) arrives moved to the front, its length read from it.
        $film = (string) file_get_contents(base_path('tests/fixtures/promo-moov-at-end.mp4'));
        $this->assertFalse(\App\Services\PromoVideos\Mp4::inspect(base_path('tests/fixtures/promo-moov-at-end.mp4'))['faststart']);
        $this->adminSend($admin, $v->id, 'video', $film, 'mp4')->assertOk()->assertJson(['done' => true]);
        $this->adminSend($admin, $v->id, 'poster', $this->jpeg(), 'jpg')->assertOk()->assertJson(['done' => true]);
        $this->adminSend($admin, $v->id, 'captions', "not captions\n", 'vtt')->assertStatus(422);
        $this->adminSend($admin, $v->id, 'captions', "WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.000\nA short tour.\n", 'vtt')->assertOk();
        $v->refresh();
        $stored = Storage::disk('admin')->path($v->video_path);
        $facts = \App\Services\PromoVideos\Mp4::inspect($stored);
        $this->assertTrue($facts['faststart']);
        $this->assertSame(strlen($film), filesize($stored), 'only rearranged, nothing lost');
        $this->assertSame([1.0, 64, 112], [$v->duration, $v->width, $v->height]);
        $this->assertSame('ready', $v->state());

        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/publish")->assertRedirect();
        $this->assertSame('live', $v->fresh()->state());
        $this->getJson('/api/v1/videos?app=estate-ledger')->assertOk()->assertJsonPath('data.0.slug', 'tour')
            ->assertJsonPath('data.0.captions_url', fn ($u) => str_ends_with((string) $u, '.vtt') && str_contains((string) $u, '/storage/promo-videos/estate-ledger/tour-captions-'));
        $this->get('/api/v1/videos/estate-ledger/tour/captions.vtt')->assertOk()->assertSee('A short tour.');

        // A new poster waits until it is published; a film taken down can be published again.
        $this->adminSend($admin, $v->id, 'poster', $this->jpeg().'x', 'jpg')->assertOk();
        $this->assertSame('ready', $v->fresh()->state());
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/unpublish");
        $this->assertTrue($v->fresh()->hasUnpublishedRender());
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/publish");
        $this->assertSame('live', $v->fresh()->state());

        // Shop users reach none of it.
        $owner = $this->makeTenant('company')['user'];
        $this->asAdmin($owner)->post('/promo-videos', ['app' => 'shelfie', 'slug' => 'x1', 'title' => 'X'])->assertForbidden();
        $this->asAdmin($owner)->call('POST', "/promo-videos/{$v->id}/upload/poster?upload=".str_repeat('a', 32).'&offset=0&size=3&sha1='.sha1('abc').'&ext=jpg', [], [], [], [], 'abc')->assertForbidden();
        $this->asAdmin($owner)->post("/promo-videos/{$v->id}/feature", ['on' => 1])->assertForbidden();
        $this->asAdmin($owner)->post("/promo-videos/{$v->id}/details", ['title' => 'Hacked', 'app' => 'shelfie'])->assertForbidden();
        $this->assertSame('The tour', $v->fresh()->title);
    }

    public function test_the_admin_sets_title_part_app_and_one_featured_film_and_a_studio_push_keeps_them(): void
    {
        $this->push()->assertOk();
        $admin = $this->makeTenant('admin')['user'];
        $v = PromoVideo::query()->where('slug', 'get-started')->firstOrFail();
        $other = PromoVideo::query()->create(['app' => 'pledge-and-budget', 'slug' => 'the-question', 'title' => 'The Question', 'script' => [], 'featured' => true]);

        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/details", ['title' => 'Start in minutes', 'tagline' => 'Free for 14 days.', 'app' => 'pledge-and-budget',
            'part' => 6, 'parts' => 7, 'sort' => 3, 'featured' => 1])->assertRedirect();
        $v->refresh();
        $this->assertSame(['Start in minutes', 6, 3, true], [$v->title, $v->part, $v->sort, $v->featured]);
        $this->assertFalse($other->fresh()->featured, 'one featured film per app');

        $this->push(['base_rev' => 1])->assertOk();
        $v->refresh();
        $this->assertSame(['Start in minutes', 'Free for 14 days.', 6], [$v->title, $v->tagline, $v->part], 'the studio never undoes an admin edit');

        // Moving a film to an app that already has that address is refused.
        PromoVideo::query()->create(['app' => 'shelfie', 'slug' => 'get-started', 'title' => 'Shelfie start', 'script' => []]);
        $this->asAdmin($admin)->post("/promo-videos/{$v->id}/details", ['title' => 'Start in minutes', 'app' => 'shelfie'])->assertRedirect();
        $this->assertSame('pledge-and-budget', $v->fresh()->app);

        $this->asAdmin($admin)->post("/promo-videos/{$other->id}/feature", ['on' => 1])->assertRedirect();
        $this->assertSame([true, false], [$other->fresh()->featured, $v->fresh()->featured]);
    }

    public function test_an_exported_kit_is_imported_and_published_with_no_ffmpeg(): void
    {
        $kit = sys_get_temp_dir().'/promo-kit-'.bin2hex(random_bytes(4));
        mkdir($kit.'/the-question', 0777, true);
        copy(base_path('tests/fixtures/promo-moov-at-end.mp4'), $kit.'/the-question/video.mp4');
        file_put_contents($kit.'/the-question/poster.jpg', $this->jpeg());
        file_put_contents($kit.'/the-question/captions.vtt', "WEBVTT\n\n1\n00:00:00.600 --> 00:00:01.000\nBanange!\n");
        file_put_contents($kit.'/the-question/meta.json', json_encode(['slug' => 'the-question', 'title' => 'The Question', 'tagline' => 'Know what you have.',
            'series' => ['key' => 'how-it-works', 'title' => 'How it works', 'part' => 1, 'total' => 7], 'duration' => 1.0, 'width' => 64, 'height' => 112,
            'script' => $this->script(), 'transcript' => [['scene' => 'cold', 'start' => 0.6, 'dur' => 0.4, 'speaker' => 'chairman', 'text' => 'Banange!']]]));
        try {
            $this->artisan('promo-videos:import', ['dir' => $kit, '--publish' => true, '--feature' => 'the-question'])->assertSuccessful();
            $v = PromoVideo::query()->where('app', 'pledge-and-budget')->where('slug', 'the-question')->firstOrFail();
            $this->assertSame(['live', 1, 7, true], [$v->state(), $v->part, $v->parts, $v->featured]);
            $this->assertTrue(\App\Services\PromoVideos\Mp4::inspect(Storage::disk('admin')->path($v->live_video_path))['faststart']);
            $this->assertNotNull($v->live_captions_path);
            $this->assertStringContainsString('Banange!', Storage::disk('admin')->get($v->live_captions_path));
            $this->assertSame(sha1_file(base_path('tests/fixtures/promo-moov-at-end.mp4')), sha1_file($kit.'/the-question/video.mp4'), 'the kit is left as it was');

            // Again, after an admin renamed it: nothing is copied twice and the admin's title stays.
            $v->update(['title' => 'The big question']);
            $this->artisan('promo-videos:import', ['dir' => $kit, '--publish' => true])->assertSuccessful();
            $this->assertSame('The big question', $v->fresh()->title);
            $this->assertSame(1, PromoVideo::query()->where('slug', 'the-question')->count());
        } finally {
            array_map('unlink', glob($kit.'/the-question/*'));
            rmdir($kit.'/the-question');
            rmdir($kit);
        }
    }
}
