<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Mail;

/** Email through Laravel's mailer. */
class MailChannel implements MessagingChannel
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(string $to, string $text, array $options = []): array
    {
        try {
            Mail::raw($text, function ($m) use ($to, $options) {
                $m->to($to)->subject($options['subject'] ?? config('app.name'));
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
