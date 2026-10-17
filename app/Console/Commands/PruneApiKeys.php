<?php

namespace App\Console\Commands;

use App\Http\Middleware\IdempotentRequests;
use App\Services\Auth\OneTimeToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily, on both editions (MOBILE_EXCELLENCE_PLAN.md D5, D7): stored Idempotency-Key answers past their
 * 24 hours, and "open on the web" links, reset and sign-up tokens a day after they expired (kept that long to explain a refusal),
 * and expired rows of the database cache (the attempt limits' counters).
 */
class PruneApiKeys extends Command
{
    protected $signature = 'api:prune-keys';

    protected $description = 'Remove expired Idempotency-Key answers, web links, reset or sign-up tokens and cache rows';

    public function handle(): int
    {
        $keys = Schema::hasTable(IdempotentRequests::TABLE)
            ? DB::table(IdempotentRequests::TABLE)->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere(fn ($w) => $w->whereNull('status')->where('created_at', '<', now()->subDay())))->delete()
            : 0;
        $links = Schema::hasTable('web_link_tokens')
            ? DB::table('web_link_tokens')->where('expires_at', '<', now()->subDay())->delete()
            : 0;
        $tokens = Schema::hasTable(OneTimeToken::TABLE) ? OneTimeToken::prune() : 0;
        // Attempt-limit counters (the `limiter` cache store) past their minute: the store only drops a row it reads again.
        $counters = Schema::hasTable('cache') ? DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete() : 0;
        $this->info("Removed {$keys} idempotency answer(s), {$links} web link(s), {$tokens} reset or sign-up token(s) and {$counters} expired cache row(s).");

        return self::SUCCESS;
    }
}
