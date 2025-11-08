<?php

namespace App\Jobs;

use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\StockCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateStockAggregates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $stockItemId;

    /**
     * Create a new job instance.
     */
    public function __construct($stockItemId)
    {
        $this->stockItemId = $stockItemId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $stockItem = StockItem::find($this->stockItemId);
            
            if (!$stockItem) {
                Log::warning("StockItem #{$this->stockItemId} not found for aggregate update");
                return;
            }

            // Update sub-category aggregates
            if ($stockItem->stockSubCategory) {
                $stockItem->stockSubCategory->update_self();
                Log::info("Updated StockSubCategory #{$stockItem->stock_sub_category_id} aggregates");
                
                // Update category aggregates
                if ($stockItem->stockSubCategory->stockCategory) {
                    $stockItem->stockSubCategory->stockCategory->update_self();
                    Log::info("Updated StockCategory #{$stockItem->stock_category_id} aggregates");
                }
            }
            
        } catch (\Throwable $e) {
            Log::error("Failed to update stock aggregates for item #{$this->stockItemId}: " . $e->getMessage());
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
