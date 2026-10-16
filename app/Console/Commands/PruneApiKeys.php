<?php

namespace App\Console\Commands;

use App\Http\Middleware\IdempotentRequests;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily, on both editions (MOBILE_EXCELLENCE_PLAN.md D5, D7): stored Idempotency-Key answers past their
 * 24 hours, and "open on the web" links a day after they expired (kept that long to explain a refusal).
 */
class PruneApiKeys extends Command
{
    protected $signature = 'api:prune-keys';

    protected $description = 'Remove expired Idempotency-Key answers and used or expired web links';

    public function handle(): int
    {
        $keys = Schema::hasTable(IdempotentRequests::TABLE)
            ? DB::table(IdempotentRequests::TABLE)->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere(fn ($w) => $w->whereNull('status')->where('created_at', '<', now()->subDay())))->delete()
            : 0;
        $links = Schema::hasTable('web_link_tokens')
            ? DB::table('web_link_tokens')->where('expires_at', '<', now()->subDay())->delete()
            : 0;
        $this->info("Removed {$keys} idempotency answer(s) and {$links} web link(s).");

        return self::SUCCESS;
    }
}
