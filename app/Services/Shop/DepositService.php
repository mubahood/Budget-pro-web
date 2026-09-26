<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\SaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Container deposits (SUPERMARKET_PLAN.md A11, StoreFeatures `deposits`). A deposit is a normal
 * product ("Crate deposit") linked to a drink by `stock_items.deposit_item_id`; the till adds it with
 * the drink, so it is sold on an ordinary sale line.
 *
 * Returned empties are not a negative line (a sale line must be more than zero): they are a return of
 * earlier deposit lines through ReturnService, oldest first (the customer's own sales first when one
 * is named). The crates go back on the shelf, and the money paid for them is refunded as a negative
 * payment, exactly like any other return; a deposit still owed on a credit sale is simply owed less.
 */
class DepositService
{
    public function __construct(private readonly ReturnService $returns = new ReturnService())
    {
    }

    /** How many of this deposit item are out with customers (sold and not yet returned). */
    public function held(int $companyId, int $depositItemId): float
    {
        return round((float) $this->outstanding($companyId, $depositItemId)->sum(fn ($r) => (float) $r->open), 3);
    }

    /**
     * Take back empties: return $quantity of the deposit item from earlier sales (oldest first; this
     * customer's sales first when given), refunding what was paid for them.
     *
     * @return array{quantity: float, refund: float, returns: list<int>}
     */
    public function returnEmpties(int $companyId, int $userId, int $depositItemId, float $quantity, string $method = 'cash', ?int $customerId = null, ?int $shiftId = null): array
    {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw BusinessRuleException::make('invalid_quantity', 'How many empties came back? Enter more than zero.');
        }
        $name = (string) DB::table('stock_items')->where('company_id', $companyId)->where('id', $depositItemId)->where('is_deleted', 0)->value('name');
        if ($name === '') {
            throw BusinessRuleException::make('product_not_found', 'That deposit item was not found.');
        }
        $isDeposit = DB::table('stock_items')->where('company_id', $companyId)->where('deposit_item_id', $depositItemId)->where('is_deleted', 0)->exists();
        if (! $isDeposit) {
            throw BusinessRuleException::make('not_a_deposit', "{$name} is not the deposit of any product.");
        }
        $rows = $this->outstanding($companyId, $depositItemId, $customerId);
        $out = round((float) $rows->sum(fn ($r) => (float) $r->open), 3);
        if ($quantity > $out + 0.0005) {
            throw BusinessRuleException::make('too_many_empties', 'Only '.rtrim(rtrim(number_format($out, 3, '.', ''), '0'), '.')." {$name} are out with customers, so no more can be taken back.", ['held' => $out]);
        }

        return DB::transaction(function () use ($rows, $quantity, $userId, $method, $shiftId, $name) {
            $left = $quantity;
            $refund = 0.0;
            $ids = [];
            foreach ($rows->groupBy('sale_record_id') as $saleId => $lines) {
                if ($left <= 0.0005) {
                    break;
                }
                $take = [];
                foreach ($lines as $line) {
                    $q = round(min((float) $line->open, $left), 3);
                    if ($q > 0) {
                        $take[] = ['sale_item_id' => (int) $line->id, 'quantity' => $q, 'restock' => true];
                        $left = round($left - $q, 3);
                    }
                }
                if ($take === []) {
                    continue;
                }
                $sale = SaleRecord::withoutGlobalScopes()->find($saleId);
                $return = $this->returns->create($sale, $take, $userId, 'Empties returned: '.$name, $method, null, $shiftId);
                $refund += (float) $return->refund_amount;
                $ids[] = (int) $return->id;
            }

            return ['quantity' => $quantity, 'refund' => round($refund, 2), 'returns' => $ids];
        });
    }

    /** Deposit lines with something still out, oldest first (a named customer's first). */
    private function outstanding(int $companyId, int $depositItemId, ?int $customerId = null)
    {
        return DB::table('sale_record_items as i')->join('sale_records as s', 's.id', '=', 'i.sale_record_id')
            ->where('s.company_id', $companyId)->where('i.stock_item_id', $depositItemId)
            ->whereNull('s.voided_at')->where('s.status', '<>', 'Voided')->where('s.is_deleted', 0)->where('i.is_deleted', 0)
            ->whereRaw('i.quantity - COALESCE(i.returned_quantity, 0) > 0.0005')
            ->when($customerId, fn ($q) => $q->orderByRaw('s.customer_id = ? DESC', [$customerId]))
            ->orderBy('s.id')->orderBy('i.id')
            ->get(['i.id', 'i.sale_record_id', DB::raw('i.quantity - COALESCE(i.returned_quantity, 0) AS open')]);
    }
}
