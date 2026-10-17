<?php

namespace App\Support\Licensing;

/**
 * Checks an Ed25519 signature offline against the public keys this release trusts
 * (config edition.public_keys, from RELEASE_KEYS.json beside artisan). Never throws: anything
 * malformed — an unknown key id, bad base64, the wrong length — is simply "not verified".
 */
final class Verifier
{
    /** @param array<string,string>|null $keys key id => base64 public key; null = the configured keys */
    public function __construct(private readonly ?array $keys = null)
    {
    }

    /** @param array<string,mixed> $payload */
    public function verifies(array $payload, string $signature, string $keyId): bool
    {
        if (! extension_loaded('sodium')) {
            return false;
        }

        $keys = $this->keys ?? config('edition.public_keys', []);
        $key = is_array($keys) ? ($keys[$keyId] ?? null) : null;
        if (! is_string($key)) {
            return false;
        }

        $decodedKey = base64_decode($key, true);
        $decodedSignature = base64_decode($signature, true);
        if ($decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $decodedSignature === false || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($decodedSignature, Canonical::json($payload), $decodedKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /** A whole licence.json document: {"licence": {...}, "key_id": "...", "signature": "..."}. */
    public function verifiesDocument(array $document): bool
    {
        return is_array($document['licence'] ?? null)
            && is_string($document['signature'] ?? null)
            && is_string($document['key_id'] ?? null)
            && $this->verifies($document['licence'], $document['signature'], $document['key_id']);
    }
}
