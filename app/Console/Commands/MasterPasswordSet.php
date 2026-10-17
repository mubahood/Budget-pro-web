<?php

namespace App\Console\Commands;

use App\Support\Edition;
use App\Support\MasterPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Makes the master password's hash (App\Support\MasterPassword). Asks for the password twice without
 * showing it, refuses anything shorter than 20 characters, and prints the MASTER_PASSWORD_HASH line
 * to paste into BOTH .env files (budget-pro and budget-pro-new). It never writes a .env file and
 * never prints the password.
 */
class MasterPasswordSet extends Command
{
    protected $signature = 'master-password:set';

    protected $description = 'Make the master password hash (MASTER_PASSWORD_HASH) for both .env files';

    public function handle(): int
    {
        if (Edition::isStandalone()) {
            $this->error('A standalone copy has no master password.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('New master password ('.MasterPassword::MIN_LENGTH.' characters or more)');
        if (mb_strlen($password) < MasterPassword::MIN_LENGTH) {
            $this->error('Too short: use '.MasterPassword::MIN_LENGTH.' characters or more. Nothing was made.');

            return self::FAILURE;
        }
        if ((string) $this->secret('Type it again') !== $password) {
            $this->error('The two did not match. Nothing was made.');

            return self::FAILURE;
        }

        $hash = Hash::driver('bcrypt')->make($password, ['rounds' => 12]);
        $this->info('Paste this line into BOTH .env files (budget-pro and budget-pro-new), replacing any MASTER_PASSWORD line:');
        $this->newLine();
        // Single quotes: the hash contains "$", which .env files would otherwise read as a variable.
        $this->line("MASTER_PASSWORD_HASH='{$hash}'");
        $this->newLine();
        $this->line('Then set MASTER_PASSWORD_ALLOWED_IPS (the addresses it may be used from) and run php artisan config:cache in both apps.');

        return self::SUCCESS;
    }
}
