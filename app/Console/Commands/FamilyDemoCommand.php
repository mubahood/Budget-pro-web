<?php

namespace App\Console\Commands;

use App\Services\Onboarding\FamilyDemoBuilder;
use Illuminate\Console\Command;

/**
 * Local only: (re)build the Budget Pro family's demo accounts for the web portal and the website's
 * pictures (FamilyDemoBuilder): the Mukono layers farm, the Brian & Grace wedding and Rose's Corner
 * Shop with every module on. Refuses anywhere but a developer's machine.
 */
class FamilyDemoCommand extends Command
{
    protected $signature = 'demo:family
        {--only=* : farm, wedding or all (default: the three)}
        {--remove : delete the accounts instead of building them}';

    protected $description = 'Local only: (re)build the family demo accounts (farm, wedding, all-in-one; *.demo@local.test, password secret123)';

    public function handle(FamilyDemoBuilder $builder): int
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->error('demo:family runs on a developer machine only (APP_ENV is '.app()->environment().').');

            return self::FAILURE;
        }
        $only = array_values(array_filter((array) $this->option('only')));
        if ($this->option('remove')) {
            foreach ($only ?: array_keys(FamilyDemoBuilder::ACCOUNTS) as $key) {
                $this->line(($builder->remove($key) ? 'Removed ' : 'Not there: ').$key);
            }

            return self::SUCCESS;
        }
        $started = microtime(true);
        foreach ($builder->build($only ?: null) as $key => $r) {
            $this->info(sprintf('%-8s company #%d  %s / %s', $key, $r['company_id'], $r['email'], $r['password']));
            foreach ($r['summary'] as $part => $figures) {
                $this->line("  {$part}: ".json_encode($figures));
            }
        }
        if ($builder->skipped) {
            $this->warn(count($builder->skipped).' sample entries skipped: '.implode('; ', array_slice(array_unique($builder->skipped), 0, 5)));
        }
        $this->line(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
