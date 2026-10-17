<?php

namespace App\Console\Commands;

use App\Services\Install\StandaloneInstaller;
use Illuminate\Console\Command;

/**
 * The installer's CLI door (docs/STANDALONE_EDITION_PLAN.md §9.2), for technicians and the release
 * proof: the same engine as install.php, once both .env files and storage/app/private/licence.json
 * are in place. The answers come from a private JSON file, never the command line (no password in
 * the shell history or the process list).
 *
 * {"business_name": "…", "business_type": "retail", "country": "UG", "owner_name": "…",
 *  "owner_email": "…", "owner_phone": "…", "owner_password": "…"}
 */
class InstallStandalone extends Command
{
    protected $signature = 'standalone:install {--config= : A private JSON file with the business and owner details}';

    protected $description = 'Install a standalone copy (after its .env files and licence.json are in place)';

    public function handle(StandaloneInstaller $installer): int
    {
        $path = (string) $this->option('config');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->error('Give --config=<a readable private JSON file>.');

            return self::FAILURE;
        }
        $details = json_decode((string) file_get_contents($path), true);
        if (! is_array($details) || array_diff(StandaloneInstaller::DETAILS, array_keys($details)) !== []) {
            $this->error('The file needs: '.implode(', ', StandaloneInstaller::DETAILS).'.');

            return self::FAILURE;
        }
        foreach (StandaloneInstaller::DETAILS as $key) {
            if (! is_string($details[$key])) {
                $this->error("{$key} must be text.");

                return self::FAILURE;
            }
        }
        // The owner of a whole copy: 10 characters or more, and the one password rule (letters, numbers, not leaked).
        if (mb_strlen($details['owner_password']) < 10) {
            $this->error('The owner password needs at least 10 characters, with letters and numbers.');

            return self::FAILURE;
        }
        if (($weak = \App\Support\Rules\PasswordPolicy::refusal($details['owner_password'])) !== null) {
            $this->error('The owner password: '.$weak);

            return self::FAILURE;
        }

        try {
            $owner = $installer->install(array_intersect_key($details, array_flip(StandaloneInstaller::DETAILS)));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Installed. {$owner->email} owns business #{$owner->company_id}.");
        $this->line('Add the scheduler: * * * * * cd '.base_path().' && php artisan schedule:run >/dev/null 2>&1');

        return self::SUCCESS;
    }
}
