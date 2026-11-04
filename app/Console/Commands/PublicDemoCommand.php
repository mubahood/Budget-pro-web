<?php

namespace App\Console\Commands;

use App\Services\Onboarding\PublicDemo;
use Illuminate\Console\Command;

/** Hourly: repair each of the family's demo accounts and bring it up to today; rebuild each every demo.rebuild_hours. Passwords are never printed. */
class PublicDemoCommand extends Command
{
    protected $signature = 'demo:public {--rebuild : Build fresh accounts now} {--app= : Only this account (budget-pro, shelfie, kuku-tally, pledge-and-budget)}';

    protected $description = 'Look after the family\'s demo accounts: heal them hourly, rebuild them every demo.rebuild_hours';

    public function handle(PublicDemo $demo): int
    {
        $only = $this->option('app') ?: null;
        if ($only !== null && ! PublicDemo::hasAccount($only)) {
            $this->error("No demo account called {$only}.");

            return self::FAILURE;
        }
        $failed = false;
        foreach ($demo->runAll((bool) $this->option('rebuild'), $only) as $account => $result) {
            $started = $result['seconds'] ?? null;
            $this->info("{$account}: ".match ($result['action']) {
                'off' => 'the public demos are switched off (DEMO_PUBLIC).',
                'busy' => 'another run is looking after it right now.',
                'failed' => 'failed: '.$result['error'],
                'healed' => 'healed #'.$result['company_id'].': '.($result['repairs'] ? implode('; ', $result['repairs']) : 'nothing to repair').", {$result['sales']} added today.",
                default => $result['action'].' #'.$result['company_id']." in {$started}s.".($result['skipped'] ? ' Skipped: '.count($result['skipped']) : ''),
            });
            foreach (array_slice($result['skipped'] ?? [], 0, 15) as $why) {
                $this->line("  - {$why}");
            }
            $failed = $failed || $result['action'] === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
