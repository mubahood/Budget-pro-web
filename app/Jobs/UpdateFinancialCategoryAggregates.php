<?php

namespace App\Jobs;

use App\Models\FinancialCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateFinancialCategoryAggregates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $financialCategoryId;

    /**
     * Create a new job instance.
     */
    public function __construct($financialCategoryId)
    {
        $this->financialCategoryId = $financialCategoryId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $financialCategory = FinancialCategory::find($this->financialCategoryId);
            
            if (!$financialCategory) {
                Log::warning("FinancialCategory #{$this->financialCategoryId} not found for aggregate update");
                return;
            }

            // Update category aggregates if update_self method exists
            if (method_exists($financialCategory, 'update_self')) {
                $financialCategory->update_self();
                Log::info("Updated FinancialCategory #{$this->financialCategoryId} aggregates");
            }
            
        } catch (\Throwable $e) {
            Log::error("Failed to update financial category aggregates for #{$this->financialCategoryId}: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 30;
}
