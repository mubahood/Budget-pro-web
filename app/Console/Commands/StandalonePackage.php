<?php

namespace App\Console\Commands;

use App\Services\Licensing\Signer;
use App\Services\Releases\StandaloneArchive;
use App\Services\Releases\WorkingCopyStager;
use App\Support\Edition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * A standalone ZIP from a developer's working copies (docs/STANDALONE_EDITION_PLAN.md §8.1), for
 * drills and tests: both apps as they are on disk now, signed with config edition.signing_*
 * (RELEASE_SIGNING_KEY_ID / RELEASE_SIGNING_SECRET). SaaS only — a buyer's copy holds no secret.
 *
 * Not a release: real releases are built from the deployed commits by the release builder, which
 * uses the same StandaloneArchive::build().
 */
class StandalonePackage extends Command
{
    protected $signature = 'standalone:package
        {--release=0.0.0-dev : The semantic version written into RELEASE.json (not --version: artisan keeps that for itself)}
        {--out= : Where to write the ZIP}
        {--kernel= : The kernel working copy (default: this one)}
        {--web= : The web app working copy (default: config edition.release_sources.web)}';

    protected $description = 'Build a signed standalone ZIP from the local working copies (development only)';

    public function handle(WorkingCopyStager $stager, StandaloneArchive $archive, Signer $signer): int
    {
        if (! Edition::isSaas()) {
            $this->error('standalone:package runs on the SaaS (development) copy only.');

            return self::FAILURE;
        }
        $out = (string) $this->option('out');
        if ($out === '' || ! is_dir(dirname($out))) {
            $this->error('Give --out=<path to the .zip>, in a folder that exists.');

            return self::FAILURE;
        }
        $kernel = (string) ($this->option('kernel') ?: config('edition.release_sources.kernel'));
        $web = (string) ($this->option('web') ?: config('edition.release_sources.web'));

        $stage = storage_path('framework/standalone-stage-'.bin2hex(random_bytes(4)));
        try {
            $this->line('Staging the kernel from '.$kernel.' …');
            $k = $stager->stage($kernel, $stage.'/kernel', false);
            $this->line('Staging the web app from '.$web.' …');
            $w = $stager->stage($web, $stage.'/web', true);
            $this->line(sprintf('Staged %d + %d files. Packing …', $k['files'], $w['files']));

            $built = $archive->build($k['path'], $w['path'], null, $out, (string) $this->option('release'),
                ['kernel' => $k['commit'], 'web' => $w['commit']], $signer);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($stage);
        }

        $this->info(sprintf('%s: %d files, %.1f MB, sha256 %s', $built['path'], $built['count'], $built['bytes'] / 1048576, $built['sha256']));
        $this->line('Signed with key '.$signer->keyId().' (in budget_pro/RELEASE_KEYS.json).');

        return self::SUCCESS;
    }
}
