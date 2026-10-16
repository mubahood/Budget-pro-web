<?php

namespace App\Services\Install;

use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\RegistrationService;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Database\Seeders\StandaloneReferenceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * One installation engine for the web installer (install.php, in short browser steps) and the CLI
 * door (`standalone:install`), after True-Doctor's (docs/STANDALONE_EDITION_PLAN.md §9).
 *
 * Both .env files and licence.json are already in place. advance() runs a few of the kernel's
 * migrations per request; the last step completes: the one-business triggers, the reference data
 * (StandaloneReferenceSeeder), the business and its owner through RegistrationService's installer
 * channel (owner verified, Standalone plan, no trial), the country's presets, `licence:apply`, the
 * installed.json marker, and the caches.
 */
class StandaloneInstaller
{
    /** What the installer asks; every value a string. */
    public const DETAILS = ['business_name', 'business_type', 'country', 'owner_name', 'owner_email', 'owner_phone', 'owner_password'];

    public function __construct(private readonly InstallCaches $caches) {}

    /** The CLI door: every migration in one go, then complete. */
    public function install(array $details): User
    {
        $this->check($details);
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new \RuntimeException('Database migrations failed: '.trim(Artisan::output()));
        }

        return $this->complete($details);
    }

    /**
     * At most $batch migrations per call (shared hosting cuts long requests off), then complete.
     *
     * @return array{done:bool,completed:int,total:int}
     */
    public function advance(array $details, int $batch = 5): array
    {
        $this->check($details);
        if (! Schema::hasTable('migrations') && Artisan::call('migrate:install') !== 0) {
            throw new \RuntimeException('The migration ledger could not be created.');
        }
        $files = glob(base_path('database/migrations/*.php')) ?: [];
        sort($files, SORT_STRING);
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_values(array_filter($files, static fn (string $path): bool => ! in_array(pathinfo($path, PATHINFO_FILENAME), $ran, true)));
        foreach (array_slice($pending, 0, max(1, min($batch, 10))) as $path) {
            if (Artisan::call('migrate', ['--path' => 'database/migrations/'.basename($path), '--force' => true]) !== 0) {
                throw new \RuntimeException('A database migration failed: '.basename($path).' '.trim(Artisan::output()));
            }
        }
        $completed = DB::table('migrations')->whereIn('migration', array_map(static fn ($p) => pathinfo($p, PATHINFO_FILENAME), $files))->count();
        if ($completed < count($files)) {
            return ['done' => false, 'completed' => $completed, 'total' => count($files)];
        }

        $this->complete($details);

        return ['done' => true, 'completed' => count($files), 'total' => count($files)];
    }

    /** The edition, the marker, the licence and its address, and that this release is covered by it. */
    private function check(array $details): void
    {
        if (! Edition::isStandalone()) {
            throw new \DomainException('The installer only runs on a standalone copy (APP_EDITION=standalone).');
        }
        if (is_file($this->marker())) {
            throw new \DomainException('This installation is already complete.');
        }
        foreach (self::DETAILS as $key) {
            if (! is_string($details[$key] ?? null) || trim($details[$key]) === '') {
                throw new \InvalidArgumentException("The installation details are missing {$key}.");
            }
        }
        if (InstallChoices::country($details['country']) === null || ! array_key_exists($details['business_type'], InstallChoices::businessTypes())) {
            throw new \InvalidArgumentException('Choose the country and the kind of business from the lists.');
        }
        InstalledLicence::forget();
        $licence = InstalledLicence::current();
        if (! $licence->isValid()) {
            throw new \DomainException('Upload a valid Budget Pro licence (licence.json) before installing.');
        }
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host === '' || ! $licence->matchesHost($host)) {
            throw new \DomainException("The licence does not cover this address ({$host}).");
        }
        $release = is_file(base_path('RELEASE.json')) ? json_decode((string) file_get_contents(base_path('RELEASE.json')), true) : null;
        if (is_array($release)) {
            $builtAt = $release['built_at'] ?? null;
            $until = $licence->updatesUntil();
            if (! is_string($builtAt) || $until === null || Carbon::parse($builtAt)->greaterThan($until->copy()->endOfDay())) {
                throw new \DomainException('This release was built after the licence\'s updates ended. Download a release your licence covers.');
            }
        }
    }

    private function complete(array $details): User
    {
        app(SingleBusinessGuard::class)->install();
        if (Artisan::call('db:seed', ['--class' => StandaloneReferenceSeeder::class, '--force' => true]) !== 0) {
            throw new \RuntimeException('The reference data could not be seeded: '.trim(Artisan::output()));
        }

        $owner = $this->business($details);

        Artisan::call('licence:apply') === 0 || throw new \RuntimeException('licence:apply failed: '.trim(Artisan::output()));

        $marker = $this->marker();
        File::ensureDirectoryExists(dirname($marker), 0700);
        $temporary = $marker.'.'.bin2hex(random_bytes(6)).'.tmp';
        File::put($temporary, json_encode([
            'edition' => Edition::STANDALONE,
            'version' => Edition::version(),
            'company_id' => (int) $owner->company_id,
            'licence_id' => InstalledLicence::current()->id(),
            'installed_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($temporary, 0600);
        rename($temporary, $marker);

        $web = config('edition.web_path');
        $this->caches->warm(base_path(), is_string($web) && is_file($web.'/artisan') ? $web : null);

        return $owner;
    }

    /** The one business and its owner (a retry after a cut-off request finds them and carries on). */
    private function business(array $details): User
    {
        $email = mb_strtolower(trim($details['owner_email']));
        $existing = Company::withoutGlobalScopes()->get();
        if ($existing->isNotEmpty()) {
            $owner = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($existing->count() !== 1 || $owner === null || (int) $owner->company_id !== (int) $existing->first()->id) {
                throw new \DomainException('This database already holds another business.');
            }

            return $owner;
        }

        $preset = InstallChoices::country($details['country']);
        $name = trim(preg_replace('/\s+/', ' ', $details['owner_name']));
        $split = strrpos($name, ' ');
        $registered = app(RegistrationService::class)->register([
            'first_name' => $split === false ? $name : substr($name, 0, $split),
            'last_name' => $split === false ? '' : substr($name, $split + 1),
            'name' => $name, 'email' => $email, 'phone_number' => trim($details['owner_phone']),
            'password' => $details['owner_password'], 'company_name' => trim($details['business_name']),
            'currency' => $preset['currency'], 'country' => strtoupper($details['country']),
            'business_type' => $details['business_type'], 'timezone' => $preset['timezone'],
        ], RegistrationService::PRODUCT_BUDGET, RegistrationService::CHANNEL_STANDALONE);

        // forceFill: the verified-at columns are not mass-assignable. Whoever installed the system typed
        // this address and number; True-Doctor's owner once met "verify your email" because they were dropped.
        $owner = $registered['user'];
        $owner->forceFill(['email_verified_at' => now(), 'phone_verified_at' => $owner->phone_e164 ? now() : null])->save();

        // The country's presets, as the setup wizard's business step applies them (modules, units, VAT, locale).
        $fields = ['country' => strtoupper($details['country']), 'business_type' => $details['business_type']];
        if ($preset['vat'] !== null) {
            $fields['tax_rate'] = $preset['vat'];
        }
        app(OnboardingService::class)->saveBusiness($registered['company']->fresh(), $fields);

        return $owner->fresh();
    }

    private function marker(): string
    {
        $path = config('edition.installed_path');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('The installation marker path is not configured.');
        }

        return $path;
    }
}
