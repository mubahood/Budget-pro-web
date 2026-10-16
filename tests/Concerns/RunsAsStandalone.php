<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Run a test as an installed standalone copy (docs/STANDALONE_EDITION_PLAN.md §18): config
 * edition.name = standalone, and a temporary install marker {"edition":"standalone"} at
 * config edition.installed_path. The suite itself runs as SaaS (phpunit.xml APP_EDITION=saas).
 *
 * Create the shop first (as SaaS), then switch: on standalone a second company is refused.
 */
trait RunsAsStandalone
{
    private ?string $installMarker = null;

    /** Become an installed standalone copy (or, with $installed false, one the installer has not finished). */
    protected function asStandalone(bool $installed = true): static
    {
        config(['edition.name' => 'standalone']);
        $this->useInstallMarker($installed ? ['edition' => 'standalone'] : null);

        return $this;
    }

    /** Back to the SaaS, with no marker (our servers have none). */
    protected function asSaas(): static
    {
        config(['edition.name' => 'saas']);
        $this->useInstallMarker(null);

        return $this;
    }

    /** Point edition.installed_path at a temporary marker holding $data, or at no file at all (null). */
    protected function useInstallMarker(?array $data): void
    {
        $this->installMarker ??= sys_get_temp_dir().'/bp-installed-'.uniqid('', true).'.json';
        @unlink($this->installMarker);
        if ($data !== null) {
            file_put_contents($this->installMarker, json_encode($data));
        }
        config(['edition.installed_path' => $this->installMarker]);
    }

    /**
     * An empty companies table for this test only (the test's transaction puts the rows back): the
     * state a fresh standalone install starts from. Foreign keys are off just for the delete.
     */
    protected function withNoCompanies(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('companies')->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function tearDownRunsAsStandalone(): void
    {
        if ($this->installMarker !== null) {
            @unlink($this->installMarker);
        }
    }
}
