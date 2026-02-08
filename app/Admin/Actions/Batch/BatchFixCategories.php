<?php

namespace App\Admin\Actions\Batch;

use App\Models\BudgetItemCategory;
use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch Fix Budget Item Categories
 *
 * Recalculates target_amount, invested_amount, balance, percentage_done
 * and is_complete for each selected BudgetItemCategory by aggregating
 * from child BudgetItems, then cascades to the parent BudgetProgram.
 * Processes category-by-category with individual transactions to avoid deadlocks.
 * Maximum 50 categories per batch.
 */
class BatchFixCategories extends BatchAction
{
    public $name = 'Recalculate Selected Categories';

    protected int $maxPerBatch = 50;

    public function handle(Collection $collection)
    {
        if ($collection->count() > $this->maxPerBatch) {
            return $this->response()->error(
                "Too many items selected ({$collection->count()}). Maximum is {$this->maxPerBatch} per batch."
            );
        }

        $fixed = 0;
        $failed = 0;
        $errors = [];
        $affectedProgramIds = [];

        foreach ($collection as $item) {
            try {
                DB::beginTransaction();

                $category = BudgetItemCategory::withoutGlobalScopes()->find($item->id);

                if (!$category) {
                    $failed++;
                    $errors[] = "Category #{$item->id}: not found";
                    DB::rollBack();
                    continue;
                }

                // First: fix all child BudgetItems for this category
                $childItems = DB::table('budget_items')
                    ->where('budget_item_category_id', $category->id)
                    ->get();

                foreach ($childItems as $child) {
                    $targetAmount = (float) $child->target_amount;
                    $unitPrice = (float) ($child->unit_price ?? 0);
                    $quantity = (float) ($child->quantity ?? 0);

                    // Re-derive target_amount from unit_price × quantity
                    if ($unitPrice > 0 && $quantity > 0) {
                        $targetAmount = $unitPrice * $quantity;
                    }

                    $investedAmount = (float) $child->invested_amount;
                    $balance = $targetAmount - $investedAmount;
                    $percentageDone = $targetAmount > 0
                        ? round(($investedAmount / $targetAmount) * 100, 2)
                        : 0;
                    $isComplete = $percentageDone >= 98 ? 'Yes' : 'No';

                    DB::table('budget_items')
                        ->where('id', $child->id)
                        ->update([
                            'target_amount'   => $targetAmount,
                            'balance'         => $balance,
                            'percentage_done' => $percentageDone,
                            'is_complete'     => $isComplete,
                            'updated_at'      => now(),
                        ]);
                }

                // Now recalculate the category from its children
                $aggregates = DB::table('budget_items')
                    ->where('budget_item_category_id', $category->id)
                    ->selectRaw('COALESCE(SUM(target_amount), 0) as total_target, COALESCE(SUM(invested_amount), 0) as total_invested')
                    ->first();

                $catTarget = (float) $aggregates->total_target;
                $catInvested = (float) $aggregates->total_invested;
                $catBalance = $catTarget - $catInvested;
                $catPercentage = $catTarget > 0
                    ? round(($catInvested / $catTarget) * 100, 2)
                    : 0;
                $catComplete = ($catPercentage >= 98 || $catBalance <= 0) ? 'Yes' : 'No';

                DB::table('budget_item_categories')
                    ->where('id', $category->id)
                    ->update([
                        'target_amount'   => $catTarget,
                        'invested_amount' => $catInvested,
                        'balance'         => $catBalance,
                        'percentage_done' => $catPercentage,
                        'is_complete'     => $catComplete,
                        'updated_at'      => now(),
                    ]);

                if ($category->budget_program_id) {
                    $affectedProgramIds[$category->budget_program_id] = true;
                }

                DB::commit();
                $fixed++;

            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Category #{$item->id}: " . $e->getMessage();
                Log::error('BatchFixCategories failed on category #' . $item->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cascade to parent BudgetPrograms
        $programCount = 0;
        foreach (array_keys($affectedProgramIds) as $programId) {
            try {
                $this->recalculateProgram($programId);
                $programCount++;
            } catch (\Exception $e) {
                Log::error("BatchFixCategories: cascade to program #{$programId} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('BatchFixCategories completed', [
            'categories_fixed' => $fixed,
            'categories_failed' => $failed,
            'programs_fixed'   => $programCount,
            'errors'           => $errors,
        ]);

        $message = "Fixed {$fixed} categor" . ($fixed === 1 ? 'y' : 'ies') . " (with child items).";
        if ($programCount > 0) {
            $message .= " Updated {$programCount} program(s).";
        }
        if ($failed > 0) {
            $message .= " {$failed} failed.";
        }

        return $this->response()->success($message)->refresh();
    }

    /**
     * Recalculate a BudgetProgram from its child categories and contributions.
     */
    protected function recalculateProgram(int $programId): void
    {
        $budgetAggregates = DB::table('budget_item_categories')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(target_amount), 0) as budget_total, COALESCE(SUM(invested_amount), 0) as budget_spent')
            ->first();

        $contributionAggregates = DB::table('contribution_records')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_expected, COALESCE(SUM(paid_amount), 0) as total_collected, COALESCE(SUM(not_paid_amount), 0) as total_in_pledge')
            ->first();

        $budgetTotal = (float) $budgetAggregates->budget_total;
        $budgetSpent = (float) $budgetAggregates->budget_spent;

        DB::table('budget_programs')
            ->where('id', $programId)
            ->update([
                'budget_total'    => $budgetTotal,
                'budget_spent'    => $budgetSpent,
                'budget_balance'  => $budgetTotal - $budgetSpent,
                'total_expected'  => (float) $contributionAggregates->total_expected,
                'total_collected' => (float) $contributionAggregates->total_collected,
                'total_in_pledge' => (float) $contributionAggregates->total_in_pledge,
                'updated_at'      => now(),
            ]);
    }

    public function dialog()
    {
        $this->confirm(
            "Recalculate all child items and then re-aggregate this category's totals?\n\nThis also cascades to the parent budget program.\nMax {$this->maxPerBatch} per batch."
        );
    }
}
