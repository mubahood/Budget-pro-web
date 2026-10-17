<?php

namespace App\Services\Releases;

use App\Jobs\BuildStandaloneRelease;
use App\Models\StandaloneRelease;
use App\Services\Licensing\Signer;
use App\Support\Licensing\Verifier;
use Symfony\Component\Process\Process;

/**
 * What Platform › Standalone releases does (docs/STANDALONE_EDITION_PLAN.md §10): the readiness list,
 * the prefilled version and notes, and the four actions. Stage queues a build; Publish only a
 * staged, proven release whose archive still matches its hash and verifies with the CURRENT key and
 * records the commits it was built from (True-Doctor lesson 6); Retry a failed one; Withdraw a
 * published one.
 */
class ReleaseDesk
{
    public function __construct(private readonly GitArchiveStager $git, private readonly Signer $signer)
    {
    }

    /** @return array<string,array{ok:bool,detail:string}> */
    public function readiness(): array
    {
        $checks = [];
        try {
            $probe = ['probe' => bin2hex(random_bytes(8))];
            $signed = $this->signer->sign($probe);
            $works = (new Verifier([$this->signer->keyId() => $this->signer->publicKey()]))->verifies($probe, $signed['signature'], $signed['key_id']);
            $checks['Signing key set and working'] = ['ok' => $works, 'detail' => 'key '.$this->signer->keyId()];
        } catch (\Throwable $e) {
            $checks['Signing key set and working'] = ['ok' => false, 'detail' => $e->getMessage()];
        }
        try {
            [$db, $dedicated] = app(CleanInstallProof::class)->settings();
            $checks['Proof database configured'] = ['ok' => true, 'detail' => $dedicated ? $db['user'].'@'.$db['host'].' / '.$db['database'] : 'a throwaway database on '.$db['host']];
        } catch (\Throwable $e) {
            $checks['Proof database configured'] = ['ok' => false, 'detail' => $e->getMessage()];
        }
        foreach (['kernel' => 'budget-pro', 'web' => 'budget-pro-new'] as $side => $label) {
            $path = (string) config('edition.release_sources.'.$side);
            if (! GitArchiveStager::isCheckout($path)) {
                $checks["{$label} is a git checkout"] = ['ok' => false, 'detail' => $path.' is not a git checkout'];

                continue;
            }
            $checks["{$label} is a git checkout"] = ['ok' => true, 'detail' => $path.' at '.substr(rescue(fn () => $this->git->commit($path), '?', false), 0, 7)];
            $changes = rescue(fn () => $this->git->changes($path), ['(git status failed)'], false);
            $checks["{$label} tracked files clean"] = ['ok' => $changes === [], 'detail' => $changes === [] ? 'nothing uncommitted'
                : count($changes).' changed: '.implode(', ', array_slice($changes, 0, 5))];
        }

        return $checks;
    }

    public function ready(?array $readiness = null): bool
    {
        return ! in_array(false, array_column($readiness ?? $this->readiness(), 'ok'), true);
    }

    public function nextVersion(): string
    {
        $last = StandaloneRelease::query()->orderByDesc('id')->value('version');

        return is_string($last) && preg_match('/^(\d+)\.(\d+)\.(\d+)/', $last, $m) ? $m[1].'.'.$m[2].'.'.((int) $m[3] + 1) : '1.0.0';
    }

    /** Commit subjects since the last release that was built, from both repositories. */
    public function suggestedNotes(): string
    {
        $last = StandaloneRelease::query()->whereNotNull('commit_kernel')->whereIn('status', [StandaloneRelease::STAGED, StandaloneRelease::PUBLISHED, StandaloneRelease::YANKED])
            ->orderByDesc('id')->first();
        $parts = [];
        foreach (['kernel' => 'Kernel (budget-pro)', 'web' => 'Web app (budget-pro-new)'] as $side => $heading) {
            $path = (string) config('edition.release_sources.'.$side);
            $since = $side === 'web' ? $last?->commit_web : $last?->commit_kernel;
            if (! GitArchiveStager::isCheckout($path)) {
                continue;
            }
            $range = is_string($since) && preg_match('/^[a-f0-9]{40}$/', $since) ? $since.'..HEAD' : 'HEAD';
            $log = new Process(['git', 'log', '--no-merges', '-40', '--pretty=format:- %s', $range], $path);
            $log->setTimeout(15);
            $log->run();
            $lines = $log->isSuccessful() ? trim($log->getOutput()) : '';
            $parts[] = $heading.":\n".($lines !== '' ? $lines : '- (no changes)');
        }

        return implode("\n\n", $parts);
    }

    public function stage(string $version, string $notes, ?int $by): StandaloneRelease
    {
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/', $version)) {
            throw new \DomainException('Give a version such as 1.0.4.');
        }
        if (StandaloneRelease::query()->where('version', $version)->exists()) {
            throw new \DomainException("Version {$version} exists already.");
        }
        if (! $this->ready()) {
            throw new \DomainException('The readiness list is not all green yet.');
        }
        $release = StandaloneRelease::create(['version' => $version, 'notes' => trim($notes), 'status' => StandaloneRelease::QUEUED,
            'progress' => ['step' => 'Waiting for the release worker', 'percent' => 0], 'built_by' => $by]);
        BuildStandaloneRelease::dispatch($release->id);

        return $release;
    }

    public function publish(StandaloneRelease $release): void
    {
        if (! $release->isPublishable()) {
            throw new \DomainException('Only a staged release that passed its clean install, with its archive intact, can be published.');
        }
        $manifest = (new StandaloneArchive)->verify($release->archive_path, new Verifier([$this->signer->keyId() => $this->signer->publicKey()]));
        if (($manifest['version'] ?? null) !== $release->version
            || ($manifest['commits'] ?? null) !== ['kernel' => $release->commit_kernel, 'web' => $release->commit_web]) {
            throw new \DomainException('The archive does not carry the version and commits this release was built from.');
        }
        $release->forceFill(['status' => StandaloneRelease::PUBLISHED, 'published_at' => now(),
            'progress' => ['step' => 'Published', 'percent' => 100]])->save();
    }

    public function retry(StandaloneRelease $release): void
    {
        if ($release->status !== StandaloneRelease::FAILED) {
            throw new \DomainException('Only a failed build can be retried.');
        }
        if (is_string($release->archive_path)) {
            @unlink($release->archive_path);
        }
        $release->forceFill(['status' => StandaloneRelease::QUEUED, 'failure' => null, 'archive_path' => null, 'sha256' => null, 'size' => null,
            'file_count' => null, 'staged_at' => null, 'proved_at' => null, 'progress' => ['step' => 'Waiting for the release worker', 'percent' => 0]])->save();
        BuildStandaloneRelease::dispatch($release->id);
    }

    public function withdraw(StandaloneRelease $release): void
    {
        if ($release->status !== StandaloneRelease::PUBLISHED) {
            throw new \DomainException('Only a published release can be withdrawn.');
        }
        $release->forceFill(['status' => StandaloneRelease::YANKED, 'progress' => ['step' => 'Withdrawn from sale and updates', 'percent' => 100]])->save();
    }
}
