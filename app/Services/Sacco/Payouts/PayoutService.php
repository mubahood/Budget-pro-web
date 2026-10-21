<?php

namespace App\Services\Sacco\Payouts;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoPayout;
use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\ReversalService;
use App\Services\Sacco\SaccoAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile-money payouts (04 §11.2, AK-17). create() runs inside the approval's transaction: it writes the payout and
 * posts its "sent" journal (the caller's draft: Dr the loan or savings, Cr 2160), key `payout:{id}:sent`. After the
 * commit, process() asks the provider (idempotent on the payout's key; a retry reuses it); a success posts "settled"
 * (Dr 2160, Cr 1150, `payout:{id}:settled`), a failure reverses the sent journal, and either is passed to the
 * subject's handler (a loan becomes active, or goes back to signed). `unknown` is asked again by sacco:payouts-poll
 * for 24 hours, then left for a person.
 *
 * The driver is config('sacco.payouts.driver'): `off` (the production default until the owner adds keys; MoMo is
 * not offered), `simulated` (local and tests). There is no live driver in this phase (AK-86).
 */
final class PayoutService
{
    public const POLL_HOURS = 24;

    /** @var array<string, callable(SaccoPayout, string): void> per purpose: what a final result means for the subject */
    private static array $handlers = [];

    /** @param  callable(SaccoPayout, string): void  $handler  (payout, successful|failed) inside the result's transaction */
    public static function onResult(string $purpose, callable $handler): void
    {
        self::$handlers[$purpose] = $handler;
    }

    public static function enabled(): bool
    {
        return in_array((string) config('sacco.payouts.driver', 'off'), ['simulated'], true);
    }

    public static function gateway(): PayoutGateway
    {
        return match ((string) config('sacco.payouts.driver', 'off')) {
            'simulated' => new SimulatedPayouts(),
            'off' => throw BusinessRuleException::make('payouts_off', 'Mobile-money payouts are not switched on for this SACCO yet: pay out to savings, cash or bank.'),
            default => throw BusinessRuleException::make('payouts_off', 'That payout provider is not available yet: pay out to savings, cash or bank.'),
        };
    }

    /**
     * Inside the caller's transaction: the payout row and its sent journal (built by $sent from the payout's key).
     *
     * @param  callable(string): JournalDraft  $sent
     */
    public function create(int $companyId, string $purpose, string $subjectType, int $subjectId, ?int $memberId, string $phoneE164, int $amountMinor, ?int $approvalId,
        string $idempotencyKey, callable $sent, ?string $entryDate = null): SaccoPayout
    {
        $gateway = self::gateway();
        if (! $gateway->validateAccount($phoneE164, null)->valid) {
            throw BusinessRuleException::make('invalid_phone', 'That is not a mobile-money number.');
        }
        $existing = SaccoPayout::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $p = SaccoPayout::create(['company_id' => $companyId, 'uuid' => (string) Str::uuid(), 'purpose' => $purpose, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'member_id' => $memberId, 'phone_e164' => $phoneE164, 'amount_minor' => $amountMinor, 'currency' => PostingService::currency($companyId),
            'provider' => $gateway->key(), 'idempotency_key' => $idempotencyKey, 'status' => 'queued', 'approval_id' => $approvalId]);
        $entry = (new PostingService())->post($sent('payout:'.$p->id.':sent')->source('sacco_payouts', (int) $p->id)->dated($entryDate, $entryDate));
        $p->update(['sent_journal_id' => $entry->id]);
        DB::afterCommit(fn () => $this->process((int) $p->id));

        return $p;
    }

    /** Send a queued payout (or ask again about a sent or unknown one) and settle what is final. */
    public function process(int $payoutId): SaccoPayout
    {
        $p = SaccoPayout::withoutGlobalScopes()->findOrFail($payoutId);
        if (in_array($p->status, ['successful', 'failed', 'reversed'], true)) {
            return $p;
        }
        $gateway = self::gateway();
        $result = $p->status === 'queued'
            ? $gateway->send(new PayoutRequest($p->idempotency_key, $p->phone_e164, $p->network, (int) $p->amount_minor, $p->currency, 'Loan '.$p->subject_id))
            : $gateway->status((string) $p->provider_ref);
        $p->update(['status' => $result->status === 'successful' || $result->status === 'failed' ? $p->status : $result->status, 'provider_ref' => $result->providerRef ?? $p->provider_ref,
            'attempts' => (int) $p->attempts + 1, 'sent_at' => $p->sent_at ?? now(), 'last_error' => $result->error]);

        return match ($result->status) {
            'successful', 'failed' => $this->resolve($p->fresh(), $result->status, $result->error),
            default => $p->fresh(),
        };
    }

    /** Ask about every payout still in flight; after POLL_HOURS it waits for a person. @return int resolved */
    public function poll(?int $companyId = null): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $n = 0;
        SaccoPayout::withoutGlobalScopes()->whereIn('status', ['queued', 'sent', 'unknown'])->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')->each(function (SaccoPayout $p) use (&$n) {
                if ($p->sent_at !== null && $p->sent_at->copy()->addHours(self::POLL_HOURS)->isPast()) {
                    $p->update(['last_error' => 'No final answer after '.self::POLL_HOURS.' hours: check with the provider and settle it by hand.']);

                    return;
                }
                $n += in_array($this->process((int) $p->id)->status, ['successful', 'failed'], true) ? 1 : 0;
            });

        return $n;
    }

    private function resolve(SaccoPayout $p, string $status, ?string $error): SaccoPayout
    {
        return DB::transaction(function () use ($p, $status, $error) {
            $p = SaccoPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($p->id);
            if (in_array($p->status, ['successful', 'failed', 'reversed'], true)) {
                return $p;
            }
            $cid = (int) $p->company_id;
            if ($status === 'successful') {
                $mode = (string) SaccoSetting::for($cid)?->mode;
                $entry = (new PostingService())->post((new LoanPostingRules($cid, $mode))->payoutSettled('payout:'.$p->id.':settled',
                    \App\Support\Sacco\Amount::of((int) $p->amount_minor, $p->currency), $p->member_id === null ? null : (int) $p->member_id)->source('sacco_payouts', (int) $p->id));
                $p->update(['status' => 'successful', 'settled_journal_id' => $entry->id, 'resolved_at' => now()]);
            } else {
                $entry = (new ReversalService())->reverse($cid, (int) $p->sent_journal_id, 'Payout failed: '.($error ?: 'refused by the provider'), $p->approval_id === null ? null : (int) $p->approval_id);
                $p->update(['status' => 'failed', 'failed_journal_id' => $entry->id, 'resolved_at' => now(), 'last_error' => $error]);
            }
            SaccoAudit::record('sacco.payout.'.$p->status, $p, $cid, ['purpose' => $p->purpose, 'subject_id' => $p->subject_id], null);
            if (isset(self::$handlers[$p->purpose])) {
                (self::$handlers[$p->purpose])($p, $p->status);
            }

            return $p;
        });
    }
}
