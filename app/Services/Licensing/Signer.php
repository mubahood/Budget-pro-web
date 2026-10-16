<?php

namespace App\Services\Licensing;

use App\Support\Edition;
use App\Support\Licensing\Canonical;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Str;

/**
 * Signs standalone licences (and release manifests) with the SaaS server's Ed25519 secret
 * (docs/STANDALONE_EDITION_PLAN.md §7.2). SaaS only: a customer's copy holds public keys, never
 * this secret. The secret is config edition.signing_secret (RELEASE_SIGNING_SECRET, base64 of the
 * 64-byte libsodium secret key) under the id edition.signing_key_id.
 */
final class Signer
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{key_id:string,signature:string}
     */
    public function sign(array $payload): array
    {
        [$keyId, $secret] = $this->key();

        return [
            'key_id' => $keyId,
            'signature' => base64_encode(sodium_crypto_sign_detached(Canonical::json($payload), $secret)),
        ];
    }

    /**
     * A complete, signed licence.json document.
     *
     * @param  array{customer:string,domains:list<string>,updates_until:string|\DateTimeInterface,
     *               limits?:array<string,int|null>,white_label?:bool,id?:string}  $details
     * @return array{licence:array<string,mixed>,key_id:string,signature:string}
     */
    public function issue(array $details): array
    {
        $customer = trim((string) ($details['customer'] ?? ''));
        if ($customer === '') {
            throw new \InvalidArgumentException('A licence needs the customer\'s name.');
        }

        $domains = [];
        foreach ((array) ($details['domains'] ?? []) as $domain) {
            $domain = rtrim(strtolower(trim((string) $domain)), '.');
            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain)) {
                throw new \InvalidArgumentException("\"{$domain}\" is not a web address.");
            }
            $domains[] = $domain;
        }
        if ($domains === []) {
            throw new \InvalidArgumentException('A licence needs at least one web address.');
        }

        $until = $details['updates_until'] ?? null;
        $until = $until instanceof \DateTimeInterface ? $until->format('Y-m-d') : (string) $until;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
        if ($parsed === false || $parsed->format('Y-m-d') !== $until) {
            throw new \InvalidArgumentException('updates_until must be a date (Y-m-d).');
        }

        $limits = [];
        foreach (InstalledLicence::LIMIT_KEYS as $key) {
            $value = $details['limits'][$key] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new \InvalidArgumentException("{$key} must be a whole number or empty (unlimited).");
            }
            $limits[$key] = $value;
        }
        if ($unknown = array_diff(array_keys((array) ($details['limits'] ?? [])), InstalledLicence::LIMIT_KEYS)) {
            throw new \InvalidArgumentException('A licence cannot limit '.implode(', ', $unknown).'.');
        }

        $licence = [
            'format' => 1,
            'product' => InstalledLicence::PRODUCT,
            'id' => (string) ($details['id'] ?? Str::uuid()),
            'customer' => $customer,
            'domains' => array_values(array_unique($domains)),
            'updates_until' => $until,
            'limits' => $limits,
            'white_label' => (bool) ($details['white_label'] ?? false),
        ];

        return ['licence' => $licence] + $this->sign($licence);
    }

    /** The public half of the signing key (base64), as RELEASE_KEYS.json carries it. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->key()[1]));
    }

    /** @return array{0:string,1:string} key id and raw secret */
    private function key(): array
    {
        if (! Edition::isSaas()) {
            throw new \RuntimeException('Signing is only available on the SaaS installation.');
        }

        $keyId = config('edition.signing_key_id');
        $secret = base64_decode((string) config('edition.signing_secret'), true);
        if (! is_string($keyId) || $keyId === '' || $secret === false
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Release signing is not configured (RELEASE_SIGNING_KEY_ID / RELEASE_SIGNING_SECRET).');
        }

        return [$keyId, $secret];
    }
}
