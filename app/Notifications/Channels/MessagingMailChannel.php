<?php

namespace App\Notifications\Channels;

use App\Models\StandaloneLicence;
use App\Services\Messaging\LogChannel;
use App\Services\Messaging\Messenger;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Sends a notification's toText() through the app's own email channel (config messaging.mail.driver:
 * `mail` → Laravel's mailer, `log` → the log only, as when no SMTP is configured), and records it in
 * message_log like every other message — with the private link left out of the stored copy.
 */
class MessagingMailChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $to = (string) ($notifiable->routeNotificationFor('mail', $notification) ?? '');
        $message = method_exists($notification, 'toText') ? $notification->toText($notifiable) : null;
        if ($to === '' || $message === null) {
            return;
        }

        $channel = Messenger::channel('mail') ?? new LogChannel('mail');
        // `options` from the notification: which help line ends the email (MailChannel's support_*).
        $result = $channel->send($to, $message['body'], ['subject' => $message['subject']] + (is_array($message['options'] ?? null) ? $message['options'] : []));

        DB::table('message_log')->insert([
            'channel' => $channel->name(), 'to' => mb_substr($to, 0, 191), 'purpose' => 'standalone_link',
            'body' => str_replace($message['link'] ?? "\0", '(private link)', $message['body']),
            'status' => $result['ok'] ? 'sent' : 'failed', 'provider_id' => $result['provider_id'] ?? null,
            'error' => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? 'failed'), 0, 500),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (! $result['ok']) {
            throw new \RuntimeException('The download email could not be sent: '.($result['error'] ?? 'failed'));
        }
        if (property_exists($notification, 'licenceId')) {
            StandaloneLicence::query()->whereKey($notification->licenceId)->update(['last_link_sent_at' => now()]);
        }
    }
}
