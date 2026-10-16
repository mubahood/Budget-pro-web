<?php

namespace Tests\Feature\Standalone;

use App\Jobs\BuildStandaloneRelease;
use App\Models\StandaloneRelease;
use App\Services\Releases\ReleaseDesk;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Platform › Standalone releases (docs/STANDALONE_EDITION_PLAN.md §10): platform admins only, a 404
 * on a standalone copy, Stage queues a build on the release-builds connection, the actions keep to
 * their states, and a build whose worker died is failed by the stale check.
 */
class ReleaseScreensTest extends AdminTestCase
{
    use RunsAsStandalone;

    private function release(string $status, array $attributes = []): StandaloneRelease
    {
        return StandaloneRelease::create($attributes + ['version' => '9.'.random_int(0, 999).'.'.random_int(0, 99999), 'status' => $status]);
    }

    public function test_only_platform_admins_reach_the_screens_and_a_standalone_copy_has_none(): void
    {
        $admin = $this->makeTenant('admin')['user'];
        $owner = $this->makeTenant('company')['user'];
        $staged = $this->release(StandaloneRelease::STAGED);

        $this->asAdmin($admin)->get('/standalone-releases')->assertOk()->assertSee('Stage a new version')->assertSee('Ready to stage?')->assertSee($staged->version);
        $this->asAdmin($owner)->get('/standalone-releases')->assertForbidden();
        $this->asAdmin($owner)->post('/standalone-releases', ['version' => '5.0.0'])->assertForbidden();
        $this->asAdmin($owner)->post("/standalone-releases/{$staged->id}/publish")->assertForbidden();
        $this->asAdmin($owner)->get("/standalone-releases/{$staged->id}/download")->assertForbidden();

        $this->asStandalone();
        $this->asAdmin($admin)->get('/standalone-releases')->assertNotFound();
        $this->asAdmin($admin)->post("/standalone-releases/{$staged->id}/publish")->assertNotFound();
        $this->asAdmin($admin)->get("/standalone-releases/{$staged->id}/download")->assertNotFound();
    }

    public function test_stage_queues_one_build_on_the_release_connection(): void
    {
        Queue::fake();
        $this->app->bind(ReleaseDesk::class, fn ($app) => new class($app->make(\App\Services\Releases\GitArchiveStager::class), $app->make(\App\Services\Licensing\Signer::class)) extends ReleaseDesk
        {
            public function readiness(): array
            {
                return ['Everything' => ['ok' => true, 'detail' => 'ready']];
            }
        });
        $admin = $this->makeTenant('admin')['user'];

        $this->asAdmin($admin)->post('/standalone-releases', ['version' => '7.1.0', 'notes' => '- A change'])->assertRedirect();
        $release = StandaloneRelease::query()->where('version', '7.1.0')->sole();
        $this->assertSame([StandaloneRelease::QUEUED, $admin->id, '- A change'], [$release->status, $release->built_by, $release->notes]);
        Queue::assertPushedOn('releases', BuildStandaloneRelease::class, fn ($job) => $job->releaseId === $release->id && $job->connection === 'release-builds');

        $this->asAdmin($admin)->post('/standalone-releases', ['version' => '7.1.0'])->assertRedirect();
        $this->assertSame(1, StandaloneRelease::query()->where('version', '7.1.0')->count(), 'a version is built once');
        Queue::assertPushed(BuildStandaloneRelease::class, 1);
        $this->assertSame('7.1.1', app(ReleaseDesk::class)->nextVersion());
    }

    public function test_the_actions_keep_to_their_states_and_the_download_is_streamed(): void
    {
        Queue::fake();
        $admin = $this->makeTenant('admin')['user'];
        $unproven = $this->release(StandaloneRelease::STAGED);
        $published = $this->release(StandaloneRelease::PUBLISHED, ['published_at' => now()]);
        $failed = $this->release(StandaloneRelease::FAILED, ['failure' => 'boom']);

        $this->asAdmin($admin)->post("/standalone-releases/{$unproven->id}/publish")->assertRedirect();
        $this->assertSame(StandaloneRelease::STAGED, $unproven->fresh()->status, 'no proof, no publish');
        $this->asAdmin($admin)->post("/standalone-releases/{$failed->id}/withdraw")->assertRedirect();
        $this->assertSame(StandaloneRelease::FAILED, $failed->fresh()->status);

        $this->asAdmin($admin)->post("/standalone-releases/{$published->id}/withdraw")->assertRedirect();
        $this->assertSame(StandaloneRelease::YANKED, $published->fresh()->status);
        $this->assertFalse(StandaloneRelease::available()->whereKey($published->id)->exists());

        $this->asAdmin($admin)->post("/standalone-releases/{$failed->id}/retry")->assertRedirect();
        $this->assertSame([StandaloneRelease::QUEUED, null], [$failed->fresh()->status, $failed->fresh()->failure]);
        Queue::assertPushed(BuildStandaloneRelease::class, 1);

        $file = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)).'.zip');
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, 'PK fixture');
        try {
            $ready = $this->release(StandaloneRelease::STAGED, ['archive_path' => $file, 'sha256' => hash_file('sha256', $file)]);
            $response = $this->asAdmin($admin)->get("/standalone-releases/{$ready->id}/download")->assertOk();
            $this->assertStringContainsString('budget-pro-standalone-'.$ready->version.'.zip', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        } finally {
            @unlink($file);
        }
        $this->asAdmin($admin)->get("/standalone-releases/{$unproven->id}/download")->assertNotFound();
    }

    public function test_a_build_whose_worker_died_is_failed_by_the_saas_stale_check(): void
    {
        $dead = $this->release(StandaloneRelease::BUILDING);
        StandaloneRelease::query()->whereKey($dead->id)->update(['updated_at' => now()->subMinutes(41)]);
        $alive = $this->release(StandaloneRelease::BUILDING);

        $this->assertSame(1, StandaloneRelease::failStale());
        $this->assertSame([StandaloneRelease::FAILED, StandaloneRelease::BUILDING], [$dead->fresh()->status, $alive->fresh()->status]);
        $this->assertStringContainsString('releases:work', (string) $dead->fresh()->failure);

        $this->app->forgetInstance(Schedule::class);
        $names = array_map(fn ($e) => (string) ($e->description ?? $e->command), app(Schedule::class)->events());
        $this->assertContains('standalone-release-stale-check', $names);
        $this->assertEmpty(array_filter($names, fn ($n) => str_contains($n, 'release-builds') || str_contains($n, 'releases:work')), 'the worker has its own cron line');

        $this->asStandalone();
        $this->app->forgetInstance(Schedule::class);
        $this->assertNotContains('standalone-release-stale-check', array_map(fn ($e) => (string) ($e->description ?? $e->command), app(Schedule::class)->events()));
    }
}
