<?php

namespace App\Console\Commands;

use App\Services\Kin\Demo\KinDemo;
use Illuminate\Console\Command;

/** Hourly: put the demo family's sign-ins back; rebuild the family every kin.demo.rebuild_hours (My Family Roots 360). */
class KinDemoCommand extends Command
{
    protected $signature = 'kin:demo {--rebuild : Build the demo family afresh now}';

    protected $description = 'Look after the My Family Roots 360 demo family: heal its sign-ins hourly, rebuild it every kin.demo.rebuild_hours';

    public function handle(KinDemo $demo): int
    {
        $r = $demo->run((bool) $this->option('rebuild'));
        match ($r['action']) {
            'off' => $this->info('The Family Roots demo is switched off (DEMO_KIN, or not the SaaS edition).'),
            'busy' => $this->info('Another run is looking after the Family Roots demo right now.'),
            'healed' => $this->info('Family #'.$r['company_id'].': healed, '.($r['repairs'] ? implode('; ', $r['repairs']) : 'nothing to repair').'.'),
            default => $this->info('Family #'.$r['company_id'].": {$r['action']} with {$r['people']} people in {$r['seconds']}s."),
        };

        return self::SUCCESS;
    }
}
