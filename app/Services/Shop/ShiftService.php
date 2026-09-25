<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Till sessions / cash-up (plan A3, P2-8): open with a float, close with the
 * counted cash; expected cash = float + cash received − cash refunded during
 * the shift; variance = counted − expected.
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

        return [
            'sales_count' => (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('total_amount') - (float) (clone $sales)->sum('refunded_amount'), 2),
            'by_method' => $byMethod,
            'cash_in' => round($cash, 2),
            'expected_cash' => round((float) $shift->opening_float + $cash, 2),
        ];
    }

    public function close(Shift $shift, float $countedCash, int $userId, ?string $notes = null): Shift
    {
        if ($shift->status === 'closed') {
            return $shift;
        }
        if ($countedCash < 0) {
            throw BusinessRuleException::make('invalid_amount', 'Counted cash cannot be negative.');
        }

        $closed = DB::transaction(function () use ($shift, $countedCash, $userId, $notes) {
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
