<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

/** WhatsApp via Meta Cloud API (H4). OTPs use an approved template; other text as a session message. */
class MetaWhatsApp implements MessagingChannel
{
    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(string $to, string $text, array $options = []): array
    {
        $cfg = config('messaging.whatsapp.meta');
        if (empty($cfg['token']) || empty($cfg['phone_number_id'])) {
            return ['ok' => false, 'error' => 'WhatsApp is not configured'];
        }
        $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+')];
        if (! empty($options['template'])) {
            $payload['type'] = 'template';
            $payload['template'] = ['name' => $options['template'], 'language' => ['code' => $options['language'] ?? 'en'],
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $options['params'] ?? [])]]];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $text, 'preview_url' => false];
        }
        $res = Http::withToken($cfg['token'])->timeout(15)->post("https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}/messages", $payload);

        return ['ok' => $res->successful(), 'provider_id' => $res->json('messages.0.id'), 'error' => $res->successful() ? null : $res->body()];
    }
}
