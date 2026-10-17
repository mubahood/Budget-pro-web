<?php

namespace App\Console\Commands;

use App\Support\ConfigDoctor;
use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * The settings that must not quietly switch mail, messages, payments or the queue off, checked in one go
 * (App\Support\ConfigDoctor, whose docblock lists every check key). budget-pro-new's deploy script runs it
 * after budget-pro is updated and its config cached; exit 1 when anything fails, which stops the deploy.
 */
class ConfigDoctorCommand extends Command
{
    protected $signature = 'config:doctor {--json : One JSON document instead of a table}';

    protected $description = 'Check the settings that must not quietly switch mail, messages, payments or the queue off';

    public function handle(): int
    {
        $checks = ConfigDoctor::sorted((new ConfigDoctor(ConfigDoctor::KERNEL))->checks());
        $overall = ConfigDoctor::overall($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $overall, 'app' => ConfigDoctor::KERNEL, 'edition' => (string) config('edition.name'),
                'environment' => (string) config('app.env'), 'version' => Edition::version(), 'checks' => $checks,
                'acceptable' => ConfigDoctor::acceptable(), 'never_accepted' => ConfigDoctor::NEVER_ACCEPTED,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Check', 'Status', 'What it found', 'Fix'], array_map(fn ($c) => [
                $c['key'], strtoupper($c['status']).($c['accepted'] ? ' (accepted by the owner)' : ''), $c['message'], $c['fix'],
            ], $checks));
            $counts = array_count_values(array_column($checks, 'status'));
            $this->line(sprintf('Overall: %s (%d to fix, %d to look at, %d fine) on %s, %s.', strtoupper($overall),
                $counts[ConfigDoctor::FAIL] ?? 0, $counts[ConfigDoctor::WARN] ?? 0, $counts[ConfigDoctor::OK] ?? 0, config('edition.name'), config('app.env')));
            $this->line('Check keys: '.implode(', ', ConfigDoctor::KEYS).'.');
            $this->line('To live with a failure for now, add its key to CONFIG_DOCTOR_ACCEPT (comma-separated): it then shows as a warning marked "accepted by the owner". '
                .'Never accepted: '.implode(', ', ConfigDoctor::NEVER_ACCEPTED).'.');
        }

        return $overall === ConfigDoctor::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
