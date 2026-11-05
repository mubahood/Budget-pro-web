<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetPayment;
use App\Models\BudgetProgram;
use App\Models\ContributionPayment;
use App\Models\User;
use App\Services\Sync\SyncRegistry;
use App\Support\Decimal;
use App\Support\LocalDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spending (the review's PB-13, decision D6): the only writer of budget_payments and of a line's invested_amount.
 *
 *  - record():   a payment out. Idempotent on its uuid. On the web, the same reference on the event, or the same line and amount within minutes, asks first.
 *  - reverse():  a mistake put right with a new negative row and a reason; the original is never touched.
 *  - settleTo(): older phones and the classic forms send a line's new figure: posted as a row of the difference (source item_edit).
 *
 * Every write locks the line, appends, then sets invested_amount = SUM(rows) in the same transaction, so two payments at the same moment both count, and
 * every reader of the figure (EventBook, BudgetSummary, EventReports, the phones) goes on reading invested_amount and agrees.
 *
 * Money with no line goes on one real line, "Not on the budget", priced 0. It is an ordinary row everywhere, so it counts in Paid out and In hand on
 * every screen, document and phone with nothing to add up separately. A line removed from a phone or the classic admin takes its money there too.
 */
class BudgetPayments
{
    public const UNBUDGETED = 'Not on the budget';

    public const DUPLICATE_MINUTES = 10;

    public const METHODS = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];

    /** Who is pushing, while a phone's batch is applied (SyncApplier): a line's save has no other way to know. */
    private static ?int $actorId = null;

    private static ?string $actorDevice = null;

    public static function ready(): bool
    {
        return SyncRegistry::columns('budget_payments') !== [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function actingFor(int $userId, ?string $deviceId, callable $work): mixed
    {
        [$user, $device] = [self::$actorId, self::$actorDevice];
        [self::$actorId, self::$actorDevice] = [$userId, $deviceId];
        try {
            return $work();
        } finally {
            [self::$actorId, self::$actorDevice] = [$user, $device];
        }
    }

    /**
     * @param  array{amount: mixed, paid_on?: ?string, method?: ?string, paid_to?: ?string, reference?: ?string, notes?: ?string, uuid?: ?string, device_id?: ?string, client_created_at?: ?int, paid_by_id?: mixed, approved_by_id?: mixed, confirm_duplicate?: bool, budget_program_id?: int}  $data
     * @param  ?int  $itemId  null: not on the budget, and $data names the event in budget_program_id
     * @param  bool  $fromPhone  money already paid out is never refused: not for going past the line, a fraction, a date, a likely duplicate or a removed line
     * @return array{payment: BudgetPayment, item: BudgetItem, replayed: bool}
     */
    public function record(int $companyId, ?int $itemId, array $data, int $userId, bool $fromPhone = false): array
    {
        $amount = $this->positiveAmount($data['amount'] ?? null);
        if (! $fromPhone) {
            ContributionPayments::assertExact($companyId, (string) $data['amount']);
        }
        $method = $this->method($data['method'] ?? null, $fromPhone);
        $paidOn = $this->paidOn($companyId, $data['paid_on'] ?? null, $fromPhone);
        $uuid = $this->uuid($data['uuid'] ?? null);

        return DB::transaction(function () use ($companyId, $itemId, $data, $userId, $fromPhone, $amount, $method, $paidOn, $uuid) {
            if ($uuid !== null && ($replay = $this->replay($companyId, $uuid, $itemId)) !== null) {
                return $replay;
            }
            $item = $itemId !== null ? $this->lockLine($companyId, $itemId) : null;
            if ($itemId !== null && ($item === null || ((int) $item->is_deleted === 1 && ! $fromPhone))) {
                throw BusinessRuleException::make('not_found', 'This budget line was not found.');
            }
            $programId = $item !== null ? (int) $item->budget_program_id : (int) ($data['budget_program_id'] ?? 0);
            if ($programId <= 0 || ! DB::table('budget_programs')->where('company_id', $companyId)->where('id', $programId)->where('is_deleted', 0)->exists()) {
                throw BusinessRuleException::make('not_found', 'This event was not found.');
            }
            EventClosure::assertOpen($programId);
            $notes = $this->text($data['notes'] ?? null, 255);
            if ($item !== null && (int) $item->is_deleted === 1) {
                // A phone paid on a line removed since. The money is real and the budget stays as agreed, so it goes on Not on the budget and the owner is told.
                (new \App\Services\Sync\MasterDataService())->conflict($companyId, $data['device_id'] ?? null, 'budget_payments', $uuid, 'paid_after_removed',
                    ['amount' => $amount, 'line' => (string) $item->name], null, 'Money was paid out on the removed line '.$item->name.', so it is under '.self::UNBUDGETED);
                $notes = $this->text('Paid on the removed line '.$item->name.($notes !== null ? '. '.$notes : ''), 255);
                $item = null;
            }
            $item ??= $this->unbudgetedLine($companyId, $programId, $userId);
            $this->adopt((int) $item->id); // a figure with no history yet is its opening row, before anything joins it
            $reference = $this->text($data['reference'] ?? null, 100);
            if (! $fromPhone && empty($data['confirm_duplicate'])) {
                $this->refuseLikelyDuplicate($item, $amount, $reference);
            }
            $payment = $this->append($item, [
                'uuid' => $uuid, 'amount' => $amount, 'paid_on' => $paidOn, 'method' => $method, 'paid_to' => $this->text($data['paid_to'] ?? null, 120),
                'paid_by_id' => $this->person($companyId, $programId, $data['paid_by_id'] ?? null, $userId, $fromPhone, 'invalid_payer'),
                'approved_by_id' => $this->person($companyId, $programId, $data['approved_by_id'] ?? null, null, $fromPhone, 'invalid_approver'),
                'reference' => $reference, 'notes' => $notes, 'source' => 'payment', 'created_by_id' => $userId,
                'device_id' => $data['device_id'] ?? null, 'client_created_at' => $data['client_created_at'] ?? null,
            ]);

            return ['payment' => $payment, 'item' => self::recalculate($item), 'replayed' => false];
        });
    }

    /** @return array{payment: BudgetPayment, item: BudgetItem, replayed: bool} */
    public function reverse(int $companyId, int $paymentId, string $reason, int $userId, ?string $uuid = null, ?string $deviceId = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::make('reason_required', 'Say why this payment is being reversed.');
        }
        $uuid = $this->uuid($uuid);

        return DB::transaction(function () use ($companyId, $paymentId, $reason, $userId, $uuid, $deviceId) {
            $original = BudgetPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $paymentId)->where('is_deleted', 0)->first();
            if ($original === null) {
                throw BusinessRuleException::make('not_found', 'This payment was not found.');
            }
            if ($uuid !== null && ($replay = $this->replay($companyId, $uuid, (int) $original->budget_item_id)) !== null) {
                return $replay;
            }
            $item = $this->lockLine($companyId, (int) $original->budget_item_id);
            if ($item === null) {
                throw BusinessRuleException::make('not_found', 'This budget line was not found.');
            }
            EventClosure::assertOpen($item->budget_program_id);
            if ($original->reverses_id !== null) {
                throw BusinessRuleException::make('cannot_reverse_reversal', 'A reversal cannot be reversed. Record the payment again instead.');
            }
            if (BudgetPayment::withoutGlobalScopes()->where('reverses_id', $original->id)->exists()) {
                throw BusinessRuleException::make('already_reversed', 'This payment was already reversed.');
            }
            $payment = $this->append($item, [
                'uuid' => $uuid, 'amount' => Decimal::neg((string) $original->amount), 'paid_on' => LocalDate::today($companyId)->format('Y-m-d'), 'method' => $original->method, 'paid_to' => $original->paid_to,
                'paid_by_id' => $original->paid_by_id, 'approved_by_id' => $original->approved_by_id, 'source' => 'reversal', 'reverses_id' => $original->id,
                'reason' => mb_substr($reason, 0, 255), 'created_by_id' => $userId, 'device_id' => $deviceId,
            ]);

            return ['payment' => $payment, 'item' => self::recalculate($item), 'replayed' => false];
        });
    }

    /**
     * Older phones and the classic forms send a line's new figure: post the difference from what the history says (a negative row with a reason when it
     * went down). Null when nothing changed.
     */
    public function settleTo(int $companyId, int $itemId, string $wanted, ?int $userId, ?string $deviceId = null): ?BudgetPayment
    {
        return DB::transaction(function () use ($companyId, $itemId, $wanted, $userId, $deviceId) {
            $item = $this->lockLine($companyId, $itemId);
            if ($item === null) {
                return null;
            }
            $by = self::$actorId ?? $userId;
            $diff = Decimal::sub($wanted, $this->sum($itemId));
            if (Decimal::isZero($diff)) {
                self::recalculate($item);

                return null;
            }
            $payment = $this->append($item, [
                'amount' => $diff, 'paid_on' => LocalDate::today($companyId)->format('Y-m-d'), 'method' => 'other', 'paid_by_id' => $by, 'created_by_id' => $by, 'source' => 'item_edit',
                'reason' => Decimal::cmp($diff, '0') < 0 ? 'The amount paid out was lowered on the line' : null, 'device_id' => $deviceId ?? self::$actorDevice,
            ]);
            self::recalculate($item);

            return $payment;
        });
    }

    /** The legacy endpoints save a line without its events, having already written the figure they were sent: the history catches up here. */
    public function afterQuietSave(BudgetItem $item, ?int $userId): void
    {
        if (self::ready()) {
            $this->settleTo((int) $item->company_id, (int) $item->id, Decimal::of((string) ($item->getRawOriginal('invested_amount') ?? $item->invested_amount ?? 0)), $userId);
        }
    }

    /**
     * invested_amount = SUM(rows); balance, percent and complete from it; then the category and the event. Call with the line locked, inside the caller's
     * transaction. Bumps the sync version only when what was paid changed, so phones see it.
     */
    public static function recalculate(BudgetItem $item): BudgetItem
    {
        $self = new self();
        $self->adopt((int) $item->id);
        $paid = $self->sum((int) $item->id);
        $target = Decimal::of((string) DB::table('budget_items')->where('id', $item->id)->value('target_amount'));
        $balance = Decimal::sub($target, $paid);
        $percent = Decimal::percent($paid, $target);
        $complete = Decimal::cmp($percent, '98') >= 0 ? 'Yes' : 'No';
        $stored = DB::table('budget_items')->where('id', $item->id)->first(['invested_amount']);
        if ($stored === null || Decimal::cmp((string) $stored->invested_amount, $paid) !== 0) {
            $item->invested_amount = $paid;
            $item->balance = $balance;
            $item->setAttribute('percentage_done', $percent);
            $item->is_complete = $complete;
            $item->saveQuietlySynced();
        } else {
            DB::table('budget_items')->where('id', $item->id)->update(['balance' => $balance, 'percentage_done' => $percent, 'is_complete' => $complete]);
        }
        $category = BudgetItemCategory::withoutGlobalScopes()->find($item->budget_item_category_id);
        if ($category !== null) {
            $category->updateSelf();
        } elseif ($item->budget_program_id) {
            BudgetProgram::recalculateFromChildren((int) $item->budget_program_id);
        }

        return $item;
    }

    public function recalculateById(int $companyId, int $itemId): ?BudgetItem
    {
        return DB::transaction(function () use ($companyId, $itemId) {
            $item = $this->lockLine($companyId, $itemId);

            return $item === null ? null : self::recalculate($item);
        });
    }

    /** The sum of a line's rows, exactly ("0.00" without any). */
    public function sum(int $itemId): string
    {
        return Decimal::of((string) DB::table('budget_payments')->where('budget_item_id', $itemId)->where('is_deleted', 0)->selectRaw('COALESCE(SUM(amount), 0) AS s')->value('s'));
    }

    /** A figure with no history (written straight to the table, or from before the ledger) is given its opening row, so it is never lost to a sum of nothing. */
    public function adopt(int $itemId): void
    {
        $line = DB::table('budget_items')->where('id', $itemId)->first(['id', 'company_id', 'budget_program_id', 'invested_amount', 'changed_by_id', 'created_by_id', 'updated_at']);
        if ($line === null || ! Decimal::valid($line->invested_amount) || Decimal::isZero((string) $line->invested_amount) || ! $line->company_id || ! $line->budget_program_id
            || DB::table('budget_payments')->where('budget_item_id', $itemId)->exists()) {
            return;
        }
        $row = new BudgetPayment();
        $row->forceFill(['company_id' => $line->company_id, 'budget_program_id' => $line->budget_program_id, 'budget_item_id' => $itemId, 'amount' => (string) $line->invested_amount,
            'paid_on' => substr((string) ($line->updated_at ?? now()), 0, 10), 'method' => 'other', 'paid_by_id' => $line->changed_by_id ?: $line->created_by_id, 'source' => 'opening',
            'notes' => 'Paid before payment history was kept']);
        $row->save();
    }

    /** The web and the API do not remove a line with money on it: reverse the payments first, so nothing paid out vanishes. */
    public function assertRemovable(int $itemId, int $companyId): void
    {
        $this->adopt($itemId);
        $paid = $this->sum($itemId);
        if (! Decimal::isZero($paid)) {
            throw BusinessRuleException::make('line_has_payments', \App\Support\Money::format($paid, null, $companyId).' has been paid out on this line. Reverse those payments first, so the money stays accounted for.', ['paid' => $paid]);
        }
    }

    /** A line already removed (a phone's delete, the classic admin) with money on it: the money stays in Paid out, on Not on the budget. */
    public function moveOff(BudgetItem $removed, ?int $userId): void
    {
        DB::transaction(function () use ($removed, $userId) {
            $line = $this->lockLine((int) $removed->company_id, (int) $removed->id);
            if ($line === null) {
                return;
            }
            $this->adopt((int) $line->id);
            $paid = $this->sum((int) $line->id);
            if (Decimal::isZero($paid)) {
                return;
            }
            $by = self::$actorId ?? $userId;
            $today = LocalDate::today((int) $line->company_id)->format('Y-m-d');
            $target = $this->unbudgetedLine((int) $line->company_id, (int) $line->budget_program_id, (int) ($by ?: $line->created_by_id));
            $this->append($line, ['amount' => Decimal::neg($paid), 'paid_on' => $today, 'method' => 'other', 'source' => 'moved', 'reason' => 'The line was removed: moved to '.self::UNBUDGETED, 'created_by_id' => $by]);
            $this->append($target, ['amount' => $paid, 'paid_on' => $today, 'method' => 'other', 'source' => 'moved', 'notes' => $this->text('From the removed line '.$line->name, 255), 'created_by_id' => $by]);
            self::recalculate($line);
            self::recalculate($target);
        });
    }

    // ── Reading ─────────────────────────────────────────────

    /** A line's history, oldest first, each row saying who paid, who approved, who recorded and whether it was reversed. */
    public function history(int $companyId, int $itemId): Collection
    {
        $rows = $this->rows($companyId)->where('p.budget_item_id', $itemId)->orderBy('p.paid_on')->orderBy('p.id')->get();
        $reversed = $rows->whereNotNull('reverses_id')->pluck('reverses_id')->flip();

        return $rows->map(function ($r) use ($reversed) {
            $r->reversed = isset($reversed[$r->id]);

            return $r;
        });
    }

    /**
     * Every row on an event's live lines, for the Spending page and the documents; they add up to Paid out.
     *
     * @param  array{search?: string, method?: string, vendor?: string}  $f
     */
    public function ledger(int $companyId, int $programId, array $f = []): Builder
    {
        $q = $this->rows($companyId)->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')->leftJoin('budget_item_categories as ct', 'ct.id', '=', 'i.budget_item_category_id')
            ->where('p.budget_program_id', $programId)->where('i.is_deleted', 0)
            ->addSelect(['i.name as line', 'ct.name as category']);
        if (($f['method'] ?? '') !== '') {
            $q->where('p.method', $f['method']);
        }
        if (($f['vendor'] ?? '') !== '') {
            $q->where('p.paid_to', $f['vendor']);
        }
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $amount = PledgeService::amount($search);
            $q->where(function ($w) use ($like, $amount) {
                $w->where('p.paid_to', 'like', $like)->orWhere('i.name', 'like', $like)->orWhere('p.reference', 'like', $like)->orWhere('p.notes', 'like', $like)->orWhere('payer.name', 'like', $like);
                if ($amount !== null) {
                    $w->orWhere('p.amount', $amount);
                }
            });
        }

        return $q;
    }

    /** Paid out by who was paid, biggest first; the rows with no name are left to the ledger. @return list<array{name: string, amount: string, count: int}> */
    public function byVendor(int $companyId, int $programId): array
    {
        return DB::table('budget_payments as p')->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')
            ->where('p.company_id', $companyId)->where('p.budget_program_id', $programId)->where('p.is_deleted', 0)->where('i.is_deleted', 0)->whereNotNull('p.paid_to')->where('p.paid_to', '!=', '')
            ->groupBy('p.paid_to')->selectRaw('p.paid_to AS n, SUM(p.amount) AS a, COUNT(*) AS c')->orderByDesc('a')->get()
            ->filter(fn ($r) => Decimal::cmp((string) $r->a, '0') !== 0)
            ->map(fn ($r) => ['name' => (string) $r->n, 'amount' => Decimal::of((string) $r->a), 'count' => (int) $r->c])->values()->all();
    }

    /** Paid out by how it went, reversals taken off; adds up to Paid out. @return array<string, string> method => amount */
    public function byMethod(int $companyId, int $programId): array
    {
        return DB::table('budget_payments as p')->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')
            ->where('p.company_id', $companyId)->where('p.budget_program_id', $programId)->where('p.is_deleted', 0)->where('i.is_deleted', 0)
            ->groupBy('p.method')->selectRaw('p.method AS m, SUM(p.amount) AS a')->orderByDesc('a')->pluck('a', 'm')
            ->map(fn ($a) => Decimal::of((string) $a))->filter(fn (string $a) => Decimal::cmp($a, '0') !== 0)->all();
    }

    /** Who this account has paid lately, newest first, for one-tap suggestions. @return list<string> */
    public function recentVendors(int $companyId, int $limit = 30): array
    {
        return DB::table('budget_payments')->where('company_id', $companyId)->where('is_deleted', 0)->whereNotNull('paid_to')->where('paid_to', '!=', '')->whereNull('reverses_id')
            ->groupBy('paid_to')->orderByRaw('MAX(id) DESC')->limit($limit)->pluck('paid_to')->map(fn ($v) => (string) $v)->all();
    }

    /** The way this person paid last time, so the form remembers it. */
    public function lastMethod(int $companyId, int $userId): string
    {
        $method = DB::table('budget_payments')->where('company_id', $companyId)->where('created_by_id', $userId)->where('source', 'payment')->orderByDesc('id')->value('method');

        return isset(self::METHODS[(string) $method]) ? (string) $method : 'cash';
    }

    /** What a row of history is, in words, when it is not a plain payment. */
    public static function sourceLabel(string $source): ?string
    {
        return match ($source) {
            'opening' => 'Paid before this history was kept',
            'item_edit' => 'Figure changed on the line',
            'moved' => 'Moved when a line was removed',
            default => null,
        };
    }

    /**
     * "3 tiers × UGX 300,000", and the notes, from a line as it is stored. Older lines hold "unit: tier, from template x" in their details; it is read apart
     * here and not rewritten, so no template words reach a screen (PB-35).
     *
     * @return array{unit: string, note: string}
     */
    public static function readDetails(?string $details, ?string $unit = null): array
    {
        $note = trim((string) $details);
        $found = trim((string) $unit);
        $parts = [];
        foreach (array_map('trim', explode(',', $note)) as $part) {
            if (preg_match('/^unit:\s*(.+)$/i', $part, $m)) {
                $found = $found !== '' ? $found : trim($m[1]);
            } elseif (! preg_match('/^from template\s+[a-z0-9-]+$/i', $part) && $part !== '') {
                $parts[] = $part;
            }
        }

        return ['unit' => $found, 'note' => implode(', ', $parts)];
    }

    /**
     * An order for a query of categories or lines: as someone arranged them, else the biggest money first.
     *
     * @template TQuery
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function inOrder(mixed $query, string $table): mixed
    {
        if (in_array('sort_order', SyncRegistry::columns($table), true)) {
            $query->orderByRaw('sort_order IS NULL')->orderBy('sort_order');
        }
        if ($table === 'budget_items') {
            $query->orderByDesc('target_amount');
        }

        return $query->orderBy('name');
    }

    // ── Who may, from a phone ───────────────────────────────

    /** A pushed payment out: chair, treasurer or full access on its event. Null when allowed, else the ability that is missing. */
    public static function refuseSyncOp(?User $actor, int $companyId, string $table, array $data): ?string
    {
        if ($table !== 'budget_payments') {
            return null;
        }
        $programId = self::programOf($companyId, 'budget_payments', $data['reverses_uuid'] ?? null) ?? self::programOf($companyId, 'budget_items', $data['item_uuid'] ?? null)
            ?? (is_string($data['program_uuid'] ?? null) ? DB::table('budget_programs')->where('company_id', $companyId)->where('uuid', $data['program_uuid'])->value('id') : null);

        return $programId !== null && BudgetAccess::can($actor, (int) $programId, BudgetAccess::SPENDING) ? null : BudgetAccess::SPENDING;
    }

    /**
     * PB-08: a pushed line update that changes only what was paid out, by someone who may record spending, is allowed without manage_budget. Anything else on the
     * line still needs it (SyncApplier::permissionFor), including moving it to another category.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $op
     */
    public static function allowsSpendingSyncOp(?User $actor, int $companyId, string $table, string $action, array $data, string $uuid, array $op): bool
    {
        if ($actor === null || $table !== 'budget_items' || ! in_array($action, ['update', 'upsert'], true) || ! array_key_exists('invested_amount', $data)) {
            return false;
        }
        $line = DB::table('budget_items')->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($line === null || ! BudgetAccess::can($actor, (int) $line->budget_program_id, BudgetAccess::SPENDING)) {
            return false;
        }
        foreach (['category_uuid' => ['budget_item_categories', 'budget_item_category_id'], 'program_uuid' => ['budget_programs', 'budget_program_id']] as $wire => [$from, $column]) {
            if (array_key_exists($wire, $data) && (string) DB::table($from)->where('company_id', $companyId)->where('uuid', (string) $data[$wire])->value('id') !== (string) $line->{$column}) {
                return false;
            }
        }
        $changed = is_array($op['changed_fields'] ?? null) ? $op['changed_fields'] : array_keys($data);
        foreach (array_intersect($changed, SyncRegistry::get('budget_items')['fields'] ?? []) as $field) {
            if ($field === 'invested_amount' || ! array_key_exists($field, $data)) {
                continue;
            }
            $same = Decimal::valid($data[$field]) && Decimal::valid($line->{$field}) ? Decimal::cmp((string) $data[$field], (string) $line->{$field}) === 0 : trim((string) $data[$field]) === trim((string) $line->{$field});
            if (! $same) {
                return false;
            }
        }

        return true;
    }

    // ────────────────────────────────────────────────────────

    private static function programOf(int $companyId, string $table, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $id = DB::table($table)->where('company_id', $companyId)->where('uuid', $uuid)->value('budget_program_id');

        return $id === null ? null : (int) $id;
    }

    private function rows(int $companyId): Builder
    {
        return DB::table('budget_payments as p')->leftJoin('admin_users as payer', 'payer.id', '=', 'p.paid_by_id')->leftJoin('admin_users as approver', 'approver.id', '=', 'p.approved_by_id')
            ->leftJoin('admin_users as rec', 'rec.id', '=', 'p.created_by_id')->where('p.company_id', $companyId)->where('p.is_deleted', 0)
            ->select(['p.id', 'p.uuid', 'p.budget_item_id', 'p.amount', 'p.paid_on', 'p.paid_to', 'p.method', 'p.reference', 'p.notes', 'p.source', 'p.reverses_id', 'p.reason', 'p.created_at',
                'payer.name as paid_by', 'approver.name as approved_by', 'rec.name as recorded_by']);
    }

    private function lockLine(int $companyId, int $itemId): ?BudgetItem
    {
        return BudgetItem::withoutGlobalScopes()->without(['budgetItemCategory', 'createdBy'])->where('company_id', $companyId)->where('id', $itemId)->lockForUpdate()->first();
    }

    /** The event's line for money with no line of its own, made the first time it is needed. Locked. */
    private function unbudgetedLine(int $companyId, int $programId, int $userId): BudgetItem
    {
        DB::table('budget_programs')->where('id', $programId)->lockForUpdate()->value('id');
        $categoryId = DB::table('budget_item_categories')->where('company_id', $companyId)->where('budget_program_id', $programId)->where('name', self::UNBUDGETED)->where('is_deleted', 0)->value('id');
        if ($categoryId === null) {
            $category = new BudgetItemCategory();
            $category->forceFill(['company_id' => $companyId, 'budget_program_id' => $programId, 'name' => self::UNBUDGETED])->save();
            $categoryId = $category->id;
        }
        $id = DB::table('budget_items')->where('company_id', $companyId)->where('budget_item_category_id', $categoryId)->where('name', self::UNBUDGETED)->where('is_deleted', 0)->value('id');
        if ($id === null) {
            $line = new BudgetItem();
            $line->forceFill(['company_id' => $companyId, 'budget_program_id' => $programId, 'budget_item_category_id' => $categoryId, 'name' => self::UNBUDGETED, 'unit_price' => '0', 'quantity' => '1',
                'approved' => 'No', 'priority' => 'Normal', 'created_by_id' => $userId, 'changed_by_id' => $userId])->save();
            $id = $line->id;
        }

        return $this->lockLine($companyId, (int) $id) ?? throw BusinessRuleException::make('not_found', 'This budget line was not found.');
    }

    private function append(BudgetItem $item, array $row): BudgetPayment
    {
        $payment = new BudgetPayment();
        $payment->forceFill(array_filter($row, fn ($v) => $v !== null) + ['company_id' => $item->company_id, 'budget_program_id' => $item->budget_program_id, 'budget_item_id' => $item->id]);
        $payment->save();

        return $payment;
    }

    /** The same uuid again: the first answer (a double tap, a retried request); a uuid used on another line is refused. */
    private function replay(int $companyId, string $uuid, ?int $itemId): ?array
    {
        $existing = BudgetPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($existing === null) {
            return null;
        }
        if ($itemId !== null && (int) $existing->budget_item_id !== $itemId && $existing->source !== 'payment') {
            throw BusinessRuleException::make('uuid_reused', 'This payment id was already used for a different payment.');
        }
        $item = BudgetItem::withoutGlobalScopes()->without(['budgetItemCategory', 'createdBy'])->find($existing->budget_item_id);

        return ['payment' => $existing, 'item' => $item, 'replayed' => true];
    }

    private function refuseLikelyDuplicate(BudgetItem $item, string $amount, ?string $reference): void
    {
        $live = fn () => BudgetPayment::withoutGlobalScopes()->where('is_deleted', 0)->whereNull('reverses_id')->where('source', 'payment')
            ->whereNotIn('id', DB::table('budget_payments')->where('budget_program_id', $item->budget_program_id)->whereNotNull('reverses_id')->select('reverses_id'))->latest('id');
        $earlier = $reference === null ? null : $live()->where('budget_program_id', $item->budget_program_id)->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])->first();
        $earlier ??= $live()->where('budget_item_id', $item->id)->where('amount', $amount)->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_MINUTES))->first();
        if ($earlier === null) {
            return;
        }
        $who = trim((string) DB::table('admin_users')->where('id', $earlier->created_by_id)->value('name'));
        $when = $earlier->created_at !== null && $earlier->created_at->gt(now()->subDay()) ? $earlier->created_at->diffForHumans() : 'on '.Carbon::parse((string) $earlier->getRawOriginal('paid_on'))->format('j M Y');

        throw BusinessRuleException::make('possible_duplicate', 'This looks like the payment recorded '.$when.($who !== '' ? ' by '.$who : '').'. Record it again?', ['earlier' => ['id' => (int) $earlier->id]]);
    }

    /** Someone named on a payment. On the web and API they must be on the event's team; from a phone, on the account, else the fallback. */
    private function person(int $companyId, int $programId, mixed $given, ?int $fallback, bool $fromPhone, string $code): ?int
    {
        $id = is_numeric($given) ? (int) $given : 0;
        if ($id <= 0) {
            return $fallback;
        }
        if ($fromPhone) {
            return DB::table('admin_users')->where('id', $id)->where('company_id', $companyId)->exists() ? $id : $fallback;
        }
        if (! BudgetAccess::onTeam($companyId, $programId, $id)) {
            throw BusinessRuleException::make($code, "Choose someone on this event's team.");
        }

        return $id;
    }

    private function positiveAmount(mixed $value): string
    {
        if (! Decimal::valid($value) || Decimal::cmp((string) $value, '0') <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter an amount greater than zero.');
        }
        $amount = Decimal::of((string) $value);
        if (Decimal::cmp($amount, '999999999999999999') > 0) {
            throw BusinessRuleException::make('invalid_amount', 'That amount is too large.');
        }

        return $amount;
    }

    private function method(mixed $value, bool $fromPhone): string
    {
        $method = strtolower(str_replace([' ', '-'], '_', trim((string) ($value ?? ''))));
        $method = ['momo' => 'mobile_money', 'mobile' => 'mobile_money', 'bank_transfer' => 'bank'][$method] ?? $method;
        if ($method === '') {
            return 'cash';
        }
        if (! in_array($method, ContributionPayment::METHODS, true)) {
            if ($fromPhone) {
                return 'other';
            }
            throw BusinessRuleException::make('invalid_method', 'Choose cash, mobile money, bank, card, cheque or other.');
        }

        return $method;
    }

    private function paidOn(int $companyId, mixed $value, bool $fromPhone): string
    {
        $today = LocalDate::today($companyId)->format('Y-m-d');
        if ($value === null || $value === '') {
            return $today;
        }
        try {
            $date = Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            if ($fromPhone) {
                return $today;
            }
            throw BusinessRuleException::make('invalid_date', 'Enter the date it was paid.');
        }
        if ($date > Carbon::parse($today)->addDay()->format('Y-m-d')) {
            if ($fromPhone) {
                return $today;
            }
            throw BusinessRuleException::make('invalid_date', 'A payment cannot be dated in the future.');
        }

        return $date;
    }

    private function uuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! Str::isUuid((string) $value)) {
            throw BusinessRuleException::make('invalid_uuid', 'The payment id must be a UUID.');
        }

        return strtolower((string) $value);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
