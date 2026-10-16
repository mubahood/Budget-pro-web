<?php

namespace App\Services\Messaging;

use App\Models\Company;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * "Send a test" on Settings › Connections (docs/STANDALONE_EDITION_PLAN.md §13): one message to the
 * owner through the very channel receipts and password reset use (Messenger), and the provider's
 * refusal turned into plain words the owner can act on.
 */
class ConnectionTest
{
    public const CHANNELS = ['mail', 'sms', 'whatsapp'];

    /** @return array{ok: bool, message: string} */
    public function send(string $channel, User $owner, Company $company): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return ['ok' => false, 'message' => 'There is no test for this connection.'];
        }
        if (! in_array($channel, \App\Support\InstallSettings::readyChannels(), true)) {
            return ['ok' => false, 'message' => 'Save the '.self::label($channel).' settings first, then send a test.'];
        }
        $to = $channel === 'mail' ? trim((string) $owner->email) : Phone::e164((string) $owner->phone_number, $company->country ?? 'UG');
        if ($to === null || $to === '') {
            return ['ok' => false, 'message' => $channel === 'mail'
                ? 'Your account has no email address. Add one in My profile, then send the test again.'
                : 'Your account has no phone number. Add one in My profile, then send the test again.'];
        }

        $name = (string) $company->name;
        $options = match ($channel) {
            'mail' => ['mail' => ['subject' => "Test email from {$name}"]],
            'whatsapp' => ['whatsapp' => ['template' => $template = (string) config('messaging.whatsapp.meta.receipt_template', 'sale_receipt'),
                'params' => [$name, 'TEST-0001', '0', rtrim((string) config('app.url'), '/')]]],
            default => [],
        };
        $text = "This is a test from {$name}. ".ucfirst(self::label($channel)).' is working: receipts and password-reset codes can go out this way.';
        $id = app(Messenger::class)->send($to, $text, [$channel], [
            'company_id' => $company->id, 'user_id' => $owner->id, 'purpose' => 'connection_test', 'options' => $options,
        ], now: true);
        $row = DB::table('message_log')->find($id);

        if ($row?->status === 'sent') {
            return ['ok' => true, 'message' => match ($channel) {
                'mail' => "Test email sent to {$to}. If it is not in the inbox, look in spam.",
                'sms' => "Test SMS sent to {$to}.",
                default => "WhatsApp accepted the test ({$template}) for {$to}.",
            }];
        }

        return ['ok' => false, 'message' => self::plain($channel, (string) ($row?->error ?? ''), $template ?? null)];
    }

    public static function label(string $channel): string
    {
        return ['mail' => 'email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'][$channel] ?? $channel;
    }

    /** A provider's refusal in words the owner can act on. */
    public static function plain(string $channel, string $error, ?string $template = null): string
    {
        $error = trim((string) preg_replace('/^(mail|sms|whatsapp): /', '', $error));
        $has = fn (string ...$needles) => collect($needles)->contains(fn ($n) => stripos($error, $n) !== false);

        if ($channel === 'mail') {
            $server = config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port');

            return match (true) {
                $has('535', 'authenticat', 'credentials', 'password') => 'The mail server refused the username or password.',
                $has('certificate', 'ssl', 'tls', 'crypto') => 'The secure connection to the mail server failed. Try TLS on port 587, or SSL on port 465.',
                $has('connection', 'timed out', 'getaddrinfo', 'network', 'refused', 'resolve') => "Could not reach the mail server {$server}. Check the server name, the port and the encryption.",
                $has('550', '553', '554', 'sender', 'from address') => 'The mail server refused to send from '.config('mail.from.address').'. Use an address that belongs to this mail account.',
                default => 'The email could not be sent: '.self::short($error),
            };
        }

        if ($channel === 'sms') {
            return match (true) {
                $has('not configured') => "Save your Africa's Talking username and API key first.",
                $has('authentication', 'unauthor', '401', 'api key') => "Africa's Talking refused the username or API key. Check them on your Africa's Talking dashboard.",
                $has('InvalidSenderId', 'sender') => "Africa's Talking does not allow this sender id yet. Leave it empty, or ask them to approve it.",
                $has('InsufficientBalance', 'balance') => "Your Africa's Talking balance is too low. Top it up and try again.",
                $has('InvalidPhoneNumber', 'UserInBlacklist', 'blacklist') => "Africa's Talking would not send to your phone number. Check the number in My profile.",
                default => 'The SMS could not be sent: '.self::short($error),
            };
        }

        $json = json_decode($error, true);
        $code = (int) ($json['error']['code'] ?? 0);
        $message = (string) ($json['error']['message'] ?? $error);

        return match (true) {
            $has('not configured') => 'Save your WhatsApp token and phone number id first.',
            $code === 190 || stripos($message, 'access token') !== false => 'WhatsApp refused the token. Make a new permanent token in Meta Business settings.',
            $code === 132001 || stripos($message, 'template') !== false => 'The template "'.($template ?? '').'" is not on your WhatsApp Business account, or is not approved yet.',
            $code === 131030 || stripos($message, 'recipient') !== false => 'WhatsApp will not send to your number yet. Add it as a test recipient in Meta\'s API setup, or use your live number.',
            $code === 100 => 'WhatsApp does not know this phone number id. Copy it again from Meta\'s API setup.',
            default => 'WhatsApp did not send it: '.self::short($message),
        };
    }

    private static function short(string $error): string
    {
        $error = trim((string) preg_replace('/\s+/', ' ', strip_tags($error)));

        return $error === '' ? 'no reason was given.' : mb_strimwidth($error, 0, 180, '…');
    }
}
