<?php

namespace App\Console\Commands;

use App\Services\Unity\Demo\UnityDemo;
use Illuminate\Console\Command;

/** Hourly: put the demo association's sign-ins back and refresh its standing; rebuild it every unity.demo.rebuild_hours (UnityDesk). */
class UnityDemoCommand extends Command
{
    protected $signature = 'unity:demo {--rebuild : Build the demo association afresh now}';

    protected $description = 'Look after the UnityDesk demo association: heal its sign-ins hourly, rebuild it every unity.demo.rebuild_hours';

    public function handle(UnityDemo $demo): int
    {
        $r = $demo->run((bool) $this->option('rebuild'));
        match ($r['action']) {
            'off' => $this->info('The UnityDesk demo is switched off (DEMO_UNITY, or not the SaaS edition).'),
            'busy' => $this->info('Another run is looking after the UnityDesk demo right now.'),
            'healed' => $this->info('Association #'.$r['company_id'].': healed, '.($r['repairs'] ? implode('; ', $r['repairs']) : 'nothing to repair').'.'),
            default => $this->info('Association #'.$r['company_id'].": {$r['action']} with {$r['members']} members in {$r['seconds']}s."),
        };

        return self::SUCCESS;
    }
}
