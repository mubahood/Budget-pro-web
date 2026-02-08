<?php

namespace App\Admin\Actions\Batch;

use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch Fix Budget Items
 *
 * Recalculates balance, percentage_done, and is_complete for each selected
 * BudgetItem, then cascades the fix upward to the parent BudgetItemCategory
 * and BudgetProgram. Processes item-by-item with individual transactions
 * to avoid deadlocks. Maximum 50 items per batch.
 */
class BatchFixBudgetItems extends BatchAction
{
    public $name = 'Recalculate Selected Items';

    /**
     * Maximum items allowed per batch to avoid timeouts and memory issues.
     */
    protected int $maxPerBatch = 50;

    public function handle(Collection $collection)
    {
        // Enforce max-per-batch limit
        if ($collection->count() > $this->maxPerBatch) {
            return $this->response()->error(
                "Too many items selected ({$collection->count()}). Maximum is {$this->maxPerBatch} per batch. Please select fewer items."
            );
        }

        $fixed = 0;
        $failed = 0;
        $errors = [];
        $affectedCategoryIds = [];

        // Process each BudgetItem individually to avoid deadlocks
        foreach ($collection as $item) {
            try {
                DB::beginTransaction();

                // Reload to get fresh data and avoid stale reads
                $budgetItem = BudgetItem::withoutGlobalScopes()->find($item->id);

                if (!$budgetItem) {
                    $failed++;
                    $errors[] = "Item #{$item->id}: not found";
                    DB::rollBack();
                    continue;
                }

                // Recalculate computed fields
                $targetAmount = (float) $budgetItem->target_amount;
                $investedAmount = (float) $budgetItem->invested_amount;

                $balance = $targetAmount - $investedAmount;
                $percentageDone = $targetAmount > 0
                    ? round(($investedAmount / $targetAmount) * 100, 2)
                    : 0;
                $isComplete = $percentageDone >= 98 ? 'Yes' : 'No';

                // Also re-derive target_amount from unit_price × quantity if both exist
                $unitPrice = (float) ($budgetItem->unit_price ?? 0);
                $quantity = (float) ($budgetItem->quantity ?? 0);
                if ($unitPrice > 0 && $quantity > 0) {
                    $derivedTarget = $unitPrice * $quantity;
                    if (abs($derivedTarget - $targetAmount) > 0.01) {
                        $targetAmount = $derivedTarget;
                        $balance = $targetAmount - $investedAmount;
                        $percentageDone = $targetAmount > 0
                            ? round(($investedAmount / $targetAmount) * 100, 2)
                            : 0;
                        $isComplete = $percentageDone >= 98 ? 'Yes' : 'No';
                    }
                }

                // Use direct DB update to bypass model events and avoid infinite loops
                DB::table('budget_items')
                    ->where('id', $budgetItem->id)
                    ->update([
                        'target_amount'   => $targetAmount,
                        'balance'         => $balance,
                        'percentage_done' => $percentageDone,
                        'is_complete'     => $isComplete,
                        'updated_at'      => now(),
                    ]);

                // Track affected parent category for cascade
                if ($budgetItem->budget_item_category_id) {
                    $affectedCategoryIds[$budgetItem->budget_item_category_id] = true;
                }

                DB::commit();
                $fixed++;

            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Item #{$item->id}: " . $e->getMessage();
                Log::error('BatchFixBudgetItems failed on item #' . $item->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cascade fix upward: recalculate each affected BudgetItemCategory
        $categoriesFixed = 0;
        $programsFixed = [];
        foreach (array_keys($affectedCategoryIds) as $categoryId) {
            try {
                $category = BudgetItemCategory::withoutGlobalScopes()->find($categoryId);
                if ($category) {
                    $this->recalculateCategory($category);
                    $categoriesFixed++;

                    // Track parent program for further cascade
                    if ($category->budget_program_id) {
                        $programsFixed[$category->budget_program_id] = true;
                    }
                }
            } catch (\Exception $e) {
                Log::error("BatchFixBudgetItems: cascade to category #{$categoryId} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cascade fix upward: recalculate each affected BudgetProgram
        $programCount = 0;
        foreach (array_keys($programsFixed) as $programId) {
            try {
                $this->recalculateProgram($programId);
                $programCount++;
            } catch (\Exception $e) {
                Log::error("BatchFixBudgetItems: cascade to program #{$programId} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log the batch fix operation
        Log::info('BatchFixBudgetItems completed', [
            'items_fixed'      => $fixed,
            'items_failed'     => $failed,
            'categories_fixed' => $categoriesFixed,
            'programs_fixed'   => $programCount,
            'errors'           => $errors,
        ]);

        $message = "Fixed {$fixed} budget item(s).";
        if ($categoriesFixed > 0) {
            $message .= " Cascaded to {$categoriesFixed} categor" . ($categoriesFixed === 1 ? 'y' : 'ies') . ".";
        }
        if ($programCount > 0) {
            $message .= " Updated {$programCount} program(s).";
        }
        if ($failed > 0) {
            $message .= " {$failed} item(s) failed.";
        }

        return $this->response()->success($message)->refresh();
    }

    /**
     * Recalculate a BudgetItemCategory from its child BudgetItems.
     * Uses direct DB queries to avoid model event side-effects.
     */
    protected function recalculateCategory(BudgetItemCategory $category): void
    {
        $aggregates = DB::table('budget_items')
            ->where('budget_item_category_id', $category->id)
            ->selectRaw('COALESCE(SUM(target_amount), 0) as total_target, COALESCE(SUM(invested_amount), 0) as total_invested')
            ->first();

        $targetAmount = (float) $aggregates->total_target;
        $investedAmount = (float) $aggregates->total_invested;
        $balance = $targetAmount - $investedAmount;
        $percentageDone = $targetAmount > 0
            ? round(($investedAmount / $targetAmount) * 100, 2)
            : 0;
        $isComplete = ($percentageDone >= 98 || $balance <= 0) ? 'Yes' : 'No';

        DB::table('budget_item_categories')
            ->where('id', $category->id)
            ->update([
                'target_amount'   => $targetAmount,
                'invested_amount' => $investedAmount,
                'balance'         => $balance,
                'percentage_done' => $percentageDone,
                'is_complete'     => $isComplete,
                'updated_at'      => now(),
            ]);
    }

    /**
     * Recalculate a BudgetProgram from its child categories and contributions.
     * Uses direct DB queries to avoid model event side-effects.
     */
    protected function recalculateProgram(int $programId): void
    {
        // Budget totals from categories
        $budgetAggregates = DB::table('budget_item_categories')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(target_amount), 0) as budget_total, COALESCE(SUM(invested_amount), 0) as budget_spent')
            ->first();

        // Contribution totals
        $contributionAggregates = DB::table('contribution_records')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_expected, COALESCE(SUM(paid_amount), 0) as total_collected, COALESCE(SUM(not_paid_amount), 0) as total_in_pledge')
            ->first();

        $budgetTotal = (float) $budgetAggregates->budget_total;
        $budgetSpent = (float) $budgetAggregates->budget_spent;
        $budgetBalance = $budgetTotal - $budgetSpent;

        DB::table('budget_programs')
            ->where('id', $programId)
            ->update([
                'budget_total'    => $budgetTotal,
                'budget_spent'    => $budgetSpent,
                'budget_balance'  => $budgetBalance,
                'total_expected'  => (float) $contributionAggregates->total_expected,
                'total_collected' => (float) $contributionAggregates->total_collected,
                'total_in_pledge' => (float) $contributionAggregates->total_in_pledge,
                'updated_at'      => now(),
            ]);
    }

    public function dialog()
    {
        $this->confirm(
            "Recalculate all computed fields (balance, progress, status) for the selected budget items and cascade the fix to parent categories and programs?\n\nMax {$this->maxPerBatch} items per batch."
        );
    }
}
