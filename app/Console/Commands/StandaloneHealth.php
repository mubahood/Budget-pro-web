<?php

namespace App\Console\Commands;

use App\Services\Install\ArtisanRunner;
use App\Services\Install\StandaloneUpdater;
use App\Services\Licensing\StandalonePlan;
use App\Support\Edition;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * Can this standalone copy serve its shop right now? (docs/STANDALONE_EDITION_PLAN.md §12.2, §8.3.)
 * The updater runs it on the new code, in a fresh process, before it brings the apps back; a
 * technician can run it any time. Exit code 0 means yes.
 *
 * The kernel's checks (database, migrations, one business on the Standalone plan, both .env files
 * sharing APP_KEY and SAAS_DATA_KEY, writable caches) plus the web app's own read-only `shop:check`,
 * run in its folder.
 */
class StandaloneHealth extends Command
{
    protected $signature = 'standalone:health {--web= : The web app\'s folder (default: BUDGETPRO_WEB_PATH, else beside this one)}';

    protected $description = 'Check that a standalone installation can serve requests (both apps)';

    public function handle(ArtisanRunner $artisan): int
    {
        /** @var Migrator $migrator */
        $migrator = $this->laravel->make('migrator'); // a deferred binding: by name, not by class
        if (! Edition::isStandalone()) {
            $this->error('standalone:health is for standalone installations; the SaaS uses shop:check and ops.');

            return self::FAILURE;
        }
        $web = rtrim((string) ($this->option('web') ?: StandaloneUpdater::webAppPath()), '/');
        $problems = [];

        try {
            DB::connection()->getPdo();
            $reachable = true;
        } catch (\Throwable) {
            $reachable = false;
            $problems[] = 'The database cannot be reached.';
        }

        if ($reachable) {
            if (! $migrator->repositoryExists()) {
                $problems[] = 'The database has never been migrated.';
            } else {
                $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
                $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
                if ($pending !== []) {
                    $problems[] = count($pending).' database migration(s) have not run, starting with '.reset($pending).'.';
                }
            }
            $companies = DB::table('companies')->pluck('id');
            if ($companies->count() !== 1) {
                $problems[] = 'A standalone installation must hold exactly one business; this one holds '.$companies->count().'.';
            } elseif (! DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.company_id', $companies->first())->where('plans.slug', StandalonePlan::SLUG)
                ->whereIn('subscriptions.status', ['active', 'trialing'])->exists()) {
                $problems[] = 'The business has no active Standalone plan subscription.';
            }
        }

        $problems = [...$problems, ...$this->sharedKeys(base_path('.env'), $web.'/.env')];

        foreach ([base_path(), $web] as $root) {
            foreach (['storage/framework/cache', 'storage/framework/views', 'storage/framework/sessions', 'bootstrap/cache'] as $folder) {
                if (! is_dir($root.'/'.$folder) || ! is_writable($root.'/'.$folder)) {
                    $problems[] = basename($root)."/{$folder} is missing or not writable.";
                }
            }
        }

        if (! is_file($web.'/artisan')) {
            $problems[] = "The web app was not found at {$web}.";
        } else {
            try {
                $artisan->fresh($web, ['shop:check']);
            } catch (\Throwable $e) {
                $problems[] = 'The web app\'s shop:check failed: '.$e->getMessage();
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }
        $this->info('Healthy: Budget Pro '.Edition::version().' (standalone), both apps.');

        return self::SUCCESS;
    }

    /** Both apps must read each other's encrypted values: one APP_KEY and one SAAS_DATA_KEY (§8.3). @return list<string> */
    private function sharedKeys(string $kernelEnv, string $webEnv): array
    {
        $read = static fn (string $file): ?array => is_file($file) ? rescue(fn () => Dotenv::parse((string) file_get_contents($file)), null, false) : null;
        [$kernel, $web] = [$read($kernelEnv), $read($webEnv)];
        if ($kernel === null || $web === null) {
            return [($kernel === null ? 'budget_pro' : 'budget_pro_web').'/.env is missing or unreadable.'];
        }
        $problems = [];
        foreach (['APP_KEY', 'SAAS_DATA_KEY'] as $key) {
            if (($kernel[$key] ?? '') === '' || ($kernel[$key] ?? null) !== ($web[$key] ?? null)) {
                $problems[] = "Both .env files must carry the same, non-empty {$key}.";
            }
        }

        return $problems;
    }
}
