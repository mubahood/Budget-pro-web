<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

/** SMS via Africa's Talking (H4). */
class AfricasTalkingSms implements MessagingChannel
{
    public function name(): string
    {
        return 'sms';
    }

    public function send(string $to, string $text, array $options = []): array
    {
        $cfg = config('messaging.sms.africastalking');
        if (empty($cfg['username']) || empty($cfg['api_key'])) {
            return ['ok' => false, 'error' => 'Africa\'s Talking is not configured'];
        }
        $res = Http::asForm()->withHeaders(['apiKey' => $cfg['api_key'], 'Accept' => 'application/json'])->timeout(15)
            ->post($cfg['endpoint'], array_filter(['username' => $cfg['username'], 'to' => $to, 'message' => $text, 'from' => $cfg['sender_id']]));
        $r = $res->json('SMSMessageData.Recipients.0');
        $ok = $res->successful() && in_array($r['statusCode'] ?? 0, [100, 101, 102], true);

        return ['ok' => $ok, 'provider_id' => $r['messageId'] ?? null, 'error' => $ok ? null : ($r['status'] ?? $res->body())];
    }
}
