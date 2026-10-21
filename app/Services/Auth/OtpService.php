<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-time codes (plan C6, P3-1): 6 digits, 10 minutes, 5 tries, 5 per hour per
 * identifier. They go by email, which costs nothing but hosting: a code asked for with
 * a phone number (sign-in, reset) is sent to that account's email. WhatsApp then SMS
 * for phones only when OTP_PHONE_CODES is on (messaging.otp.phone_codes).
 */
class OtpService
{
    public const PURPOSES = ['login', 'register', 'reset', 'verify'];

    /** @return array{sent: bool, channel: string|null, sent_to: string, expires_in: int} */
    /**
     * @param  string|null  $deliverTo  where the code goes when the identifier is not an address itself (a SACCO
     *                                  member's codes are keyed `sacco:{email}` and sent to that email, AK-61)
     * @param  string|null  $sender  the name the code is sent in (a SACCO's own name); null = the product's
     */
    public function request(string $identifier, string $purpose, ?string $ip = null, ?string $deliverTo = null, ?string $sender = null): array
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw BusinessRuleException::make('invalid_purpose', 'Unknown code purpose.');
        }
        $cfg = config('messaging.otp');
        $to = $deliverTo ?? $this->deliverTo($identifier, $purpose);
        $recent = DB::table('otp_codes')->where('identifier', $identifier)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= $cfg['max_per_hour']) {
            throw BusinessRuleException::make('otp_rate_limited', 'Too many codes requested. Please wait a while and try again.');
        }
        $code = str_pad((string) random_int(0, 10 ** $cfg['length'] - 1), $cfg['length'], '0', STR_PAD_LEFT);
        DB::table('otp_codes')->where('identifier', $identifier)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        DB::table('otp_codes')->insert([
            'identifier' => $identifier, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes($cfg['ttl_minutes']),
            'ip' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $isEmail = str_contains($to, '@');
        // A standalone copy speaks in the business's name (STANDALONE_EDITION_PLAN.md §15).
        // An Akiba Ledger SACCO speaks only in its own name ($sender), never the family's.
        $product = $sender ?? (\App\Support\Edition::isStandalone() ? \App\Support\ShopBrand::productName() : config('app.name'));
        $text = 'Your '.$product." code is {$code}. It expires in {$cfg['ttl_minutes']} minutes. Never share it.";
        $channels = $isEmail ? ['mail'] : ['whatsapp', 'sms'];
        $meta = ['purpose' => 'otp', 'options' => ['whatsapp' => ['template' => config('messaging.whatsapp.meta.otp_template'), 'params' => [$code]], 'mail' => ['subject' => 'Your verification code']]];
        $logId = app(Messenger::class)->send($to, $text, $channels, $meta, now: true);
        /** @var object{status: string, channel: string|null}|null $row */
        $row = DB::table('message_log')->find($logId);
        if (app()->runningUnitTests()) {
            cache()->put('otp_test_'.$identifier, $code, 600); // lets feature tests read the code
        }

        return ['sent' => $row?->status === 'sent', 'channel' => $row?->channel, 'sent_to' => $isEmail ? self::mask($to) : $to, 'expires_in' => $cfg['ttl_minutes'] * 60];
    }

    /** Phone codes cost money per message; whether they are sent at all (off unless OTP_PHONE_CODES=true). */
    public static function phoneCodes(): bool
    {
        return (bool) config('messaging.otp.phone_codes', false);
    }

    /**
     * Where the code goes: an email to itself; a phone, with phone codes off, to its account's email.
     * A phone can neither sign up nor be verified by code then, and an account without an email is told so.
     */
    private function deliverTo(string $identifier, string $purpose): string
    {
        if (str_contains($identifier, '@') || self::phoneCodes()) {
            return $identifier;
        }
        if ($purpose === 'register') {
            throw BusinessRuleException::make('email_required', 'Sign up with your email. We send codes by email.');
        }
        if ($purpose === 'verify') {
            throw BusinessRuleException::make('phone_codes_off', 'Phone numbers are not checked by code. Verify your email instead.');
        }
        $email = AccountLookup::find($identifier)?->email;
        if (! is_string($email) || ! str_contains($email, '@')) {
            throw BusinessRuleException::make('no_email', 'This account has no email, so we cannot send a code. Sign in with your password, or ask support to add your email.');
        }

        return strtolower(trim($email));
    }

    /** The contact a code for this identifier proves: a phone's code went to its email unless phone codes are on. */
    public static function verifiedColumn(string $identifier): string
    {
        return str_contains($identifier, '@') || ! self::phoneCodes() ? 'email_verified_at' : 'phone_verified_at';
    }

    /** m•••@gmail.com: enough to recognise the inbox without showing the address to whoever typed the phone. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    /** Consumes the code on success; throws otherwise. */
    public function verify(string $identifier, string $purpose, string $code): void
    {
        $row = DB::table('otp_codes')->where('identifier', $identifier)->where('purpose', $purpose)->whereNull('consumed_at')->orderByDesc('id')->first();
        if ($row === null || now()->greaterThan($row->expires_at)) {
            throw BusinessRuleException::make('otp_expired', 'That code has expired. Request a new one.');
        }
        if ($row->attempts >= config('messaging.otp.max_attempts')) {
            DB::table('otp_codes')->where('id', $row->id)->update(['consumed_at' => now()]);
            throw BusinessRuleException::make('otp_locked', 'Too many wrong codes. Request a new one.');
        }
        if (! Hash::check(trim($code), $row->code_hash)) {
            DB::table('otp_codes')->where('id', $row->id)->increment('attempts');
            throw BusinessRuleException::make('otp_invalid', 'That code is not correct.');
        }
        DB::table('otp_codes')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
    }

    /** Normalise a phone or email into the identifier codes are keyed by. */
    public static function identifier(string $raw, string $country = 'UG'): string
    {
        $raw = trim($raw);
        if (str_contains($raw, '@')) {
            return strtolower($raw);
        }
        $e164 = \App\Support\Phone::e164($raw, $country);
        if ($e164 === null) {
            throw BusinessRuleException::make('invalid_phone', 'Enter a valid phone number.');
        }

        return $e164;
    }
}
