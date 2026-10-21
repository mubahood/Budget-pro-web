<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoApprovalVote;
use App\Models\SaccoPeriod;
use App\Models\User;
use App\Services\Sacco\Approvals\ApprovalExecutor;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The maker-checker engine (docs/akiba/04-architecture.md §7, AK-22). An officer asks (request), another decides
 * (approve or reject), the maker may take it back (cancel); once enough checkers approve, the request executes
 * through its action's executor. Every request:
 *
 *   - freezes its payload and the payload's hash: what the checker sees is what executes, and a payload changed
 *     after it was asked (even in the database) is refused (`payload_tampered`);
 *   - is decided by someone other than its maker, the owner included (`self_approval`); only a group in
 *     single-officer mode may approve its own, and that is flagged on the request and its vote;
 *   - expires after 7 days, and an expired request never executes (`approval_expired`);
 *   - executes once: executing again returns the first result, and executors post with the key `apr:{uuid}`.
 *
 * If execution is refused (the money is no longer there), the request stays approved, not executed, with the
 * reason, and can be executed again once the reason is gone.
 */
final class SaccoApprovals
{
    public const EXPIRY_DAYS = 7;

    /** Actions the system itself may ask for, with no officer as maker (a receipt held by sync, AK-24). */
    public const SYSTEM_ACTIONS = ['held_receipt_allocation'];

    /** @var array<string, ApprovalExecutor> */
    private static array $executors = [];

    public static function register(ApprovalExecutor $executor): void
    {
        self::$executors[$executor->action()] = $executor;
    }

    public static function executor(string $action): ApprovalExecutor
    {
        return self::$executors[$action] ?? throw BusinessRuleException::make('unknown_action', "\"{$action}\" is not a request the SACCO knows.");
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::$executors);
    }

    /** The hash a request's payload is frozen with (a canonical JSON, keys sorted). */
    public static function hash(string $action, array $payload): string
    {
        return hash('sha256', $action.'|'.json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Ask. The uuid is the request's idempotency key: asking again with the same uuid and payload returns the
     * first request; with another payload it is `idempotency_conflict`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(Company $company, string $action, array $payload, ?User $maker, ?string $uuid = null): SaccoApproval
    {
        $executor = self::executor($action);
        if ($maker !== null && $executor->makerPermission() !== null) {
            SaccoAccess::authorize($maker, $executor->makerPermission());
        } elseif ($maker === null && ! in_array($action, self::SYSTEM_ACTIONS, true)) {
            throw BusinessRuleException::make('forbidden', 'An officer must make this request.', ['permission' => $executor->makerPermission()]);
        }
        $cid = (int) $company->id;
        $prepared = $executor->prepare($cid, $payload);
        $frozen = self::canonical($prepared['payload']);
        $hash = self::hash($action, $frozen);
        $uuid = $uuid !== null && Str::isUuid($uuid) ? strtolower($uuid) : (string) Str::uuid();

        return DB::transaction(function () use ($cid, $action, $executor, $prepared, $frozen, $hash, $uuid, $maker) {
            $existing = SaccoApproval::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $hash || $existing->action !== $action) {
                    throw BusinessRuleException::make('idempotency_conflict', 'That request was already made with other details.', ['approval_id' => $existing->id]);
                }

                return $existing;
            }
            $approval = SaccoApproval::create(['company_id' => $cid, 'uuid' => $uuid, 'action' => $action, 'subject_type' => $prepared['subject_type'],
                'subject_id' => $prepared['subject_id'], 'payload' => $frozen, 'payload_hash' => $hash, 'amount_minor' => $prepared['amount_minor'],
                'required_approvals' => max(1, $executor->requiredApprovals($frozen)), 'status' => 'pending', 'requested_by_id' => $maker?->id,
                'requested_at' => now(), 'requested_on' => LocalDate::today($cid)->toDateString(), 'expires_at' => now()->addDays(self::EXPIRY_DAYS)]);
            SaccoAudit::record('sacco.approval.requested', $approval, $cid, ['action' => $action, 'amount_minor' => $prepared['amount_minor']], $maker?->id);

            return $approval;
        });
    }

    /**
     * Approve: a checker with the action's permission (and office), never the maker. When the last needed checker
     * approves, the request executes at once; if execution is refused, it stays approved with the reason and the
     * refusal is thrown.
     */
    public function approve(Company $company, int $approvalId, User $checker, ?string $note = null): SaccoApproval
    {
        $approval = $this->expiring($company, $approvalId, fn () => DB::transaction(function () use ($company, $approvalId, $checker, $note) {
            $a = $this->lockOpen($company, $approvalId, ['pending']);
            $executor = self::executor($a->action);
            $self = SaccoAccess::assertChecker($checker, $a->requested_by_id === null ? null : (int) $a->requested_by_id, $executor->checkerPermission());
            if ($executor instanceof Approvals\ChecksChecker) {
                $executor->assertCanCheck($a, $checker, 'approve');
            }
            if ($executor->checkerPositions() !== [] && ! SaccoAccess::holds($checker, $executor->checkerPositions())) {
                throw BusinessRuleException::make('position_required', 'This needs an officer holding one of these offices: '.implode(', ', array_map(
                    fn ($p) => \App\Models\SaccoOfficer::LABELS[$p] ?? $p, $executor->checkerPositions())).'.', ['positions' => $executor->checkerPositions()]);
            }
            if (SaccoApprovalVote::withoutGlobalScopes()->where('approval_id', $a->id)->where('user_id', $checker->id)->exists()) {
                throw BusinessRuleException::make('already_voted', 'You have already decided on this request.');
            }
            SaccoApprovalVote::create(['company_id' => $a->company_id, 'approval_id' => $a->id, 'user_id' => $checker->id, 'decision' => 'approve',
                'note' => $note === null ? null : mb_substr($note, 0, 255), 'self_approved' => $self,
                'officer_id' => \App\Models\SaccoOfficer::withoutGlobalScopes()->where('company_id', $a->company_id)->where('user_id', $checker->id)->activeOn(LocalDate::today((int) $a->company_id))->value('id')]);
            $approvals = SaccoApprovalVote::withoutGlobalScopes()->where('approval_id', $a->id)->where('decision', 'approve')->count();
            if ($approvals >= $a->required_approvals) {
                $a->update(['status' => 'approved', 'decided_at' => now(), 'decided_by_id' => $checker->id, 'decision_note' => $note === null ? null : mb_substr($note, 0, 255),
                    'self_approved' => $a->self_approved || $self]);
            }
            SaccoAudit::record('sacco.approval.approved', $a, (int) $a->company_id, ['action' => $a->action, 'self_approved' => $self, 'approvals' => $approvals], (int) $checker->id);

            return $a;
        }));

        return $approval->status === 'approved' ? $this->execute($company, (int) $approval->id, $checker) : $approval;
    }

    /** Run an approved request (again, after a refusal). Executing an executed request returns it as it is. */
    public function execute(Company $company, int $approvalId, User $by): SaccoApproval
    {
        try {
            return DB::transaction(function () use ($company, $approvalId, $by) {
                $a = SaccoApproval::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->find($approvalId)
                    ?? throw BusinessRuleException::make('approval_not_found', 'That request is not in this SACCO.');
                if ($a->status === 'executed') {
                    return $a;
                }
                if ($a->status !== 'approved') {
                    throw BusinessRuleException::make('approval_not_approved', "This request is {$a->status}.");
                }
                $this->assertIntact($a);
                if ($a->isExpired()) {
                    throw BusinessRuleException::make('approval_expired', 'This request has expired: ask again.');
                }
                $result = self::executor($a->action)->execute($a, $by);
                $a->update(['status' => 'executed', 'executed_at' => now(), 'executed_entry_id' => $result['entry_id'] ?? null, 'result' => $result, 'last_error' => null]);
                SaccoAudit::record('sacco.approval.executed', $a, (int) $a->company_id, ['action' => $a->action, 'result' => $result], (int) $by->id);

                return $a;
            });
        } catch (BusinessRuleException $e) {
            $this->noteFailure($company, $approvalId, $e);
            throw $e;
        }
    }

    public function reject(Company $company, int $approvalId, User $checker, string $note): SaccoApproval
    {
        if (blank($note)) {
            throw BusinessRuleException::make('reason_required', 'Say why it is rejected: the officer who asked is told.');
        }

        return $this->expiring($company, $approvalId, fn () => DB::transaction(function () use ($company, $approvalId, $checker, $note) {
            $a = $this->lockOpen($company, $approvalId, ['pending']);
            SaccoAccess::assertChecker($checker, $a->requested_by_id === null ? null : (int) $a->requested_by_id, self::executor($a->action)->checkerPermission());
            if (self::executor($a->action) instanceof Approvals\ChecksChecker) {
                self::executor($a->action)->assertCanCheck($a, $checker, 'reject');
            }
            SaccoApprovalVote::create(['company_id' => $a->company_id, 'approval_id' => $a->id, 'user_id' => $checker->id, 'decision' => 'reject', 'note' => mb_substr($note, 0, 255)]);
            $a->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by_id' => $checker->id, 'decision_note' => mb_substr($note, 0, 255)]);
            self::closeSubject($a, 'rejected');
            SaccoAudit::record('sacco.approval.rejected', $a, (int) $a->company_id, ['action' => $a->action, 'note' => $note], (int) $checker->id);

            return $a;
        }));
    }

    /** The maker takes their request back (before it executes). */
    public function cancel(Company $company, int $approvalId, User $maker): SaccoApproval
    {
        return $this->expiring($company, $approvalId, fn () => DB::transaction(function () use ($company, $approvalId, $maker) {
            $a = $this->lockOpen($company, $approvalId, ['pending', 'approved']);
            if ($a->requested_by_id === null || (int) $a->requested_by_id !== (int) $maker->id) {
                throw BusinessRuleException::make('forbidden', 'Only the officer who asked can take a request back.');
            }
            $a->update(['status' => 'cancelled', 'decided_at' => now(), 'decided_by_id' => $maker->id]);
            self::closeSubject($a, 'cancelled');
            SaccoAudit::record('sacco.approval.cancelled', $a, (int) $a->company_id, ['action' => $a->action], (int) $maker->id);

            return $a;
        }));
    }

    /** Mark every request past its expiry as expired (hourly). @return int how many */
    public function expireDue(?int $companyId = null): int
    {
        $n = 0;
        SaccoApproval::withoutGlobalScopes()->whereIn('status', ['pending', 'approved'])->where('expires_at', '<', now())
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')
            ->each(function (SaccoApproval $a) use (&$n) {
                $a->update(['status' => 'expired']);
                self::closeSubject($a, 'expired');
                SaccoAudit::record('sacco.approval.expired', $a, (int) $a->company_id, ['action' => $a->action], null);
                $n++;
            });

        return $n;
    }

    /** Tell an executor whose subject waits on the request that it will not execute (ClosesSubject). */
    private static function closeSubject(SaccoApproval $a, string $status): void
    {
        $executor = self::$executors[$a->action] ?? null;
        if ($executor instanceof Approvals\ClosesSubject) {
            $executor->closed($a, $status);
        }
    }

    /** Requests still waiting from a month (they block its close, ADR 0009 §8). */
    public static function openIn(int $companyId, SaccoPeriod $period): int
    {
        return SaccoApproval::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['pending', 'approved'])->where('expires_at', '>=', now())
            ->whereBetween('requested_on', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->count();
    }

    /**
     * Run a decision; a request found past its expiry is marked expired once the decision's transaction has rolled
     * back, so the mark sticks and the refusal still reaches the caller.
     *
     * @param  callable(): SaccoApproval  $decide
     */
    private function expiring(Company $company, int $approvalId, callable $decide): SaccoApproval
    {
        try {
            return $decide();
        } catch (BusinessRuleException $e) {
            if ($e->errorCode() === 'approval_expired') {
                $this->expireDue((int) $company->id);
            }
            throw $e;
        }
    }

    /** @param  list<string>  $statuses */
    private function lockOpen(Company $company, int $approvalId, array $statuses): SaccoApproval
    {
        $a = SaccoApproval::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->find($approvalId)
            ?? throw BusinessRuleException::make('approval_not_found', 'That request is not in this SACCO.');
        if (in_array($a->status, $statuses, true) && $a->isExpired()) {
            throw BusinessRuleException::make('approval_expired', 'This request has expired: ask again.'); // marked expired by expiring() after the rollback
        }
        if (! in_array($a->status, $statuses, true)) {
            throw BusinessRuleException::make('approval_closed', "This request is {$a->status}.");
        }
        $this->assertIntact($a);

        return $a;
    }

    /** The stored payload must still hash to what was frozen (AK-22). */
    private function assertIntact(SaccoApproval $a): void
    {
        $raw = json_decode((string) $a->getRawOriginal('payload'), true);
        if (! is_array($raw) || ! hash_equals((string) $a->payload_hash, self::hash($a->action, $raw))) {
            throw BusinessRuleException::make('payload_tampered', 'This request was changed after it was made: it cannot be approved or run. Ask again.');
        }
    }

    private function noteFailure(Company $company, int $approvalId, BusinessRuleException $e): void
    {
        if (in_array($e->errorCode(), ['approval_not_found', 'approval_not_approved'], true)) {
            return;
        }
        $a = SaccoApproval::withoutGlobalScopes()->where('company_id', $company->id)->find($approvalId);
        if ($a === null) {
            return;
        }
        if ($e->errorCode() === 'approval_expired') {
            $this->expireDue((int) $company->id);
        } elseif ($a->status === 'approved') {
            $a->update(['last_error' => mb_substr($e->getMessage(), 0, 255)]);
        }
    }

    /** @return array<mixed> keys sorted at every level, so the same request always hashes the same */
    private static function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return array_map(fn ($v) => is_array($v) ? self::canonical($v) : $v, $payload);
    }
}
