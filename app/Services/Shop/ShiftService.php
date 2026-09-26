<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\Shift;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Till sessions / cash-up (plan A3, P2-8): open with a float, close with the
 * counted cash; expected cash = float + cash received − cash refunded during
 * the shift; variance = counted − expected.
 *
 * Supermarket cash control (SUPERMARKET_PLAN E1/E4, feature `cash_control`): drops to the safe,
 * pickups from it, paid-in and paid-out (cashMovement) also move the expected cash. A shift with
 * no movements gives exactly the figures it always did.
 */
class ShiftService
{
    public function current(int $companyId, int $userId, ?string $deviceId = null): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'open')
            ->where('opened_by_id', $userId)
            ->when($deviceId, fn ($q) => $q->where(fn ($w) => $w->where('device_id', $deviceId)->orWhereNull('device_id')))
            ->latest('id')->first();
    }

    public function open(int $companyId, int $userId, float $openingFloat, ?string $deviceId = null, ?string $clientUuid = null, ?string $notes = null): Shift
    {
        if ($clientUuid) {
            $existing = Shift::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }
        }
        if ($this->current($companyId, $userId, $deviceId)) {
            throw BusinessRuleException::make('shift_already_open', 'You already have an open shift. Close it first.');
        }
        if ($openingFloat < 0) {
            throw BusinessRuleException::make('invalid_amount', 'Opening float cannot be negative.');
        }

        return DB::transaction(function () use ($companyId, $userId, $openingFloat, $deviceId, $clientUuid, $notes) {
            $shift = new Shift();
            if ($clientUuid) {
                $shift->uuid = $clientUuid;
            }
            $shift->company_id = $companyId;
            $shift->number = NumberSequencer::next($companyId, 'shift');
            $shift->opened_by_id = $userId;
            $shift->created_by_id = $userId;
            $shift->opened_at = now();
            $shift->opening_float = round($openingFloat, 2);
            $shift->expected_cash = round($openingFloat, 2);
            $shift->device_id = $deviceId;
            $shift->status = 'open';
            $shift->notes = $notes;
            $shift->save();

            return $shift;
        });
    }

    /** Live figures for an open or closed shift. */
    public function totals(Shift $shift): array
    {
        $sales = SaleRecord::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('voided_at');
        $byMethod = Payment::withoutGlobalScopes()->where('shift_id', $shift->id)
            ->selectRaw('method, SUM(amount) AS total, COUNT(*) AS n')->groupBy('method')->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['total' => round((float) $r->total, 2), 'count' => (int) $r->n]])->all();
        $cash = (float) ($byMethod['cash']['total'] ?? 0);
        $movements = $this->movementTotals($shift);

        $totals = [
            'sales_count' => (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('total_amount') - (float) (clone $sales)->sum('refunded_amount'), 2),
            'by_method' => $byMethod,
            'cash_in' => round($cash, 2),
            'expected_cash' => round((float) $shift->opening_float + $cash + $movements['net'], 2),
        ];
        if ($movements['by_type'] !== []) {
            $totals['cash_movements'] = $movements['by_type']; // only when there are any: the old shape stays as it was
        }

        return $totals;
    }

    /**
     * Drops, pickups, paid-in/out and no-sales in a shift: net effect on the drawer and, per type, count and amount.
     *
     * @return array{net: float, by_type: array<string, array{count: int, total: float}>}
     */
    public function movementTotals(Shift $shift): array
    {
        $rows = CashMovement::withoutGlobalScopes()->where('shift_id', $shift->id)
            ->selectRaw('type, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')->groupBy('type')->get();
        $net = 0.0;
        $byType = [];
        foreach ($rows as $r) {
            $net += (CashMovement::TYPES[$r->type][1] ?? 0) * (float) $r->total;
            $byType[$r->type] = ['count' => (int) $r->n, 'total' => round((float) $r->total, 2)];
        }

        return ['net' => round($net, 2), 'by_type' => $byType];
    }

    /**
     * Cash in or out of an open shift's drawer (plan E1), or a no-sale drawer opening (E4).
     *  - drop: to the safe; pickup: from the safe. No income or expense, only the drawer moves.
     *  - paid_out: an expense in the ledger (category "Cash paid out", or $categoryId, an Expense category).
     *  - paid_in: income in the ledger, category "Cash paid in".
     *  - no_sale: amount 0, a reason, logged.
     * A reason is always required. When ApprovalService::required() says so (paid_out → cash_out,
     * no_sale → no_sale) a supervisor's approval id must come with it; it is used up here.
     * Idempotent on $clientUuid.
     */
    public function cashMovement(Shift $shift, string $type, float $amount, string $reason, int $userId, ?int $approvalId = null, ?string $clientUuid = null, ?int $categoryId = null): CashMovement
    {
        $companyId = (int) $shift->company_id;
        if ($clientUuid) {
            $existing = CashMovement::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }
        }
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! StoreFeatures::enabled($company, 'cash_control')) {
            throw BusinessRuleException::make('feature_off', 'Cash drops and paid-in/out are off for this shop. Switch on cash control in Business settings.');
        }
        if (! array_key_exists($type, CashMovement::TYPES)) {
            throw BusinessRuleException::make('invalid_type', 'Choose a drop, a pickup, a paid-in, a paid-out or a no-sale.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::make('reason_required', 'Say why, in a few words.');
        }
        $amount = $type === 'no_sale' ? 0.0 : round($amount, 2);
        if ($type !== 'no_sale' && $amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter an amount greater than zero.');
        }

        return DB::transaction(function () use ($shift, $company, $companyId, $type, $amount, $reason, $userId, $approvalId, $clientUuid, $categoryId) {
            $shift = Shift::withoutGlobalScopes()->whereKey($shift->id)->lockForUpdate()->first();
            if ($shift === null || $shift->status !== 'open') {
                throw BusinessRuleException::make('shift_closed', 'This shift is closed. Open a shift first.');
            }
            if (in_array($type, ['drop', 'paid_out'], true)) {
                $inDrawer = (float) $this->totals($shift)['expected_cash'];
                if ($amount > $inDrawer + 0.004) {
                    throw BusinessRuleException::make('not_enough_cash', 'The drawer should only hold '.number_format($inDrawer, 2).'. You cannot take out more than that.');
                }
            }
            $action = ['paid_out' => 'cash_out', 'no_sale' => 'no_sale'][$type] ?? null;
            $approvedBy = null;
            if ($action !== null && $approvalId === null && (new ApprovalService())->required($company, $action, ['amount' => $amount])) {
                throw BusinessRuleException::make('approval_required', 'A supervisor must approve this with their PIN.');
            }
            if ($approvalId !== null) {
                $approvedBy = (int) (new ApprovalService())->consume($companyId, $approvalId, $action ?? 'cash_out', $userId)->approved_by;
            }

            $m = new CashMovement();
            $m->forceFill([
                'uuid' => $clientUuid ?: (string) Str::uuid(),
                'company_id' => $companyId,
                'shift_id' => $shift->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => mb_substr($reason, 0, 500),
                'created_by' => $userId,
                'approved_by' => $approvedBy,
            ]);

            if (in_array($type, ['paid_in', 'paid_out'], true)) {
                $out = $type === 'paid_out';
                $category = $out && $categoryId
                    ? FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('type', 'Expense')->find($categoryId)
                    : null;
                if ($out && $categoryId && $category === null) {
                    throw BusinessRuleException::make('invalid_category', 'Choose an expense category of this shop.');
                }
                $category ??= self::cashCategory($companyId, $out ? 'Cash paid out' : 'Cash paid in', $out ? 'Expense' : 'Income');
                $row = new FinancialRecord();
                $row->financial_category_id = $category->id;
                $row->company_id = $companyId;
                $row->user_id = $userId;
                $row->created_by_id = $userId;
                $row->amount = $amount;
                $row->quantity = 1;
                $row->type = $out ? 'Expense' : 'Income';
                $row->payment_method = 'cash';
                $row->recipient = '';
                $row->receipt = (string) $shift->number;
                $row->date = LocalDate::today($companyId);
                $row->description = ($out ? 'Paid out of the till' : 'Paid into the till').' (shift '.$shift->number.'): '.$reason;
                $row->source_type = 'cash_movement';
                $row->currency = $company?->currency;
                $row->save();
                $m->financial_record_id = $row->id;
            }
            $m->save();
            if ($m->financial_record_id) {
                DB::table('financial_records')->where('id', $m->financial_record_id)->update(['source_id' => $m->id]);
            }

            return $m;
        });
    }

    /** A named ledger category of this shop, created on first use. */
    public static function cashCategory(int $companyId, string $name, string $type): FinancialCategory
    {
        $c = FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $name)->first();
        if ($c === null) {
            $c = new FinancialCategory();
            $c->company_id = $companyId;
            $c->name = $name;
            $c->type = $type;
            $c->save();
        }

        return $c;
    }

    /**
     * @param  array<string, int|float>|null  $cashCount  blind cash-up (E3): how many of each note/coin were counted
     *                                                    (denomination => pieces), kept on the shift; $countedCash is their total.
     */
    public function close(Shift $shift, float $countedCash, int $userId, ?string $notes = null, ?array $cashCount = null): Shift
    {
        if ($shift->status === 'closed') {
            return $shift;
        }
        if ($countedCash < 0) {
            throw BusinessRuleException::make('invalid_amount', 'Counted cash cannot be negative.');
        }

        $closed = DB::transaction(function () use ($shift, $countedCash, $userId, $notes, $cashCount) {
            $t = $this->totals($shift);
            $shift->status = 'closed';
            $shift->closed_at = now();
            $shift->closed_by_id = $userId;
            $shift->counted_cash = round($countedCash, 2);
            $shift->expected_cash = $t['expected_cash'];
            $shift->variance = round($countedCash - $t['expected_cash'], 2);
            $shift->sales_total = $t['sales_total'];
            $shift->sales_count = $t['sales_count'];
            if ($notes) {
                $shift->notes = trim(($shift->notes ? $shift->notes."\n" : '').$notes);
            }
            if ($cashCount) {
                $shift->forceFill(['cash_count' => json_encode(array_filter($cashCount, fn ($n) => (float) $n > 0))]);
            }
            $shift->save();

            return $shift;
        });
        if (abs((float) $closed->variance) >= 0.01) {
            $cur = \App\Models\Company::withoutGlobalScopes()->find($closed->company_id)?->currency ?? '';
            $who = \App\Models\User::withoutGlobalScopes()->find($closed->opened_by_id)?->name ?? 'A cashier';
            $diff = (float) $closed->variance;
            app(\App\Services\Notifications\Notifier::class)->notify((int) $closed->company_id, 'cash_variance',
                'Cash '.($diff < 0 ? 'short' : 'over').' by '.number_format(abs($diff)).' '.$cur,
                "{$who}'s shift {$closed->number}: expected ".number_format((float) $closed->expected_cash).', counted '.number_format((float) $closed->counted_cash).'.', ['shift_id' => $closed->id]);
        }

        return $closed;
    }
}
