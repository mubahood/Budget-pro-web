<?php

namespace App\Admin\Actions\Batch;

use App\Models\BudgetProgram;
use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch Fix Budget Programs
 *
 * Full cascade recalculation for selected BudgetPrograms:
 *   1. Fix every child BudgetItem (target_amount, balance, percentage, status)
 *   2. Fix every child BudgetItemCategory (aggregates from items)
 *   3. Fix the BudgetProgram itself (aggregates from categories + contributions)
 *
 * Processes program-by-program, each in its own transaction, to avoid deadlocks.
 * Maximum 50 programs per batch.
 */
class BatchFixPrograms extends BatchAction
{
    public $name = 'Full Recalculate Selected Programs';

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
        $totalItemsFixed = 0;
        $totalCategoriesFixed = 0;
        $totalContributionsFixed = 0;

        foreach ($collection as $item) {
            try {
                DB::beginTransaction();

                $program = BudgetProgram::withoutGlobalScopes()->find($item->id);

                if (!$program) {
                    $failed++;
                    $errors[] = "Program #{$item->id}: not found";
                    DB::rollBack();
                    continue;
                }

                // Step 1: Fix all BudgetItems under this program
                $items = DB::table('budget_items')
                    ->where('budget_program_id', $program->id)
                    ->get();

                foreach ($items as $budgetItem) {
                    $targetAmount = (float) $budgetItem->target_amount;
                    $unitPrice = (float) ($budgetItem->unit_price ?? 0);
                    $quantity = (float) ($budgetItem->quantity ?? 0);

                    if ($unitPrice > 0 && $quantity > 0) {
                        $targetAmount = $unitPrice * $quantity;
                    }

                    $investedAmount = (float) $budgetItem->invested_amount;
                    $balance = $targetAmount - $investedAmount;
                    $percentageDone = $targetAmount > 0
                        ? round(($investedAmount / $targetAmount) * 100, 2)
                        : 0;
                    $isComplete = $percentageDone >= 98 ? 'Yes' : 'No';

                    DB::table('budget_items')
                        ->where('id', $budgetItem->id)
                        ->update([
                            'target_amount'   => $targetAmount,
                            'balance'         => $balance,
                            'percentage_done' => $percentageDone,
                            'is_complete'     => $isComplete,
                            'updated_at'      => now(),
                        ]);
                    $totalItemsFixed++;
                }

                // Step 2: Fix all BudgetItemCategories under this program
                $categories = DB::table('budget_item_categories')
                    ->where('budget_program_id', $program->id)
                    ->get();

                foreach ($categories as $category) {
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
                    $totalCategoriesFixed++;
                }

                // Step 3: Fix ContributionRecords under this program
                $contributions = DB::table('contribution_records')
                    ->where('budget_program_id', $program->id)
                    ->get();

                foreach ($contributions as $contribution) {
                    $amount = (float) $contribution->amount;
                    $paidAmount = (float) $contribution->paid_amount;

                    // Ensure paid_amount does not exceed amount
                    if ($paidAmount > $amount) {
                        $paidAmount = $amount;
                    }

                    $notPaidAmount = $amount - $paidAmount;
                    $fullyPaid = $notPaidAmount <= 0 ? 'Yes' : 'No';

                    if ($fullyPaid === 'Yes') {
                        $notPaidAmount = 0;
                        $paidAmount = $amount;
                    }

                    DB::table('contribution_records')
                        ->where('id', $contribution->id)
                        ->update([
                            'paid_amount'     => $paidAmount,
                            'not_paid_amount' => $notPaidAmount,
                            'fully_paid'      => $fullyPaid,
                            'updated_at'      => now(),
                        ]);
                    $totalContributionsFixed++;
                }

                // Step 4: Recalculate the BudgetProgram aggregates
                $budgetAggregates = DB::table('budget_item_categories')
                    ->where('budget_program_id', $program->id)
                    ->selectRaw('COALESCE(SUM(target_amount), 0) as budget_total, COALESCE(SUM(invested_amount), 0) as budget_spent')
                    ->first();

                $contributionAggregates = DB::table('contribution_records')
                    ->where('budget_program_id', $program->id)
                    ->selectRaw('COALESCE(SUM(amount), 0) as total_expected, COALESCE(SUM(paid_amount), 0) as total_collected, COALESCE(SUM(not_paid_amount), 0) as total_in_pledge')
                    ->first();

                $budgetTotal = (float) $budgetAggregates->budget_total;
                $budgetSpent = (float) $budgetAggregates->budget_spent;

                DB::table('budget_programs')
                    ->where('id', $program->id)
                    ->update([
                        'budget_total'    => $budgetTotal,
                        'budget_spent'    => $budgetSpent,
                        'budget_balance'  => $budgetTotal - $budgetSpent,
                        'total_expected'  => (float) $contributionAggregates->total_expected,
                        'total_collected' => (float) $contributionAggregates->total_collected,
                        'total_in_pledge' => (float) $contributionAggregates->total_in_pledge,
                        'updated_at'      => now(),
                    ]);

                DB::commit();
                $fixed++;

            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Program #{$item->id}: " . $e->getMessage();
                Log::error('BatchFixPrograms failed on program #' . $item->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('BatchFixPrograms completed', [
            'programs_fixed'      => $fixed,
            'programs_failed'     => $failed,
            'items_fixed'         => $totalItemsFixed,
            'categories_fixed'    => $totalCategoriesFixed,
            'contributions_fixed' => $totalContributionsFixed,
            'errors'              => $errors,
        ]);

        $message = "Fixed {$fixed} program(s): {$totalItemsFixed} items, {$totalCategoriesFixed} categories, {$totalContributionsFixed} contributions recalculated.";
        if ($failed > 0) {
            $message .= " {$failed} program(s) failed.";
        }

        return $this->response()->success($message)->refresh();
    }

    public function dialog()
    {
        $this->confirm(
            "Run a FULL cascade recalculation on the selected program(s)?\n\nThis will fix ALL child items → categories → program totals → contribution balances.\nMax {$this->maxPerBatch} per batch."
        );
    }
}
