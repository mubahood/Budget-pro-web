<?php

namespace App\Console\Commands;

use App\Support\Edition;
use Illuminate\Console\Command;

/**
 * Make the Ed25519 key pair that signs standalone licences and releases
 * (docs/STANDALONE_EDITION_PLAN.md §7.2).
 *
 * Run once, on the SaaS server. The secret belongs in that server's kernel `.env` and one offline
 * copy, nowhere else: if both are lost, no installed copy can ever verify another update. The
 * public half goes into RELEASE_KEYS.json, which every release carries; old public keys are kept
 * for ever. With --write-env the pair goes straight into `.env` and the secret is never printed;
 * an existing secret is never overwritten (rotation is a deliberate, manual act).
 */
class LicensingKeygen extends Command
{
    protected $signature = 'licensing:keygen
        {--id= : Key id, e.g. 2026-a}
        {--write-env : Write the pair into .env instead of printing the secret}';

    protected $description = 'Create the key pair that signs standalone licences and releases (SaaS only)';

    public function handle(): int
    {
        if (! Edition::isSaas()) {
            $this->error('Signing keys belong to the SaaS server, never to a customer installation.');

            return self::FAILURE;
        }

        $id = (string) ($this->option('id') ?: now()->format('Y').'-'.substr(bin2hex(random_bytes(2)), 0, 3));
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,30}$/i', $id)) {
            $this->error('A key id is 2-31 letters, digits, dots and dashes, e.g. 2026-a.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $keysLine = '"'.$id.'": "'.$public.'"';

        if (! $this->option('write-env')) {
            if ((string) config('edition.signing_secret') !== '') {
                $this->warn('A signing key is already configured. This is a NEW pair, for a deliberate rotation only.');
            }
            $this->line("RELEASE_SIGNING_KEY_ID={$id}");
            $this->line("RELEASE_SIGNING_SECRET={$secret}");
            $this->newLine();
            $this->info('Add to RELEASE_KEYS.json (keep every older key):');
            $this->line($keysLine);
            $this->warn('Keep the secret in the SaaS .env and one offline copy only.');

            return self::SUCCESS;
        }

        $env = $this->laravel->environmentFilePath();
        $contents = is_file($env) ? (string) file_get_contents($env) : '';
        if ((string) config('edition.signing_secret') !== ''
            || preg_match('/^\s*RELEASE_SIGNING_SECRET\s*=\s*["\']?[^"\'\s#]/m', $contents)) {
            $this->error('A signing secret already exists. Rotating it is a deliberate act: add the new key by hand and keep the old public key trusted.');

            return self::FAILURE;
        }

        // Fill empty placeholder lines in place (the first definition wins when .env loads), else append.
        $missing = '';
        foreach (['RELEASE_SIGNING_KEY_ID' => $id, 'RELEASE_SIGNING_SECRET' => $secret] as $key => $value) {
            $pattern = '/^\s*'.$key.'\s*=.*$/m';
            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace($pattern, $key.'='.$value, $contents, 1);
            } else {
                $missing .= $key.'='.$value."\n";
            }
        }
        if ($missing !== '') {
            $contents = rtrim($contents, "\n")."\n\n# Standalone licence and release signing (docs/STANDALONE_EDITION_PLAN.md §7.2)\n".$missing;
        }

        $temporary = $env.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            $this->error('The .env file could not be written.');

            return self::FAILURE;
        }
        chmod($temporary, is_file($env) ? (fileperms($env) & 0777) : 0600);
        rename($temporary, $env);

        $this->info("Signing key {$id} written to .env. Add to RELEASE_KEYS.json (keep every older key):");
        $this->line($keysLine);
        $this->warn('Copy the secret to one offline place now, then run `php artisan config:clear`.');

        return self::SUCCESS;
    }
}
