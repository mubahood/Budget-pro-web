<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/** Development/test driver: nothing leaves the server, the message is only logged. */
class LogChannel implements MessagingChannel
{
    public function __construct(private readonly string $as = 'log')
    {
    }

    public function name(): string
    {
        return $this->as;
    }

    public function send(string $to, string $text, array $options = []): array
    {
        Log::info("[messaging:{$this->as}] to {$to}: ".str_replace("\n", ' | ', $text));

        return ['ok' => true, 'provider_id' => 'log-'.uniqid()];
    }
}
