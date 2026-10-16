<?php

namespace Tests\Feature\Standalone;

use App\Jobs\BuildStandaloneRelease;
use App\Models\StandaloneRelease;
use App\Services\Licensing\Signer;
use App\Services\Releases\CleanInstallProof;
use App\Services\Releases\GitArchiveStager;
use App\Services\Releases\StandaloneArchive;
use App\Services\Releases\WorkingCopyStager;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * The clean-install proof (docs/STANDALONE_EDITION_PLAN.md §10 step 6). It never touches the
 * application's own database, and (slow group) a queued build ends "staged" only after its ZIP
 * installed for real, in its own process, on a throwaway MySQL database this test creates and drops.
 * No transaction wrapper: CREATE/DROP DATABASE would commit it.
 */
class ReleaseProofTest extends TestCase
{
    public function test_the_proof_needs_its_own_database_and_never_the_applications(): void
    {
        $proof = new CleanInstallProof;
        config(['edition.test_database' => ['host' => '', 'user' => '']]);
        $this->assertRefused(fn () => $proof->settings(), 'Configure the proof database');

        $own = (string) config('database.connections.mysql.database');
        config(['edition.test_database' => ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'proof', 'database' => strtoupper($own)]]);
        $this->assertRefused(fn () => $proof->settings(), 'must not be the application');
        $this->assertRefused(fn () => $proof->run('/nowhere.zip', new Signer), 'must not be the application');

        config(['edition.test_database' => ['host' => '127.0.0.1', 'user' => 'proof', 'database' => 'bp_proof; DROP DATABASE x']]);
        $this->assertRefused(fn () => $proof->settings(), 'letters, digits and underscores');
        config(['edition.test_database' => ['host' => '127.0.0.1', 'user' => 'proof', 'database' => '']]);
        $this->assertMatchesRegularExpression('/^bp_release_[a-f0-9]{12}$/', $proof->settings()[0]['database'], 'no name: a throwaway database');
    }

    /**
     * @group slow
     *
     * Packs the local working copies (the real stager is GitArchiveStager, covered by ReleaseBuildTest
     * with small repositories) and runs the whole job: build, then the packaged installer in a subprocess.
     * About 75 s, so phpunit.xml leaves it out; run it with
     * vendor/bin/phpunit --group slow --exclude-group none tests/Feature/Standalone/ReleaseProofTest.php
     */
    public function test_a_queued_build_is_staged_only_after_its_zip_installs_on_an_empty_database(): void
    {
        $keys = json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/test-keypair.json')), true);
        $app = config('database.connections.mysql');
        $database = 'bp_proof_'.bin2hex(random_bytes(4));
        $pdo = new PDO($app['unix_socket'] ? "mysql:unix_socket={$app['unix_socket']}" : "mysql:host={$app['host']};port={$app['port']}", $app['username'], $app['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config([
            'edition.name' => 'saas', 'edition.signing_key_id' => $keys['key_id'], 'edition.signing_secret' => $keys['secret'], 'edition.public_keys' => [],
            'edition.test_database' => ['host' => $app['host'], 'port' => (string) $app['port'], 'user' => $app['username'], 'password' => $app['password'],
                'database' => $database, 'socket' => (string) $app['unix_socket']],
        ]);
        $this->app->instance(GitArchiveStager::class, new class extends GitArchiveStager
        {
            public function build(string $version, string $destination, Signer $signer, ?callable $progress = null, ?string $kernel = null, ?string $web = null): array
            {
                $stage = storage_path('framework/testing/proof-stage-'.bin2hex(random_bytes(4)));
                try {
                    $k = (new WorkingCopyStager)->stage(base_path(), $stage.'/kernel', false);
                    $w = (new WorkingCopyStager)->stage((string) config('edition.release_sources.web'), $stage.'/web', true);
                    $commits = ['kernel' => $k['commit'], 'web' => $w['commit']];
                    File::ensureDirectoryExists(dirname($destination));

                    return (new StandaloneArchive)->build($k['path'], $w['path'], null, $destination, $version, $commits, $signer)
                        + ['commits' => $commits, 'vendor' => 'the working copy\'s'];
                } finally {
                    File::deleteDirectory($stage);
                }
            }
        });
        $release = StandaloneRelease::create(['version' => '0.0.0-proof.'.bin2hex(random_bytes(3)), 'status' => StandaloneRelease::QUEUED]);
        try {
            $this->app->call([new BuildStandaloneRelease($release->id), 'handle']);

            $release->refresh();
            $this->assertSame(StandaloneRelease::STAGED, $release->status, (string) $release->failure);
            $this->assertNotNull($release->proved_at);
            $this->assertSame(100, $release->progress['percent']);
            $this->assertStringContainsString('shop:check passed', $release->progress['step']);
            $this->assertSame(hash_file('sha256', $release->archive_path), $release->sha256);
            $this->assertGreaterThan(1000, $release->file_count);
            $this->assertTrue($release->isPublishable());
            $left = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$database}'")->fetchColumn();
            $this->assertSame(0, (int) $left, 'the proof database is emptied afterwards');
            $this->assertSame([], glob(storage_path('app/private/release-proofs/*')) ?: [], 'the extraction is removed');
        } finally {
            @unlink((string) $release->fresh()?->archive_path);
            $release->delete();
            $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }

    private function assertRefused(callable $action, string $because): void
    {
        try {
            $action();
            $this->fail("Expected a refusal mentioning \"{$because}\".");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}
