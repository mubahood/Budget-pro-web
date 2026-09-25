<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-time codes (plan C6, P3-1): 6 digits, 10 minutes, 5 tries, 5 per hour per
 * identifier; delivered by WhatsApp (template) then SMS for phones, mail for emails.
 */
class OtpService
{
    public const PURPOSES = ['login', 'register', 'reset', 'verify'];

    /** @return array{sent: bool, channel: string|null, expires_in: int} */
    public function request(string $identifier, string $purpose, ?string $ip = null): array
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw BusinessRuleException::make('invalid_purpose', 'Unknown code purpose.');
        }
        $cfg = config('messaging.otp');
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
        $isEmail = str_contains($identifier, '@');
        $text = 'Your '.config('app.name')." code is {$code}. It expires in {$cfg['ttl_minutes']} minutes. Never share it.";
        $channels = $isEmail ? ['mail'] : ['whatsapp', 'sms'];
        $meta = ['purpose' => 'otp', 'options' => ['whatsapp' => ['template' => config('messaging.whatsapp.meta.otp_template'), 'params' => [$code]], 'mail' => ['subject' => 'Your verification code']]];
        $logId = app(Messenger::class)->send($identifier, $text, $channels, $meta, now: true);
        /** @var object{status: string, channel: string|null}|null $row */
        $row = DB::table('message_log')->find($logId);
        if (app()->runningUnitTests()) {
            cache()->put('otp_test_'.$identifier, $code, 600); // lets feature tests read the code
        }

        return ['sent' => $row?->status === 'sent', 'channel' => $row?->channel, 'expires_in' => $cfg['ttl_minutes'] * 60];
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
