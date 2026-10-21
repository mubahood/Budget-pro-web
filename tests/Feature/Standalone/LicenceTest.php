<?php

namespace Tests\Feature\Standalone;

use App\Models\Plan;
use App\Services\Billing\Quotas;
use App\Services\Licensing\Signer;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\Licensing\Verifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The signed licence (docs/STANDALONE_EDITION_PLAN.md §7.2), with the throwaway key pair in
 * tests/fixtures/licensing — never a real key.
 */
class LicenceTest extends TestCase
{
    use DatabaseTransactions;

    private array $keys;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/test-keypair.json')), true);
        config([
            'edition.name' => Edition::STANDALONE,
            'edition.public_keys' => [$this->keys['key_id'] => $this->keys['public']],
            'edition.signing_key_id' => null,
            'edition.signing_secret' => null,
        ]);
        InstalledLicence::forget();
    }

    protected function tearDown(): void
    {
        InstalledLicence::forget();
        foreach ($this->cleanup as $path) {
            is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
        }
        parent::tearDown();
    }

    public function test_the_fixture_licence_is_valid_and_reads_offline(): void
    {
        $licence = $this->install($this->fixture());

        $this->assertTrue($licence->isValid());
        $this->assertSame('valid', $licence->status());
        $this->assertSame('7d1c2f0e-3b8a-4c55-9e61-0f2a9b7c4d10', $licence->id());
        $this->assertSame('Fresh Mart Ltd', $licence->customer());
        $this->assertSame('2099-12-31', $licence->updatesUntil()?->format('Y-m-d'));
        $this->assertFalse($licence->updatesEnded());
        $this->assertFalse($licence->whiteLabel());
        $this->assertSame(['shop.freshmart.test'], $licence->domains());
        $this->assertSame('test-fixture', $licence->document()['key_id']);
        $this->assertSame(10, $licence->limit('max_users'));
        $this->assertSame(3, $licence->limit('max_locations'));
        $this->assertSame(10, $licence->limit('max_devices'));
        $this->assertNull($licence->limit('max_products'), 'a licence never limits products');
        $this->assertNull($licence->limit('max_sales_per_month'), 'a licence never limits sales');
    }

    public function test_the_host_matches_without_regard_to_case_or_port_and_a_mismatch_is_reported(): void
    {
        $licence = $this->install($this->fixture());

        $this->assertTrue($licence->matchesHost('SHOP.FreshMart.test'));
        $this->assertTrue($licence->matchesHost('shop.freshmart.test:8443'));
        $this->assertFalse($licence->matchesHost('other.freshmart.test'));
        $this->assertFalse($licence->matchesHost(''));
        $this->assertTrue($licence->isValid(), 'a wrong address is a banner, never an invalid licence');
    }

    public function test_one_altered_byte_or_an_unknown_key_id_makes_it_invalid(): void
    {
        $text = (string) file_get_contents(base_path('tests/fixtures/licensing/licence.json'));
        $this->assertFalse($this->installText(str_replace('"max_users": 10', '"max_users": 11', $text))->isValid());
        $this->assertFalse($this->installText(str_replace('Fresh Mart Ltd', 'Fresh Mart Ltc', $text))->isValid());

        $document = $this->fixture();
        $signature = base64_decode($document['signature']);
        $signature[10] = chr(ord($signature[10]) ^ 1);
        $document['signature'] = base64_encode($signature);
        $this->assertSame('invalid', $this->install($document)->status());

        $document = $this->fixture();
        $document['key_id'] = 'not-trusted';
        $this->assertFalse($this->install($document)->isValid());

        config(['edition.public_keys' => []]);
        $this->assertFalse($this->install($this->fixture())->isValid(), 'a copy trusts only the keys its release shipped');
    }

    public function test_a_missing_damaged_or_foreign_file_is_invalid_and_never_throws(): void
    {
        config(['edition.licence_path' => sys_get_temp_dir().'/bp-no-such-licence-'.bin2hex(random_bytes(4)).'.json']);
        $missing = InstalledLicence::current();
        $this->assertFalse($missing->isValid());
        $this->assertSame('missing', $missing->status());
        $this->assertNull($missing->id());
        $this->assertNull($missing->updatesUntil());
        $this->assertNull($missing->limit('max_users'));
        $this->assertFalse($missing->matchesHost('shop.freshmart.test'));
        $this->assertNull($missing->document());

        $this->assertSame('invalid', $this->installText('{"licence": {"format": 1, ')->status());
        $this->assertSame('invalid', $this->installText("\x00\xff not json")->status());
    }

    public function test_updates_that_ended_leave_the_licence_valid(): void
    {
        $this->asSaas();
        $document = app(Signer::class)->issue([
            'customer' => 'Fresh Mart Ltd', 'domains' => ['Shop.FreshMart.test'], 'updates_until' => '2020-01-01',
            'limits' => ['max_users' => 2], 'white_label' => true,
        ]);
        config(['edition.name' => Edition::STANDALONE]);

        $licence = $this->install($document);
        $this->assertTrue($licence->isValid());
        $this->assertTrue($licence->updatesEnded());
        $this->assertTrue($licence->updatesUntil()->isPast());
        $this->assertTrue($licence->whiteLabel());
        $this->assertSame(2, $licence->limit('max_users'));
        $this->assertNull($licence->limit('max_locations'));
        $this->assertTrue($licence->matchesHost('shop.freshmart.test'));
    }

    public function test_the_signer_reproduces_the_fixture_and_refuses_off_the_saas(): void
    {
        $this->asSaas();
        $fixture = $this->fixture();
        $shuffled = array_reverse($fixture['licence'], true);
        $this->assertSame(['key_id' => 'test-fixture', 'signature' => $fixture['signature']], app(Signer::class)->sign($shuffled));
        $this->assertSame($this->keys['public'], app(Signer::class)->publicKey());
        $this->assertTrue((new Verifier)->verifiesDocument(app(Signer::class)->issue(['customer' => 'A', 'domains' => ['a.test'], 'updates_until' => '2030-01-01'])));

        try {
            app(Signer::class)->issue(['customer' => 'A', 'domains' => ['a.test'], 'updates_until' => '2030-01-01', 'limits' => ['max_products' => 5]]);
            $this->fail('A licence must never limit products.');
        } catch (\InvalidArgumentException) {
        }

        config(['edition.name' => Edition::STANDALONE]);
        $this->expectException(\RuntimeException::class);
        app(Signer::class)->sign($fixture['licence']);
    }

    public function test_current_is_memoised_until_forgotten(): void
    {
        $this->install($this->fixture());
        $first = InstalledLicence::current();
        $this->assertSame($first, InstalledLicence::current());
        InstalledLicence::forget();
        $this->assertNotSame($first, InstalledLicence::current());
    }

    // licence:apply and licensing:keygen ─────────────────────────────────

    public function test_licence_apply_writes_the_licence_limits_onto_the_standalone_plan(): void
    {
        $this->install($this->fixture());
        $this->artisan('licence:apply')->expectsOutputToContain('There is no "standalone" plan')->assertSuccessful();

        $plan = Plan::query()->updateOrCreate(['slug' => 'standalone'], [
            'name' => 'Standalone', 'price' => 0, 'currency' => 'UGX', 'interval' => 'lifetime', 'is_public' => false,
            'limits' => ['max_users' => 1, 'max_products' => 50, 'max_sales_per_month' => 100, 'storage_mb' => 500, 'max_stock_items' => 9],
        ]);

        $this->artisan('licence:apply')->expectsOutputToContain('users 10, locations 3, phones 10')->assertSuccessful();
        $limits = $plan->fresh()->limits;
        ksort($limits);
        $this->assertSame(['max_devices' => 10, 'max_locations' => 3, 'max_products' => null, 'max_sales_per_month' => null, 'max_users' => 10, 'storage_mb' => null], $limits);
        $keys = array_values(array_diff_key(Quotas::KEYS, Quotas::LINE_KEYS));
        sort($keys);
        $this->assertSame($keys, array_keys($limits), 'every quota key is set, so no default limit applies');

        // An invalid licence never changes (or loosens) the limits; the SaaS has no licence at all.
        $this->installText('{"damaged": true}');
        $this->artisan('licence:apply')->assertFailed();
        $this->assertSame(10, $plan->fresh()->limits['max_users']);
        config(['edition.name' => Edition::SAAS]);
        $this->artisan('licence:apply')->assertFailed();
    }

    public function test_keygen_prints_a_working_pair_and_never_overwrites_a_secret(): void
    {
        $this->artisan('licensing:keygen', ['--id' => '2026-a'])->assertFailed(); // standalone: never

        config(['edition.name' => Edition::SAAS]);
        $this->artisan('licensing:keygen', ['--id' => 'bad id!'])->assertFailed();
        $this->artisan('licensing:keygen', ['--id' => '2026-a'])
            ->expectsOutputToContain('RELEASE_SIGNING_KEY_ID=2026-a')
            ->expectsOutputToContain('"2026-a": "')
            ->assertSuccessful();

        $dir = $this->cleanup[] = sys_get_temp_dir().'/bp-keygen-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_NAME=Test\nRELEASE_SIGNING_KEY_ID=\nRELEASE_SIGNING_SECRET=\n");
        $this->app->useEnvironmentPath($dir);

        $this->artisan('licensing:keygen', ['--id' => '2026-b', '--write-env' => true])
            ->expectsOutputToContain('"2026-b": "')
            ->doesntExpectOutputToContain('RELEASE_SIGNING_SECRET=')
            ->assertSuccessful();
        $env = \Dotenv\Dotenv::parse((string) file_get_contents($dir.'/.env'));
        $this->assertSame('2026-b', $env['RELEASE_SIGNING_KEY_ID']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen(base64_decode($env['RELEASE_SIGNING_SECRET'], true)));
        $this->assertSame(1, substr_count((string) file_get_contents($dir.'/.env'), 'RELEASE_SIGNING_SECRET='));

        $before = file_get_contents($dir.'/.env');
        $this->artisan('licensing:keygen', ['--id' => '2026-c', '--write-env' => true])->assertFailed();
        $this->assertSame($before, file_get_contents($dir.'/.env'));
    }

    // Helpers ────────────────────────────────────────────────────────────

    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/licensing/licence.json')), true);
    }

    private function install(array $document): InstalledLicence
    {
        return $this->installText(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function installText(string $text): InstalledLicence
    {
        $path = $this->cleanup[] = tempnam(sys_get_temp_dir(), 'bp-licence-');
        file_put_contents($path, $text);
        config(['edition.licence_path' => $path]);
        InstalledLicence::forget();

        return InstalledLicence::current();
    }

    private function asSaas(): void
    {
        config([
            'edition.name' => Edition::SAAS,
            'edition.signing_key_id' => $this->keys['key_id'],
            'edition.signing_secret' => $this->keys['secret'],
        ]);
    }
}
