<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\ContributionPayment;
use App\Models\ContributionRecord;
use App\Services\Shop\NumberSequencer;
use App\Support\Decimal;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pledge payments (roadmap 02 §6): the only writer of contribution_payments and of a pledge's paid figures.
 *
 *  - record():  a payment received. Idempotent on its uuid (the phone's op uuid or the API's `uuid`).
 *  - reverse(): a mistake put right with a new negative row and a reason; the original is never touched.
 *  - settleTo(): the older apps' way (they send the pledge's new paid_amount) as a payment of the difference.
 *
 * Every write locks the pledge row, appends, then sets paid_amount = SUM(payments) in the same transaction,
 * so two payments at the same moment both count and the figure always equals the history (bcmath, no floats).
 */
class ContributionPayments
{
    public const RECEIPT_KIND = 'plg'; // receipt numbers PLG-2026-000001 (NumberSequencer)

    /**
     * @param  array{amount: mixed, paid_on?: ?string, method?: ?string, receipt_no?: ?string, reference?: ?string, notes?: ?string, uuid?: ?string, device_id?: ?string, client_created_at?: ?int}  $data
     * @param  bool  $allowOverpay  true for payments made offline (sync): money already received is never refused
     * @return array{payment: ContributionPayment, record: ContributionRecord, replayed: bool}
     */
    public function record(int $companyId, int $recordId, array $data, int $userId, bool $allowOverpay = false): array
    {
        $amount = $this->positiveAmount($data['amount'] ?? null);
        $method = $this->method($data['method'] ?? null);
        $paidOn = $this->paidOn($companyId, $data['paid_on'] ?? null);
        $uuid = $this->uuid($data['uuid'] ?? null);

        return DB::transaction(function () use ($companyId, $recordId, $data, $userId, $allowOverpay, $amount, $method, $paidOn, $uuid) {
            if ($uuid !== null && ($replay = $this->replay($companyId, $uuid, $recordId, null)) !== null) {
                return $replay;
            }
            $record = $this->lockPledge($companyId, $recordId);
            if (! $allowOverpay && Decimal::cmp($record->amount, '0') > 0) {
                $owed = Decimal::sub($record->amount, $this->sum($record->id));
                if (Decimal::cmp($amount, $owed) > 0) {
                    throw BusinessRuleException::make('more_than_owed', Decimal::cmp($owed, '0') <= 0
                        ? 'This pledge is already fully paid. Raise the pledge first to record more.'
                        : 'That is more than the '.$this->money($companyId, $owed).' still owed on this pledge. Raise the pledge first, or record '.$this->money($companyId, $owed).'.',
                        ['owed' => $owed]);
                }
            }
            $payment = $this->append($record, [
                'uuid' => $uuid, 'amount' => $amount, 'paid_on' => $paidOn, 'method' => $method, 'received_by_id' => $userId,
                'receipt_no' => $this->receiptNo($companyId, $data['receipt_no'] ?? null), 'reference' => $this->text($data['reference'] ?? null, 100),
                'notes' => $this->text($data['notes'] ?? null, 255), 'source' => 'payment', 'created_by_id' => $userId,
                'device_id' => $data['device_id'] ?? null, 'client_created_at' => $data['client_created_at'] ?? null,
            ]);

            return ['payment' => $payment, 'record' => self::recalculate($record), 'replayed' => false];
        });
    }

    /** @return array{payment: ContributionPayment, record: ContributionRecord, replayed: bool} */
    public function reverse(int $companyId, int $paymentId, string $reason, int $userId, ?string $uuid = null, ?string $deviceId = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::make('reason_required', 'Say why this payment is being reversed.');
        }
        $uuid = $this->uuid($uuid);

        return DB::transaction(function () use ($companyId, $paymentId, $reason, $userId, $uuid, $deviceId) {
            $original = ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $paymentId)->where('is_deleted', 0)->first();
            if ($original === null) {
                throw BusinessRuleException::make('not_found', 'This payment was not found.');
            }
            if ($uuid !== null && ($replay = $this->replay($companyId, $uuid, (int) $original->contribution_record_id, (int) $original->id)) !== null) {
                return $replay;
            }
            $record = $this->lockPledge($companyId, (int) $original->contribution_record_id);
            if ($original->reverses_id !== null) {
                throw BusinessRuleException::make('cannot_reverse_reversal', 'A reversal cannot be reversed. Record the payment again instead.');
            }
            if (ContributionPayment::withoutGlobalScopes()->where('reverses_id', $original->id)->exists()) {
                throw BusinessRuleException::make('already_reversed', 'This payment was already reversed.');
            }
            $payment = $this->append($record, [
                'uuid' => $uuid, 'amount' => Decimal::neg($original->amount), 'paid_on' => LocalDate::today($companyId)->format('Y-m-d'), 'method' => $original->method,
                'received_by_id' => $userId, 'reference' => $original->receipt_no, 'source' => 'reversal', 'reverses_id' => $original->id,
                'reason' => mb_substr($reason, 0, 255), 'created_by_id' => $userId, 'device_id' => $deviceId,
            ]);

            return ['payment' => $payment, 'record' => self::recalculate($record), 'replayed' => false];
        });
    }

    /**
     * Older apps and the classic forms send the pledge's new paid figure: post the difference from what the
     * history says (a negative row with a reason when it went down). Null when nothing changed.
     */
    public function settleTo(int $companyId, int $recordId, string $wanted, ?int $userId): ?ContributionPayment
    {
        return DB::transaction(function () use ($companyId, $recordId, $wanted, $userId) {
            $record = $this->lockPledge($companyId, $recordId, false);
            $diff = Decimal::sub($wanted, $this->sum($record->id));
            if (Decimal::isZero($diff)) {
                self::recalculate($record);

                return null;
            }
            $lowered = Decimal::cmp($diff, '0') < 0;
            $payment = $this->append($record, [
                'amount' => $diff, 'paid_on' => LocalDate::today($companyId)->format('Y-m-d'), 'method' => 'cash', 'received_by_id' => $userId, 'created_by_id' => $userId,
                'receipt_no' => $lowered ? null : $this->receiptNo($companyId, null), 'source' => 'pledge_edit',
                'reason' => $lowered ? 'The amount paid was lowered on the pledge' : null,
            ]);
            self::recalculate($record);

            return $payment;
        });
    }

    /**
     * paid_amount = SUM(payments), not_paid_amount and fully_paid from it; then the event's totals. Call with the
     * pledge row locked (inside the caller's transaction). Writes only when a figure changed, and bumps the sync
     * version so phones see it.
     */
    public static function recalculate(ContributionRecord $record): ContributionRecord
    {
        $paid = (new self())->sum((int) $record->id);
        $amount = Decimal::of($record->getRawOriginal('amount') ?? $record->amount ?? 0);
        $notPaid = Decimal::max(Decimal::sub($amount, $paid), '0');
        $fully = Decimal::cmp($amount, '0') > 0 && Decimal::cmp($paid, $amount) >= 0 ? 'Yes' : 'No';
        $stored = DB::table('contribution_records')->where('id', $record->id)->first(['paid_amount', 'not_paid_amount', 'fully_paid']);
        if ($stored === null || Decimal::cmp((string) $stored->paid_amount, $paid) !== 0 || Decimal::cmp((string) $stored->not_paid_amount, $notPaid) !== 0 || $stored->fully_paid !== $fully) {
            $record->paid_amount = $paid;
            $record->not_paid_amount = $notPaid;
            $record->fully_paid = $fully;
            $record->saveQuietlySynced();
        }
        if ($record->budget_program_id) {
            BudgetProgram::recalculateFromChildren((int) $record->budget_program_id);
        }

        return $record;
    }

    /** Lock and recalculate one pledge (admin repair tool, tests). */
    public function recalculateById(int $companyId, int $recordId): ContributionRecord
    {
        return DB::transaction(fn () => self::recalculate($this->lockPledge($companyId, $recordId, false)));
    }

    /** The sum of a pledge's payments, exactly ("0.00" without any). */
    public function sum(int $recordId): string
    {
        return Decimal::of((string) DB::table('contribution_payments')->where('contribution_record_id', $recordId)->where('is_deleted', 0)
            ->selectRaw('COALESCE(SUM(amount), 0) AS s')->value('s'));
    }

    // ------------------------------------------------------------------

    private function lockPledge(int $companyId, int $recordId, bool $mustBeLive = true): ContributionRecord
    {
        $record = ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])
            ->where('company_id', $companyId)->where('id', $recordId)->lockForUpdate()->first();
        if ($record === null || ($mustBeLive && $record->is_deleted)) {
            throw BusinessRuleException::make('not_found', 'This pledge was not found.');
        }

        return $record;
    }

    private function append(ContributionRecord $record, array $row): ContributionPayment
    {
        $payment = new ContributionPayment();
        $payment->forceFill(array_filter($row, fn ($v) => $v !== null) + [
            'company_id' => $record->company_id, 'budget_program_id' => $record->budget_program_id, 'contribution_record_id' => $record->id,
        ]);
        $payment->save();

        return $payment;
    }

    /** The same uuid again: the first answer (a double tap, a retried request); a uuid used for something else is refused. */
    private function replay(int $companyId, string $uuid, int $recordId, ?int $reversesId): ?array
    {
        $existing = ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->contribution_record_id !== $recordId || ($reversesId !== null && (int) $existing->reverses_id !== $reversesId)) {
            throw BusinessRuleException::make('uuid_reused', 'This payment id was already used for a different payment.');
        }
        $record = ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])->find($recordId);

        return ['payment' => $existing, 'record' => $record, 'replayed' => true];
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

    private function method(mixed $value): string
    {
        $method = strtolower(str_replace([' ', '-'], '_', trim((string) ($value ?? ''))));
        $method = ['momo' => 'mobile_money', 'mobile' => 'mobile_money', 'bank_transfer' => 'bank'][$method] ?? $method;
        if ($method === '') {
            return 'cash';
        }
        if (! in_array($method, ContributionPayment::METHODS, true)) {
            throw BusinessRuleException::make('invalid_method', 'Choose cash, mobile money, bank, card, cheque or other.');
        }

        return $method;
    }

    private function paidOn(int $companyId, mixed $value): string
    {
        $today = LocalDate::today($companyId)->format('Y-m-d');
        if ($value === null || $value === '') {
            return $today;
        }
        try {
            $date = \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            throw BusinessRuleException::make('invalid_date', 'Enter the date it was paid.');
        }
        if ($date > \Illuminate\Support\Carbon::parse($today)->addDay()->format('Y-m-d')) {
            throw BusinessRuleException::make('invalid_date', 'A payment cannot be dated in the future.');
        }

        return $date;
    }

    private function uuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        if (! Str::isUuid($value)) {
            throw BusinessRuleException::make('invalid_uuid', 'The payment id must be a UUID.');
        }

        return strtolower($value);
    }

    private function receiptNo(int $companyId, mixed $given): string
    {
        $given = $this->text($given, 40);

        return $given ?? NumberSequencer::next($companyId, self::RECEIPT_KIND);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function money(int $companyId, string $amount): string
    {
        return \App\Support\Money::symbol($companyId).' '.number_format((float) $amount, \App\Support\Money::decimals($companyId)); // display only
    }
}
