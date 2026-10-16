<?php

namespace App\Notifications;

use App\Models\StandaloneLicence;
use App\Notifications\Channels\MessagingMailChannel;
use App\Support\BuyerLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Your Budget Pro download is ready" (docs/STANDALONE_EDITION_PLAN.md §11): sent after a verified
 * purchase or renewal, a licence issued by hand, or when the buyer asks for their link again.
 *
 * Queued, and the private link is made in toText(), which runs when the email is actually sent — so
 * a slow queue never delivers a link that has already expired (True-Doctor lesson 9). Plain text,
 * through the app's own mail channel (MessagingMailChannel → Messenger's mail or log driver).
 */
class StandaloneDownloadReady extends Notification implements ShouldQueue
{
    use Queueable;

    public const REASONS = ['purchased', 'renewed', 'issued', 'link'];

    public int $tries = 3;

    public function __construct(public readonly string $licenceId, public readonly string $reason = 'link')
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return [MessagingMailChannel::class];
    }

    /** @return array{subject:string,body:string,link:string}|null null when the licence is gone */
    public function toText(object $notifiable): ?array
    {
        $licence = StandaloneLicence::query()->find($this->licenceId);
        if ($licence === null) {
            return null;
        }
        $link = BuyerLink::make($licence->id);
        $portal = substr($link, 0, (int) strpos($link, BuyerLink::PATH)).'/my-licence';
        $until = $licence->updates_until->format('j F Y');

        $opening = match ($this->reason) {
            'purchased' => "Thank you. Your payment is confirmed and the Budget Pro licence for {$licence->customer} is ready.",
            'renewed' => "Thank you. Your payment is confirmed: new Budget Pro releases are now included until {$until}.",
            'issued' => "Your Budget Pro licence for {$licence->customer} has been issued.",
            default => 'Here is the private link to your Budget Pro downloads, as you asked.',
        };

        $body = implode("\n", [
            'Hello '.($licence->contact_name ?: $licence->customer).',',
            '',
            $opening,
            '',
            'Open your private download page:',
            $link,
            '',
            'It has the installation file, your licence file (licence.json) and the step-by-step setup guide, together.',
            '',
            'Licensed address: '.$licence->domain(),
            'New releases included until: '.$until,
            '',
            'The link works for '.BuyerLink::HOURS.' hours. For a new one, open '.$portal.' and enter this email address.',
            '',
            'Budget Pro',
        ]);

        return ['subject' => 'Your Budget Pro download is ready', 'body' => $body, 'link' => $link];
    }
}
