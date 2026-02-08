<?php

namespace App\Admin\Actions\Batch;

use App\Models\ContributionRecord;
use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch Fix Contribution Records
 *
 * Recalculates paid_amount, not_paid_amount, and fully_paid for each
 * selected ContributionRecord, then cascades the totals up to the
 * parent BudgetProgram. Processes record-by-record with individual
 * transactions to avoid deadlocks. Maximum 50 records per batch.
 */
class BatchFixContributions extends BatchAction
{
    public $name = 'Recalculate Selected Contributions';

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

                $record = DB::table('contribution_records')->where('id', $item->id)->first();

                if (!$record) {
                    $failed++;
                    $errors[] = "Contribution #{$item->id}: not found";
                    DB::rollBack();
                    continue;
                }

                $amount = (float) $record->amount;
                $paidAmount = (float) $record->paid_amount;

                // Sanitize: paid cannot exceed pledged
                if ($paidAmount > $amount && $amount > 0) {
                    $paidAmount = $amount;
                }
                if ($paidAmount < 0) {
                    $paidAmount = 0;
                }

                $notPaidAmount = $amount - $paidAmount;
                $fullyPaid = $notPaidAmount <= 0 ? 'Yes' : 'No';

                if ($fullyPaid === 'Yes') {
                    $notPaidAmount = 0;
                    $paidAmount = $amount;
                }

                DB::table('contribution_records')
                    ->where('id', $record->id)
                    ->update([
                        'paid_amount'     => $paidAmount,
                        'not_paid_amount' => $notPaidAmount,
                        'fully_paid'      => $fullyPaid,
                        'custom_amount'   => null,  // Clear transient fields
                        'custom_paid_amount' => null,
                        'updated_at'      => now(),
                    ]);

                if ($record->budget_program_id) {
                    $affectedProgramIds[$record->budget_program_id] = true;
                }

                DB::commit();
                $fixed++;

            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Contribution #{$item->id}: " . $e->getMessage();
                Log::error('BatchFixContributions failed on record #' . $item->id, [
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
                Log::error("BatchFixContributions: cascade to program #{$programId} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('BatchFixContributions completed', [
            'contributions_fixed'  => $fixed,
            'contributions_failed' => $failed,
            'programs_fixed'       => $programCount,
            'errors'               => $errors,
        ]);

        $message = "Fixed {$fixed} contribution(s).";
        if ($programCount > 0) {
            $message .= " Updated {$programCount} program(s).";
        }
        if ($failed > 0) {
            $message .= " {$failed} failed.";
        }

        return $this->response()->success($message)->refresh();
    }

    /**
     * Recalculate a BudgetProgram's contribution totals.
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
            "Recalculate payment status (paid, not_paid, fully_paid) for the selected contributions and update parent program totals?\n\nMax {$this->maxPerBatch} per batch."
        );
    }
}
