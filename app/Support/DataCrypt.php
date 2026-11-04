<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Encryption for values stored in the database and read by BOTH apps (the kernel and the web app).
 *
 * The two apps have their own APP_KEY (sessions, cookies). A value one of them encrypts into a
 * shared table — EFRIS credentials, a buyer's SMS keys — must be readable by the other, so it uses
 * one shared key: SAAS_DATA_KEY (config saas.data_key) in both .env files, falling back to the
 * app's own key where none is set (a single-app setup, tests).
 */
final class DataCrypt
{
    private static ?Encrypter $encrypter = null;

    public static function encrypt(string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    public static function decrypt(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }

    /** A keyed hash both apps compute alike, so a value can be compared without being stored. `$context` keeps uses apart. */
    public static function hash(string $context, string $value): string
    {
        return hash_hmac('sha256', $context."\0".$value, self::key());
    }

    public static function forget(): void
    {
        self::$encrypter = null;
    }

    private static function encrypter(): Encrypter
    {
        if (self::$encrypter !== null) {
            return self::$encrypter;
        }
        return self::$encrypter = new Encrypter(self::key(), (string) config('app.cipher', 'AES-256-CBC'));
    }

    private static function key(): string
    {
        $key = (string) (config('saas.data_key') ?: config('app.key'));

        return Str::startsWith($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
