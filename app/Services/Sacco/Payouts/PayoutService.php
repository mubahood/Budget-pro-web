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
 * not offered), `simulated` (local and tests) or `flutterwave` (FlutterwavePayouts, once its keys exist; in tests
 * only against Http::fake, AK-101). retry() asks the provider again with the same key; a payout that never got a
 * final answer is settled by a person who checked with the provider (resolveByHand, a second officer).
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
        return match ((string) config('sacco.payouts.driver', 'off')) {
            'simulated' => true,
            'flutterwave' => FlutterwavePayouts::configured(),
            default => false,
        };
    }

    public static function gateway(): PayoutGateway
    {
        return match ((string) config('sacco.payouts.driver', 'off')) {
            'simulated' => new SimulatedPayouts(),
            'flutterwave' => FlutterwavePayouts::configured() ? new FlutterwavePayouts()
                : throw BusinessRuleException::make('payouts_off', 'Mobile-money payouts are waiting for their provider keys: pay out to savings, cash or bank.'),
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
        if ((bool) DB::table('companies')->where('id', $companyId)->value('is_demo')) {
            // A demo SACCO (its members are made up) never pays anything out, whatever the driver.
            throw BusinessRuleException::make('payouts_off', 'Nothing is paid out from a demo SACCO: pay out to savings, cash or bank.');
        }
        $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);
        if ($company !== null && ! app(\App\Services\Billing\Quotas::class)->featureOn($company, 'sacco_payouts')) {
            throw BusinessRuleException::make('feature_not_in_plan', 'Mobile-money payouts are on the Compliance plan and above: pay out to savings, cash or bank.', ['feature' => 'sacco_payouts']);
        }
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
            ? $gateway->send(new PayoutRequest($p->idempotency_key, $p->phone_e164, $p->network, (int) $p->amount_minor, $p->currency, self::narration($p)))
            : $gateway->status((string) $p->provider_ref);
        $p->update(['status' => $result->status === 'successful' || $result->status === 'failed' ? $p->status : $result->status, 'provider_ref' => $result->providerRef ?? $p->provider_ref,
            'attempts' => (int) $p->attempts + 1, 'sent_at' => $p->sent_at ?? now(), 'last_error' => $result->error]);

        return match ($result->status) {
            'successful', 'failed' => $this->resolve($p->fresh(), $result->status, $result->error),
            default => $p->fresh(),
        };
    }

    /**
     * Ask the provider again now (an officer with sacco_disburse): a queued payout is sent, a sent or unknown one is
     * asked about, always with the same idempotency key, so a retry never pays twice. A final one is refused.
     */
    public function retry(\App\Models\Company $company, int $payoutId, \App\Models\User $by): SaccoPayout
    {
        \App\Services\Sacco\SaccoAccess::authorize($by, 'sacco_disburse');
        $p = SaccoPayout::withoutGlobalScopes()->where('company_id', $company->id)->find($payoutId)
            ?? throw BusinessRuleException::make('payout_not_found', 'That payout is not in this SACCO.');
        if (in_array($p->status, ['successful', 'failed', 'reversed'], true)) {
            throw BusinessRuleException::make('payout_closed', "This payout is {$p->status}: nothing to retry.");
        }
        SaccoAudit::record('sacco.payout.retried', $p, (int) $company->id, ['status' => $p->status], (int) $by->id);

        return $this->process((int) $p->id);
    }

    /**
     * A payout the provider never answered, settled by a second officer who checked with the provider (sacco_approve,
     * never the officer who asked for the payout): `successful` posts the settled journal, `failed` reverses the sent one.
     */
    public function resolveByHand(\App\Models\Company $company, int $payoutId, \App\Models\User $by, string $status, string $note): SaccoPayout
    {
        \App\Services\Sacco\SaccoAccess::authorize($by, 'sacco_approve');
        $p = SaccoPayout::withoutGlobalScopes()->where('company_id', $company->id)->find($payoutId)
            ?? throw BusinessRuleException::make('payout_not_found', 'That payout is not in this SACCO.');
        if (! in_array($status, ['successful', 'failed'], true) || trim($note) === '') {
            throw BusinessRuleException::make('invalid_resolution', 'Say whether the money arrived (successful or failed) and what the provider said.');
        }
        if (in_array($p->status, ['successful', 'failed', 'reversed'], true)) {
            throw BusinessRuleException::make('payout_closed', "This payout is already {$p->status}.");
        }
        if ($p->sent_at === null || $p->sent_at->copy()->addHours(self::POLL_HOURS)->isFuture()) {
            throw BusinessRuleException::make('payout_in_flight', 'The provider is still being asked: wait a day before settling it by hand.');
        }
        $maker = $p->approval_id === null ? null : \App\Models\SaccoApproval::withoutGlobalScopes()->whereKey($p->approval_id)->value('requested_by_id');
        \App\Services\Sacco\SaccoAccess::assertChecker($by, $maker === null ? null : (int) $maker);
        SaccoAudit::record('sacco.payout.resolved_by_hand', $p, (int) $company->id, ['status' => $status, 'note' => mb_substr($note, 0, 200)], (int) $by->id);

        return $this->resolve($p, $status, $status === 'failed' ? 'Settled by hand: '.mb_substr($note, 0, 200) : null);
    }

    /** What the member sees on their wallet's message: the SACCO's name and what the money is. */
    public static function narration(SaccoPayout $p): string
    {
        $name = (string) (SaccoSetting::for((int) $p->company_id)?->short_name ?: 'SACCO');

        return $name.' '.match ($p->purpose) {
            'loan_disbursement' => 'loan',
            'withdrawal' => 'withdrawal',
            'dividend' => 'dividend',
            'shareout' => 'share-out',
            default => 'payment',
        };
    }

    /** @return array<string, mixed> the API's view of a payout */
    public static function present(SaccoPayout $p): array
    {
        return ['id' => (int) $p->id, 'uuid' => $p->uuid, 'purpose' => $p->purpose, 'status' => $p->status, 'subject_type' => $p->subject_type, 'subject_id' => $p->subject_id === null ? null : (int) $p->subject_id,
            'member_id' => $p->member_id === null ? null : (int) $p->member_id, 'phone' => $p->phone_e164, 'network' => $p->network, 'amount_minor' => (int) $p->amount_minor,
            'fee_minor' => (int) $p->fee_minor, 'currency' => $p->currency, 'provider' => $p->provider, 'provider_ref' => $p->provider_ref, 'attempts' => (int) $p->attempts,
            'last_error' => $p->last_error, 'approval_id' => $p->approval_id === null ? null : (int) $p->approval_id, 'sent_journal_id' => $p->sent_journal_id === null ? null : (int) $p->sent_journal_id,
            'settled_journal_id' => $p->settled_journal_id === null ? null : (int) $p->settled_journal_id, 'failed_journal_id' => $p->failed_journal_id === null ? null : (int) $p->failed_journal_id,
            'sent_at' => $p->sent_at?->toIso8601String(), 'resolved_at' => $p->resolved_at?->toIso8601String(),
            'needs_person' => in_array($p->status, ['queued', 'sent', 'unknown'], true) && $p->sent_at !== null && $p->sent_at->copy()->addHours(self::POLL_HOURS)->isPast()];
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
