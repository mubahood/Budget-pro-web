<?php

namespace App\Console\Commands;

use App\Services\Sacco\Demo\SaccoDemo;
use Illuminate\Console\Command;

/** Hourly: heal the Akiba Ledger demo SACCO and bring its books up to today; rebuild it every demo.sacco.rebuild_hours. */
class SaccoDemoCommand extends Command
{
    protected $signature = 'sacco:demo {--rebuild : Build a fresh demo SACCO now}';

    protected $description = 'Look after the Akiba Ledger demo SACCO: heal it hourly, rebuild it every demo.sacco.rebuild_hours';

    public function handle(SaccoDemo $demo): int
    {
        $result = $demo->run((bool) $this->option('rebuild'));
        $this->info(match ($result['action']) {
            'off' => 'The SACCO demo is switched off (DEMO_SACCO).',
            'busy' => 'Another run is looking after the SACCO demo right now.',
            'healed' => 'Healed SACCO #'.$result['company_id'].': '.($result['repairs'] ? implode('; ', $result['repairs']) : 'nothing to repair').'.',
            default => ucfirst($result['action']).' SACCO #'.$result['company_id']." in {$result['seconds']}s.".($result['skipped'] ? ' Skipped: '.count($result['skipped']) : ''),
        });
        foreach (array_slice($result['skipped'] ?? [], 0, 25) as $why) {
            $this->line("  - {$why}");
        }
        foreach ($result['pending'] ?? [] as $what) {
            $this->line("  pending {$what}");
        }

        return self::SUCCESS;
    }
}
