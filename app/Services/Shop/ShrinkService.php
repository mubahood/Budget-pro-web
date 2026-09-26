<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\StockRecord;

/**
 * Write-offs under supervision (SUPERMARKET_PLAN.md D4). A write-off (damaged, expired, lost/stolen,
 * own use…) worth more than the shop's `waste_limit` needs a supervisor's approval when the shop has
 * the `approvals` feature on; ApprovalService::required() is false otherwise, so this is then exactly
 * StockService::record().
 *
 * The check is here, on the server: a screen asks for the PIN (ApprovalService::grant) and passes the
 * approval id back; record() consumes it once, for this person and action.
 */
class ShrinkService
{
    /** Stock leaving the shop without a sale or a supplier: what "waste" means. */
    public const WRITE_OFF_TYPES = ['Damage', 'Expired', 'Lost', 'Internal Use'];

    public function __construct(private readonly StockService $stock = new StockService(), private readonly ApprovalService $approvals = new ApprovalService())
    {
    }

    public static function isWriteOff(?string $type): bool
    {
        return in_array($type, self::WRITE_OFF_TYPES, true);
    }

    /** The value of a write-off at cost (the unit cost given, else the product's buying price). */
    public static function value(int $companyId, int $stockItemId, float $quantity, ?float $unitCost = null): float
    {
        $cost = $unitCost ?? (float) StockItem::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($stockItemId)->value('buying_price');

        return round(abs($quantity) * max(0.0, $cost), 2);
    }

    /** Does this movement need a supervisor here? */
    public function needsApproval(Company|int $company, string $type, int $stockItemId, float $quantity, ?float $unitCost = null): bool
    {
        if (! self::isWriteOff($type)) {
            return false;
        }
        $companyId = $company instanceof Company ? (int) $company->id : $company;

        return $this->approvals->required($company, 'waste', ['amount' => self::value($companyId, $stockItemId, $quantity, $unitCost)]);
    }

    /**
     * Record a movement through StockService, refusing a write-off beyond the limit without a valid
     * approval (used once, for `waste`, asked for by $userId).
     *
     * @param  array<string, mixed>  $attrs  StockService::record() attributes
     */
    public function record(int $companyId, int $userId, array $attrs, ?int $approvalId = null): StockRecord
    {
        $type = (string) ($attrs['type'] ?? '');
        $qty = (float) ($attrs['quantity'] ?? 0);
        $cost = isset($attrs['unit_cost']) && is_numeric($attrs['unit_cost']) ? (float) $attrs['unit_cost'] : null;
        if ($this->needsApproval($companyId, $type, (int) $attrs['stock_item_id'], $qty, $cost)) {
            if (! $approvalId) {
                throw BusinessRuleException::make('approval_required', 'Writing off this much needs a supervisor’s approval. Ask a supervisor to enter their PIN.',
                    ['amount' => self::value($companyId, (int) $attrs['stock_item_id'], $qty, $cost)]);
            }

            // One transaction: a refused movement (not enough stock…) leaves the approval unused.
            return \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $userId, $attrs, $approvalId) {
                $this->approvals->consume($companyId, $approvalId, 'waste', $userId);

                return $this->stock->record($attrs + ['created_by_id' => $userId]);
            });
        }

        return $this->stock->record($attrs + ['created_by_id' => $userId]);
    }
}
