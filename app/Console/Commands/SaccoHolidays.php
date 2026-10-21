<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoPublicHoliday;
use App\Services\Sacco\Calendar\PublicHolidays;
use Illuminate\Console\Command;

/**
 * The platform admin's holiday calendar (AK-51): list a year, seed it from the data file, or confirm a movable feast
 * on its gazetted day (`--set=2026-03-21:"Eid al-Fitr" --replaces=2026-03-20`).
 */
class SaccoHolidays extends Command
{
    protected $signature = 'sacco:holidays {--country=UG} {--year=} {--seed : add the year\'s missing holidays} {--set= : YYYY-MM-DD:Name} {--replaces= : the date it moves from} {--remove= : YYYY-MM-DD}';

    protected $description = 'List, seed or correct the public holidays SACCO working days skip';

    public function handle(PublicHolidays $holidays): int
    {
        $country = strtoupper((string) $this->option('country'));
        $year = (int) ($this->option('year') ?: now()->year);
        try {
            if ($this->option('seed')) {
                $this->info($holidays->seed($country, $year)." holiday(s) added for {$country} {$year}.");
            }
            if ($set = $this->option('set')) {
                [$date, $name] = array_pad(explode(':', (string) $set, 2), 2, '');
                $row = $holidays->set($country, $date, $name, $this->option('replaces') ?: null);
                $this->info("{$row->date->toDateString()} {$row->name} confirmed.");
                $year = (int) $row->date->year;
            }
            if ($remove = $this->option('remove')) {
                $this->info($holidays->remove($country, (string) $remove) ? "{$remove} removed." : "{$remove} was not a holiday.");
            }
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $rows = SaccoPublicHoliday::query()->where('country', $country)->whereYear('date', $year)->orderBy('date')->get();
        $this->table(['Date', 'Holiday', 'Kind', 'Estimate'], $rows->map(fn ($r) => [$r->date->toDateString(), $r->name, $r->kind, $r->is_estimate ? 'yes' : ''])->all());

        return self::SUCCESS;
    }
}
