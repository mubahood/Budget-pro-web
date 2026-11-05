<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closed means closed (the review's PB-06, decision D5). An event closes when its status stops being Active; `closed_at`
 * says when (stamp()). From then on the models and ContributionPayments refuse pledges, payments, reversals and budget
 * changes, whoever asks: the web, the API, the classic forms. Closing never changes a figure.
 *
 * A phone may have received money before the close and only now have signal, and money already received must never be
 * refused. So SyncApplier asks refusesSyncOp() first, by when the op was made: before the close it is applied (inside
 * allowing()), later it is rejected with event_closed, and the phone tells its user. An op that does not say when it
 * was made is applied: only a phone sends it, and losing work is the worse mistake.
 */
final class EventClosure
{
    public const CODE = 'event_closed';

    public const MESSAGE = 'This event is closed. Ask the chair to reopen it.';

    /** What may change on a closed event's own row: closing and reopening, and what the system keeps up to date. */
    private const NOT_A_CHANGE = ['status', 'is_active', 'closed_at', 'updated_at', 'version', 'client_updated_at', 'server_seq', 'device_id',
        'budget_total', 'budget_spent', 'budget_balance', 'total_expected', 'total_collected', 'total_in_pledge'];

    private static int $allowed = 0;

    /**
     * Run work already judged allowed on a closed event (a phone's op made before the close).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function allowing(callable $work): mixed
    {
        self::$allowed++;
        try {
            return $work();
        } finally {
            self::$allowed--;
        }
    }

    public static function refusal(): BusinessRuleException
    {
        return BusinessRuleException::make(self::CODE, self::MESSAGE);
    }

    /** Refuses a write on a closed event; an unknown event is for the caller's own checks to answer. */
    public static function assertOpen(int|string|null $programId): void
    {
        if (self::$allowed > 0 || empty($programId)) {
            return;
        }
        if (! EventBook::isOpen(DB::table('budget_programs')->where('id', (int) $programId)->value('status'))) {
            throw self::refusal();
        }
    }

    /** Called as an event is saved: closed_at is set when it closes and cleared when it reopens. */
    public static function stamp(BudgetProgram $program): void
    {
        if (EventBook::isOpen($program->status)) {
            $program->closed_at = null;
        } elseif (! $program->exists || ($program->isDirty('status') && EventBook::isOpen($program->getOriginal('status')))) {
            $program->closed_at = now();
        }
    }

    /** A closed event's own details do not change either, until it is reopened. */
    public static function assertEditable(BudgetProgram $program): void
    {
        if (self::$allowed > 0 || EventBook::isOpen($program->getOriginal('status')) || EventBook::isOpen($program->status)) {
            return;
        }
        if (array_diff(array_keys($program->getDirty()), self::NOT_A_CHANGE) !== []) {
            throw self::refusal();
        }
    }

    /**
     * A pushed op on an event, or on what belongs to it: true when the event is closed and the op was made after that.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $op
     */
    public static function refusesSyncOp(int $companyId, string $table, array $data, string $uuid, array $op): bool
    {
        foreach (self::programsOfOp($companyId, $table, $data, $uuid) as $programId) {
            if (self::closedFor($companyId, $programId, $table, $data, $op)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $op
     */
    private static function closedFor(int $companyId, int $programId, string $table, array $data, array $op): bool
    {
        $event = DB::table('budget_programs')->where('company_id', $companyId)->where('id', $programId)->first(['status', 'closed_at', 'updated_at']);
        if ($event === null || EventBook::isOpen($event->status)) {
            return false;
        }
        if ($table === 'budget_programs' && array_key_exists('status', $data) && EventBook::isOpen((string) $data['status'])
            && (! is_array($op['changed_fields'] ?? null) || in_array('status', $op['changed_fields'], true))) {
            return false; // reopening it (manage_budget was checked before this)
        }
        $madeAt = (int) ($op['client_created_at'] ?? $op['client_updated_at'] ?? 0);
        $closedAt = $event->closed_at ?? $event->updated_at; // closed before closed_at existed, or by a tool that sets the status alone

        return $madeAt > 0 && $closedAt !== null && $madeAt >= Carbon::parse($closedAt)->getTimestampMs();
    }

    /**
     * Every event an op touches: the one it names and the one its row is on, which a pushed op could make differ.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function programsOfOp(int $companyId, string $table, array $data, string $uuid): array
    {
        $by = fn (string $from, mixed $key) => is_string($key) && $key !== ''
            ? DB::table($from)->where('company_id', $companyId)->where('uuid', $key)->value($from === 'budget_programs' ? 'id' : 'budget_program_id') : null;
        $ids = match ($table) {
            'budget_programs' => [$by('budget_programs', $uuid)],
            'contribution_records' => [$by('budget_programs', $data['program_uuid'] ?? null), $by('contribution_records', $uuid)],
            'contribution_payments' => [$by('contribution_payments', $data['reverses_uuid'] ?? null), $by('contribution_records', $data['contribution_uuid'] ?? null)],
            'budget_item_categories' => [$by('budget_programs', $data['program_uuid'] ?? null), $by('budget_item_categories', $uuid)],
            'budget_payments' => [$by('budget_payments', $data['reverses_uuid'] ?? null), $by('budget_items', $data['item_uuid'] ?? null), $by('budget_programs', $data['program_uuid'] ?? null)],
            'budget_items' => [$by('budget_item_categories', $data['category_uuid'] ?? null), $by('budget_programs', $data['program_uuid'] ?? null), $by('budget_items', $uuid)],
            default => [],
        };

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }
}
