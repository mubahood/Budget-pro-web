<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Settings › Connections on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §13): the buyer's own
 * email server, SMS, WhatsApp and Flutterwave accounts, stored encrypted in `install_settings`
 * (DataCrypt's shared key, so both apps read the same values) instead of `.env`, which a web request
 * should never rewrite.
 *
 * `overlay()` runs at boot in both apps and, on standalone only, copies what is stored into the
 * runtime config the existing channels already read (mail.*, messaging.*, flutterwave.*). On the
 * SaaS it does nothing: there the keys are ours, in `.env`.
 */
final class InstallSettings
{
    /** Every key the Connections screen may store. */
    public const KEYS = [
        'mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.password', 'mail.from_address', 'mail.from_name',
        'sms.username', 'sms.api_key', 'sms.sender_id',
        'whatsapp.token', 'whatsapp.phone_number_id', 'whatsapp.receipt_template', 'whatsapp.otp_template',
        'flutterwave.public_key', 'flutterwave.secret_key', 'flutterwave.encryption_key', 'flutterwave.secret_hash',
    ];

    /** Never shown again once saved: the screen says "Saved" instead. */
    public const SECRETS = ['mail.password', 'sms.api_key', 'whatsapp.token', 'flutterwave.secret_key', 'flutterwave.encryption_key', 'flutterwave.secret_hash'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /** Store a value (encrypted); an empty value removes the key. */
    public static function set(string $key, ?string $value, ?int $by = null): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown connection setting \"{$key}\".");
        }
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            self::forget($key);

            return;
        }
        $row = ['value' => DataCrypt::encrypt($value), 'updated_by' => $by, 'updated_at' => now()];
        if (DB::table('install_settings')->where('key', $key)->exists()) {
            DB::table('install_settings')->where('key', $key)->update($row);
        } else {
            DB::table('install_settings')->insert($row + ['key' => $key, 'created_at' => now()]);
        }
    }

    public static function forget(string $key): void
    {
        DB::table('install_settings')->where('key', $key)->delete();
    }

    /**
     * Every stored value, decrypted. A row that no longer decrypts (the data key changed) is left
     * out, as if it had never been saved, rather than breaking every page.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (DB::table('install_settings')->whereIn('key', self::KEYS)->get(['key', 'value']) as $row) {
            if ($row->value === null || $row->value === '') {
                continue;
            }
            try {
                $out[$row->key] = DataCrypt::decrypt((string) $row->value);
            } catch (\Throwable) {
                // unreadable: treated as not set
            }
        }

        return $out;
    }

    /**
     * Fill the runtime config from what is stored — standalone only. Safe before installation
     * (no database, no table yet): it then leaves the config exactly as `.env` made it.
     */
    public static function overlay(): void
    {
        if (! Edition::isStandalone()) {
            return;
        }
        // Collections go straight to the buyer's own Flutterwave balance: no platform fee, ever.
        config(['flutterwave.momo.platform_fee_percent' => 0.0]);
        try {
            $s = self::all();
        } catch (\Throwable) {
            return;
        }

        if (isset($s['mail.host'])) {
            $encryption = $s['mail.encryption'] ?? 'tls';
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => array_merge((array) config('mail.mailers.smtp'), [
                    'transport' => 'smtp', 'url' => null, 'host' => $s['mail.host'], 'port' => (int) ($s['mail.port'] ?? 587),
                    'encryption' => $encryption === 'none' ? null : $encryption, 'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                    'username' => $s['mail.username'] ?? null, 'password' => $s['mail.password'] ?? null, 'timeout' => 15,
                ]),
                'messaging.mail.driver' => 'mail',
            ]);
            if (app()->resolved('mail.manager')) {
                app('mail.manager')->purge('smtp'); // a mailer built before this overlay must not keep the old server
            }
        }
        if (isset($s['mail.from_address'])) {
            config(['mail.from.address' => $s['mail.from_address'], 'mail.from.name' => $s['mail.from_name'] ?? config('mail.from.name')]);
        }

        if (isset($s['sms.username'], $s['sms.api_key'])) {
            config([
                'messaging.sms.driver' => 'africastalking',
                'messaging.sms.africastalking.username' => $s['sms.username'],
                'messaging.sms.africastalking.api_key' => $s['sms.api_key'],
                'messaging.sms.africastalking.sender_id' => $s['sms.sender_id'] ?? null,
            ]);
        }

        if (isset($s['whatsapp.token'], $s['whatsapp.phone_number_id'])) {
            config([
                'messaging.whatsapp.driver' => 'meta',
                'messaging.whatsapp.meta.token' => $s['whatsapp.token'],
                'messaging.whatsapp.meta.phone_number_id' => $s['whatsapp.phone_number_id'],
            ]);
            foreach (['receipt_template', 'otp_template'] as $template) {
                if (isset($s["whatsapp.{$template}"])) {
                    config(["messaging.whatsapp.meta.{$template}" => $s["whatsapp.{$template}"]]);
                }
            }
        }

        foreach (['public_key', 'secret_key', 'encryption_key', 'secret_hash'] as $key) {
            if (isset($s["flutterwave.{$key}"])) {
                config(["flutterwave.{$key}" => $s["flutterwave.{$key}"]]);
            }
        }
    }

    /**
     * The channels that can reach someone right now (after the overlay): what password reset and
     * receipts depend on. Empty = the owner's "Set one up in Settings › Connections" banner.
     *
     * @return list<string> of mail, sms, whatsapp
     */
    public static function readyChannels(): array
    {
        $ready = [];
        if (config('messaging.mail.driver') === 'mail' && ! in_array(config('mail.default'), [null, '', 'log', 'array'], true)) {
            $ready[] = 'mail';
        }
        if (config('messaging.sms.driver') === 'africastalking' && filled(config('messaging.sms.africastalking.username')) && filled(config('messaging.sms.africastalking.api_key'))) {
            $ready[] = 'sms';
        }
        if (config('messaging.whatsapp.driver') === 'meta' && filled(config('messaging.whatsapp.meta.token')) && filled(config('messaging.whatsapp.meta.phone_number_id'))) {
            $ready[] = 'whatsapp';
        }

        return $ready;
    }
}
