<?php

namespace App\Console\Commands;

use App\Services\Onboarding\PublicDemo;
use Illuminate\Console\Command;

/** Hourly: repair the public demo shop and bring its trading up to now; rebuild it every demo.rebuild_hours. */
class PublicDemoCommand extends Command
{
    protected $signature = 'demo:public {--rebuild : Build a fresh demo shop now}';

    protected $description = 'Look after the public demo shop: heal it hourly, rebuild it every demo.rebuild_hours';

    public function handle(PublicDemo $demo): int
    {
        $result = $demo->run((bool) $this->option('rebuild'));
        $this->info(match ($result['action']) {
            'off' => 'The public demo is switched off (DEMO_PUBLIC).',
            'busy' => 'Another run is looking after the demo right now.',
            'healed' => 'Healed shop #'.$result['company_id'].': '.($result['repairs'] ? implode('; ', $result['repairs']) : 'nothing to repair').", {$result['sales']} sale(s) added.",
            default => ucfirst($result['action']).' shop #'.$result['company_id']." in {$result['seconds']}s.".($result['skipped'] ? ' Skipped: '.count($result['skipped']) : ''),
        });
        foreach (array_slice($result['skipped'] ?? [], 0, 15) as $why) {
            $this->line("  - {$why}");
        }

        return self::SUCCESS;
    }
}
