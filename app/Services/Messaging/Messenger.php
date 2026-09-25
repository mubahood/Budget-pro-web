<?php

namespace App\Services\Messaging;

use App\Jobs\SendMessageJob;
use Illuminate\Support\Facades\DB;

/**
 * One way to reach a person (plan C7): picks channels in order of preference
 * (WhatsApp → SMS for phones, mail for emails), logs every attempt in
 * message_log, and runs through the database queue unless asked to send now.
 */
class Messenger
{
    public static function channel(string $kind): ?MessagingChannel
    {
        $driver = config("messaging.{$kind}.driver", 'log');

        return match ([$kind, $driver]) {
            ['sms', 'africastalking'] => new AfricasTalkingSms(),
            ['whatsapp', 'meta'] => new MetaWhatsApp(),
            ['mail', 'mail'] => new MailChannel(),
            [$kind, 'log'] => new LogChannel($kind),
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $channels  preference order, e.g. ['whatsapp','sms'] or ['mail']
     */
    public function send(string $to, string $text, array $channels, array $meta = [], bool $now = false): int
    {
        $id = DB::table('message_log')->insertGetId([
            'company_id' => $meta['company_id'] ?? null, 'user_id' => $meta['user_id'] ?? null, 'channel' => $channels[0] ?? 'log',
            'to' => $to, 'purpose' => $meta['purpose'] ?? null, 'body' => $text, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($now || config('queue.default') === 'sync') {
            $this->deliver($id, $channels, $meta);
        } else {
            SendMessageJob::dispatch($id, $channels, $meta);
        }

        return $id;
    }

    public function deliver(int $logId, array $channels, array $meta = []): bool
    {
        /** @var object{status: string, to: string, body: string}|null $row */
        $row = DB::table('message_log')->find($logId);
        if ($row === null || in_array($row->status, ['sent', 'cancelled'], true)) {
            return true; // nothing (more) to deliver
        }
        $errors = [];
        foreach ($channels as $kind) {
            $ch = self::channel($kind);
            if ($ch === null) {
                continue;
            }
            $r = $ch->send($row->to, $row->body, $meta['options'][$kind] ?? []);
            if ($r['ok']) {
                DB::table('message_log')->where('id', $logId)->update(['channel' => $kind, 'status' => 'sent', 'provider_id' => $r['provider_id'] ?? null, 'error' => null, 'updated_at' => now()]);

                return true;
            }
            $errors[] = $kind.': '.($r['error'] ?? 'failed');
        }
        DB::table('message_log')->where('id', $logId)->update(['status' => $errors === [] ? 'skipped' : 'failed', 'error' => mb_substr(implode('; ', $errors), 0, 500), 'updated_at' => now()]);

        return false;
    }

    /** Channels for a person: phones get WhatsApp then SMS, otherwise email. */
    public static function channelsFor(?string $phone, ?string $email): array
    {
        return $phone ? ['whatsapp', 'sms'] : ($email ? ['mail'] : []);
    }
}
