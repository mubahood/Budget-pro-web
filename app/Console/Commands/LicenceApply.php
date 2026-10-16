<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Licensing\StandalonePlan;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Console\Command;

/**
 * Copy the installed licence's limits onto the "standalone" plan (docs/STANDALONE_EDITION_PLAN.md
 * §7.1). Run whenever the licence changes: install, upload, update. Users, locations and devices
 * come from the licence; products, sales and storage are always unlimited.
 */
class LicenceApply extends Command
{
    protected $signature = 'licence:apply';

    protected $description = 'Apply the installed licence\'s limits to the standalone plan';

    public function handle(StandalonePlan $plans): int
    {
        if (! Edition::isStandalone()) {
            $this->error('licence:apply is for standalone installations; the SaaS has no licence.');

            return self::FAILURE;
        }

        if (! Plan::query()->where('slug', StandalonePlan::SLUG)->exists()) {
            $this->line('There is no "'.StandalonePlan::SLUG.'" plan yet; nothing to apply. The installer creates it.');

            return self::SUCCESS;
        }

        InstalledLicence::forget();
        $licence = InstalledLicence::current();
        if (! $licence->isValid()) {
            $this->error('The installed licence is '.$licence->status().'; the plan\'s limits were left as they are.');

            return self::FAILURE;
        }

        $plans->apply($licence);

        $shown = fn (?int $v) => $v === null ? 'unlimited' : (string) $v;
        $this->info(sprintf(
            'Licence %s applied: users %s, locations %s, phones %s. Products and sales are never limited.',
            $licence->id(),
            $shown($licence->limit('max_users')),
            $shown($licence->limit('max_locations')),
            $shown($licence->limit('max_devices')),
        ));

        return self::SUCCESS;
    }
}
