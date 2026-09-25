<?php

namespace App\Services\Messaging;

interface MessagingChannel
{
    /** @return array{ok: bool, provider_id?: string|null, error?: string|null} */
    public function send(string $to, string $text, array $options = []): array;

    public function name(): string;
}
