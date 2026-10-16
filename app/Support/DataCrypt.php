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

    public static function forget(): void
    {
        self::$encrypter = null;
    }

    private static function encrypter(): Encrypter
    {
        if (self::$encrypter !== null) {
            return self::$encrypter;
        }
        $key = (string) (config('saas.data_key') ?: config('app.key'));
        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return self::$encrypter = new Encrypter($key, (string) config('app.cipher', 'AES-256-CBC'));
    }
}
