<?php

namespace App\Services;

use App\Models\InventoryForecast;
use App\Models\StockItem;
use App\Models\StockRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryForecastService
{
    /**
     * Generate forecast for a stock item
     */
    public function generateForecast(StockItem $stockItem, $forecastDays = 30, $algorithm = 'moving_average')
    {
        $historicalData = $this->getHistoricalData($stockItem, 90); // Last 90 days
        
        if (empty($historicalData)) {
            return $this->createDefaultForecast($stockItem);
        }
        
        $forecast = new InventoryForecast();
        $forecast->company_id = $stockItem->company_id;
        $forecast->stock_item_id = $stockItem->id;
        $forecast->financial_period_id = $stockItem->financial_period_id;
        $forecast->forecast_date = Carbon::now()->addDays($forecastDays);
        $forecast->forecast_period = 'monthly';
        $forecast->current_stock = $stockItem->current_quantity;
        
        // Calculate historical statistics
        $demands = array_column($historicalData, 'demand');
        $forecast->historical_average = round(array_sum($demands) / count($demands));
        $forecast->historical_min = min($demands);
        $forecast->historical_max = max($demands);
        $forecast->standard_deviation = $this->calculateStandardDeviation($demands);
        
        // Apply forecasting algorithm
        switch ($algorithm) {
            case 'exponential_smoothing':
                $prediction = $this->exponentialSmoothing($demands);
                break;
            case 'linear_regression':
                $prediction = $this->linearRegression($demands);
                break;
            case 'moving_average':
            default:
                $prediction = $this->movingAverage($demands);
                break;
        }
        
        $forecast->predicted_demand = round($prediction);
        $forecast->predicted_min = round($prediction * 0.8); // 20% lower bound
        $forecast->predicted_max = round($prediction * 1.2); // 20% upper bound
        $forecast->algorithm_used = $algorithm;
        
        // Analyze trend
        $forecast->trend = $this->analyzeTrend($demands);
        $forecast->trend_percentage = $this->calculateTrendPercentage($demands);
        
        // Check for seasonality
        $seasonality = $this->detectSeasonality($historicalData);
        $forecast->is_seasonal = $seasonality['is_seasonal'];
        $forecast->seasonal_factors = $seasonality['factors'];
        
        // Calculate confidence level
        $forecast->confidence_level = $this->calculateConfidence($demands, $prediction);
        
        // Calculate reorder recommendations
        $leadTime = 7; // Default 7 days lead time
        $safetyStock = round($forecast->standard_deviation * 1.65); // 95% service level
        $forecast->safety_stock = $safetyStock;
        $forecast->recommended_reorder_point = round(($forecast->predicted_demand / 30 * $leadTime) + $safetyStock);
        $forecast->recommended_order_quantity = round($forecast->predicted_demand * 1.5); // 1.5 months supply
        
        // Calculate days until stockout
        if ($forecast->predicted_demand > 0) {
            $dailyDemand = $forecast->predicted_demand / 30;
            $forecast->days_until_stockout = $dailyDemand > 0 ? round($stockItem->current_quantity / $dailyDemand) : null;
        }
        
        // Determine stock status
        $forecast->stock_status = $this->determineStockStatus(
            $stockItem->current_quantity,
            $forecast->recommended_reorder_point,
            $forecast->safety_stock
        );
        
        // Generate action recommendations
        $forecast->action_required = in_array($forecast->stock_status, ['low', 'critical', 'stockout']);
        $forecast->recommended_action = $this->generateRecommendation($forecast);
        
        $forecast->save();
        
        return $forecast;
    }

    /**
     * Get historical demand data for a stock item
     */
    protected function getHistoricalData(StockItem $stockItem, $days = 90)
    {
        $startDate = Carbon::now()->subDays($days);
        
        $records = StockRecord::where('stock_item_id', $stockItem->id)
            ->where('created_at', '>=', $startDate)
            ->whereIn('type', ['sale', 'stock_out'])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(ABS(quantity)) as demand')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
        
        return $records;
    }

    /**
     * Moving Average forecasting
     */
    protected function movingAverage($data, $period = 7)
    {
        $count = count($data);
        if ($count < $period) {
            return array_sum($data) / $count;
        }
        
        $recentData = array_slice($data, -$period);
        return array_sum($recentData) / $period;
    }

    /**
     * Exponential Smoothing forecasting
     */
    protected function exponentialSmoothing($data, $alpha = 0.3)
    {
        if (empty($data)) return 0;
        
        $forecast = $data[0];
        foreach ($data as $actual) {
            $forecast = $alpha * $actual + (1 - $alpha) * $forecast;
        }
        
        return $forecast;
    }

    /**
     * Linear Regression forecasting
     */
    protected function linearRegression($data)
    {
        $n = count($data);
        if ($n < 2) return end($data) ?: 0;
        
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        
        foreach ($data as $i => $y) {
            $x = $i + 1;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Predict next value
        return $slope * ($n + 1) + $intercept;
    }

    /**
     * Calculate standard deviation
     */
    protected function calculateStandardDeviation($data)
    {
        $count = count($data);
        if ($count < 2) return 0;
        
        $mean = array_sum($data) / $count;
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $data)) / $count;
        
        return sqrt($variance);
    }

    /**
     * Analyze demand trend
     */
    protected function analyzeTrend($data)
    {
        $count = count($data);
        if ($count < 3) return 'stable';
        
        $firstHalf = array_slice($data, 0, floor($count / 2));
        $secondHalf = array_slice($data, floor($count / 2));
        
        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);
        
        $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;
        
        // Check volatility
        $stdDev = $this->calculateStandardDeviation($data);
        $mean = array_sum($data) / $count;
        $cv = $mean > 0 ? ($stdDev / $mean) * 100 : 0; // Coefficient of variation
        
        if ($cv > 50) return 'volatile';
        if ($change > 10) return 'increasing';
        if ($change < -10) return 'decreasing';
        
        return 'stable';
    }

    /**
     * Calculate trend percentage
     */
    protected function calculateTrendPercentage($data)
    {
        $count = count($data);
        if ($count < 2) return 0;
        
        $firstHalf = array_slice($data, 0, floor($count / 2));
        $secondHalf = array_slice($data, floor($count / 2));
        
        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);
        
        if ($firstAvg == 0) return 0;
        
        return (($secondAvg - $firstAvg) / $firstAvg) * 100;
    }

    /**
     * Detect seasonality patterns
     */
    protected function detectSeasonality($historicalData)
    {
        // Simple seasonality detection - can be enhanced
        if (count($historicalData) < 30) {
            return ['is_seasonal' => false, 'factors' => null];
        }
        
        // Group by day of week
        $weekdayPatterns = [];
        foreach ($historicalData as $record) {
            $dayOfWeek = Carbon::parse($record['date'])->dayOfWeek;
            $weekdayPatterns[$dayOfWeek][] = $record['demand'];
        }
        
        $weekdayAvgs = [];
        foreach ($weekdayPatterns as $day => $demands) {
            $weekdayAvgs[$day] = array_sum($demands) / count($demands);
        }
        
        $overallAvg = array_sum($weekdayAvgs) / count($weekdayAvgs);
        $maxDeviation = 0;
        
        foreach ($weekdayAvgs as $avg) {
            $deviation = abs(($avg - $overallAvg) / $overallAvg) * 100;
            $maxDeviation = max($maxDeviation, $deviation);
        }
        
        $isSeasonal = $maxDeviation > 20; // 20% variation indicates seasonality
        
        return [
            'is_seasonal' => $isSeasonal,
            'factors' => $isSeasonal ? $weekdayAvgs : null
        ];
    }

    /**
     * Calculate forecast confidence level
     */
    protected function calculateConfidence($data, $prediction)
    {
        $stdDev = $this->calculateStandardDeviation($data);
        $mean = array_sum($data) / count($data);
        
        if ($mean == 0) return 50;
        
        $cv = ($stdDev / $mean) * 100; // Coefficient of variation
        
        // Lower CV = higher confidence
        if ($cv < 10) return 95;
        if ($cv < 20) return 85;
        if ($cv < 30) return 75;
        if ($cv < 50) return 60;
        
        return 50;
    }

    /**
     * Determine stock status
     */
    protected function determineStockStatus($currentStock, $reorderPoint, $safetyStock)
    {
        if ($currentStock <= 0) return 'stockout';
        if ($currentStock <= $safetyStock) return 'critical';
        if ($currentStock <= $reorderPoint) return 'low';
        if ($currentStock > $reorderPoint * 3) return 'overstocked';
        
        return 'optimal';
    }

    /**
     * Generate action recommendation
     */
    protected function generateRecommendation(InventoryForecast $forecast)
    {
        switch ($forecast->stock_status) {
            case 'stockout':
                return "URGENT: Item is out of stock. Order {$forecast->recommended_order_quantity} units immediately.";
            case 'critical':
                return "CRITICAL: Only {$forecast->current_stock} units remaining. Order {$forecast->recommended_order_quantity} units within 24 hours.";
            case 'low':
                return "LOW STOCK: Current stock ({$forecast->current_stock}) is below reorder point ({$forecast->recommended_reorder_point}). Order {$forecast->recommended_order_quantity} units.";
            case 'overstocked':
                return "OVERSTOCKED: Consider reducing orders. Current stock is {$forecast->current_stock} units (optimal range: {$forecast->recommended_reorder_point} - " . ($forecast->recommended_reorder_point * 2) . ").";
            default:
                return "Stock levels are optimal. No action required.";
        }
    }

    /**
     * Create default forecast when no historical data available
     */
    protected function createDefaultForecast(StockItem $stockItem)
    {
        $forecast = new InventoryForecast();
        $forecast->company_id = $stockItem->company_id;
        $forecast->stock_item_id = $stockItem->id;
        $forecast->financial_period_id = $stockItem->financial_period_id;
        $forecast->forecast_date = Carbon::now()->addDays(30);
        $forecast->forecast_period = 'monthly';
        $forecast->current_stock = $stockItem->current_quantity;
        $forecast->predicted_demand = 0;
        $forecast->confidence_level = 0;
        $forecast->trend = 'stable';
        $forecast->stock_status = 'optimal';
        $forecast->algorithm_used = 'default';
        $forecast->notes = 'Insufficient historical data for accurate forecasting';
        $forecast->save();
        
        return $forecast;
    }

    /**
     * Batch generate forecasts for all items
     */
    public function generateBatchForecasts($companyId)
    {
        $stockItems = StockItem::where('company_id', $companyId)->get();
        $results = [];
        
        foreach ($stockItems as $item) {
            try {
                $results[] = $this->generateForecast($item);
            } catch (\Exception $e) {
                \Log::error("Forecast generation failed for item {$item->id}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
}
