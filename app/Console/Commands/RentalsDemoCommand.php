<?php

namespace App\Console\Commands;

use App\Services\Rentals\Demo\RentalsDemo;
use Illuminate\Console\Command;

/** Hourly: heal Estate Ledger's demo accounts and bring their books up to today; rebuild them every rentals.demo.rebuild_hours. */
class RentalsDemoCommand extends Command
{
    protected $signature = 'rentals:demo {--rebuild : Build the demo accounts afresh now}';

    protected $description = 'Look after Estate Ledger\'s demo accounts: heal them hourly, rebuild them every rentals.demo.rebuild_hours';

    public function handle(RentalsDemo $demo): int
    {
        $result = $demo->run((bool) $this->option('rebuild'));
        if (in_array($result['action'], ['off', 'busy'], true)) {
            $this->info($result['action'] === 'off' ? 'The Estate Ledger demo is switched off (DEMO_RENTALS).' : 'Another run is looking after the Estate Ledger demo right now.');

            return self::SUCCESS;
        }
        foreach (['Agency' => $result, 'Landlord on his own' => $result['solo']] as $what => $r) {
            $this->info($what.' #'.$r['company_id'].': '.($r['action'] === 'healed'
                ? 'healed, '.($r['repairs'] ? implode('; ', $r['repairs']) : 'nothing to repair').'.'
                : "{$r['action']} in {$r['seconds']}s.".($r['skipped'] ? ' Skipped: '.count($r['skipped']) : '')));
            foreach (array_slice($r['skipped'] ?? [], 0, 25) as $why) {
                $this->line("  - {$why}");
            }
        }

        return self::SUCCESS;
    }
}
