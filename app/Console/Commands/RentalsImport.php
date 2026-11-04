<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Rentals\Import\RentalImport;
use App\Support\LocalDate;
use Illuminate\Console\Command;

/**
 * Bring an account's books in from a folder of CSV files, one per sheet: landlords.csv, properties.csv, units.csv,
 * tenancies.csv, opening_cash.csv (docs/estate-ledger/04-architecture.md §5.10, §13; RentalImport checks and writes). Run as
 * the account's owner.
 */
class RentalsImport extends Command
{
    protected $signature = 'rentals:import {path : A folder of CSV files, or one file named after its sheet} {--company= : The account} {--as-of= : The day the balances are as of (today by default)} {--dry-run : Check only} {--allow-existing : Add to the tenancies already there}';

    protected $description = 'Estate Ledger: bring landlords, properties, units, tenancies and opening balances in from CSV files';

    public function handle(RentalImport $import): int
    {
        $company = Company::withoutGlobalScopes()->find((int) $this->option('company'));
        $owner = $company !== null ? User::withoutGlobalScopes()->find($company->owner_id) : null;
        if ($owner === null) {
            $this->error('Name the account with --company.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('path');
        $files = is_dir($path) ? (glob(rtrim($path, '/').'/*.csv') ?: []) : (is_file($path) ? [$path] : []);
        if ($files === []) {
            $this->error("No CSV files at {$path}.");

            return self::FAILURE;
        }
        $sheets = [];
        foreach ($files as $file) {
            $sheets[pathinfo($file, PATHINFO_FILENAME)] = (string) file_get_contents($file);
        }
        $cid = (int) $company->id;
        $asOf = (string) ($this->option('as-of') ?: LocalDate::today($cid)->toDateString());
        $options = ['allow_existing' => (bool) $this->option('allow-existing')];
        try {
            if ($this->option('dry-run')) {
                $r = $import->dryRun($cid, $owner, $sheets, $asOf, $options);
                foreach ($r['errors'] as $e) {
                    $this->error("{$e['sheet']}, row {$e['row']}: {$e['message']}");
                }
                $this->info($r['summary']['rows'].' row(s) read, '.$r['ok'].' fine, '.count($r['errors']).' to fix. Nothing was written.');

                return $r['errors'] === [] ? self::SUCCESS : self::FAILURE;
            }
            $r = $import->run($cid, $owner, $sheets, $asOf, $options);
            $this->info('Imported: '.json_encode($r['created']));

            return self::SUCCESS;
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
