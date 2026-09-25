<?php

namespace App\Jobs;

use App\Services\Messaging\Messenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $logId, public array $channels, public array $meta = [])
    {
    }

    public function handle(Messenger $messenger): void
    {
        if (! $messenger->deliver($this->logId, $this->channels, $this->meta)) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
