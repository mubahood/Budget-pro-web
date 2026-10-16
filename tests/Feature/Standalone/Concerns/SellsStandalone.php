<?php

namespace Tests\Feature\Standalone\Concerns;

use App\Models\StandaloneLicence;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use Illuminate\Support\Facades\File;

/**
 * Fixtures for selling the standalone edition (docs/STANDALONE_EDITION_PLAN.md §11): a signing key,
 * a product, a published release with a real archive on disk, a licence.
 */
trait SellsStandalone
{
    /** @var list<string> */
    private array $archives = [];

    protected function useSigningKey(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config([
            'edition.name' => 'saas', 'edition.signing_key_id' => 'sale-test',
            'edition.signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'edition.public_keys' => ['sale-test' => base64_encode(sodium_crypto_sign_publickey($pair))],
        ]);
    }

    protected function product(array $attributes = []): StandaloneProduct
    {
        return StandaloneProduct::create($attributes + [
            'name' => 'Shop', 'slug' => 'shop-'.bin2hex(random_bytes(3)), 'price_ugx' => 1500000, 'price_usd' => 400,
            'renewal_price_ugx' => 300000, 'renewal_price_usd' => 80, 'updates_months' => 12, 'is_active' => true,
            'limits' => ['max_users' => 5, 'max_locations' => 1, 'max_devices' => 5],
        ]);
    }

    protected function release(string $version = '1.0.0', array $attributes = []): StandaloneRelease
    {
        $file = storage_path('framework/testing/sale-'.bin2hex(random_bytes(4)).'.zip');
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, 'PK release '.$version);
        $this->archives[] = $file;

        return StandaloneRelease::create($attributes + [
            'version' => $version, 'status' => StandaloneRelease::PUBLISHED, 'published_at' => now(),
            'archive_path' => $file, 'sha256' => hash_file('sha256', $file), 'size' => filesize($file),
        ]);
    }

    protected function licence(StandaloneProduct $product, array $attributes = []): StandaloneLicence
    {
        return StandaloneLicence::create($attributes + [
            'product_id' => $product->id, 'customer' => 'Kato Hardware', 'contact_name' => 'Amina Kato',
            'email' => 'owner@kato.test', 'phone' => '+256772000111', 'domains' => ['shop.kato.test'],
            'limits' => $product->licenceLimits(), 'updates_until' => now()->addYear()->toDateString(),
            'status' => 'active', 'source' => 'online',
        ]);
    }

    protected function tearDownSellsStandalone(): void
    {
        foreach ($this->archives as $file) {
            @unlink($file);
        }
    }

    /** @return array{business:string,name:string,email:string,phone:string,domain:string} */
    protected function buyer(array $overrides = []): array
    {
        return $overrides + ['business' => 'Kato Hardware', 'name' => 'Amina Kato', 'email' => 'Owner@Kato.test', 'phone' => '+256772000111', 'domain' => 'Shop.Kato.test'];
    }
}
