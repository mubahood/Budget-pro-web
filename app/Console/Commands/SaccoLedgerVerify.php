<?php

namespace App\Console\Commands;

use App\Models\SaccoLedgerHead;
use App\Services\Notifications\Notifier;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\SaccoAudit;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nightly at 03:10 (ADR 0009 §7): prove every SACCO's books are sound. A failure goes to the SACCO's owner and
 * managers (in the app), to the platform (System health's error list and the log) and into the audit trail; the
 * latest results are on System health, with whether the immutability triggers are installed.
 */
class SaccoLedgerVerify extends Command
{
    protected $signature = 'sacco:ledger-verify {--company= : Check one SACCO only}';

    protected $description = 'Check every SACCO ledger: balanced, caches equal their lines, sub-ledgers equal their controls, hash chain intact';

    /** The cache key System health reads. */
    public const CACHE_KEY = 'sacco:ledger-verify';

    /** The notification type the owner sees. */
    public const NOTICE = 'sacco_ledger';

    public function handle(LedgerVerifier $verifier, Notifier $notifier): int
    {
        $ids = $this->option('company') !== null ? [(int) $this->option('company')]
            : SaccoLedgerHead::withoutGlobalScopes()->orderBy('company_id')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
        $failed = [];
        $warned = 0;
        foreach ($ids as $cid) {
            $r = $verifier->verify($cid);
            if (! $r['ok']) {
                $bad = LedgerVerifier::failed($r);
                $failed[] = ['company_id' => $cid, 'checks' => $bad, 'first_broken_entry' => $r['first_broken_entry']];
                $detail = implode(' ', array_map(fn ($k) => $r['checks'][$k]['detail'], $bad));
                $this->error("SACCO #{$cid}: ".implode(', ', $bad).". {$detail}");
                Log::critical('[sacco] ledger check failed', ['company_id' => $cid, 'checks' => $bad, 'first_broken_entry' => $r['first_broken_entry']]);
                ErrorTracker::capture(new \RuntimeException("SACCO ledger check failed for company {$cid}: ".implode(', ', $bad)));
                SaccoAudit::record('sacco.ledger.verify_failed', 'sacco_ledger', $cid, ['failed' => $bad, 'first_broken_entry' => $r['first_broken_entry']], null, $cid);
                $notifier->notify($cid, self::NOTICE, 'The books failed their nightly check',
                    'Akiba Ledger found a problem in your books ('.implode(', ', $bad).'). Months cannot close until it is put right. Support has been told.');
            } else {
                $warned += (int) ($r['status'] === 'warn');
                $this->line("SACCO #{$cid}: {$r['status']}.");
            }
        }
        $summary = ['at' => now()->toIso8601String(), 'companies' => count($ids), 'failed' => $failed, 'warned' => $warned, 'triggers' => LedgerVerifier::triggers()];
        if ($this->option('company') === null) {
            Cache::forever(self::CACHE_KEY, $summary);
        }
        $this->info(count($ids).' SACCO ledger(s) checked; '.count($failed).' failed; triggers '.$summary['triggers'].'.');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
