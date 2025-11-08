<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use App\Models\StockItem;

class FindDuplicates extends RowAction
{
    public $name = '🔍 Find Duplicates';

    public function handle(Model $model)
    {
        $companyId = admin_toastr()->user()->company_id ?? $model->company_id;
        
        // Find potential duplicates based on different criteria
        $duplicates = [
            'by_name' => [],
            'by_sku' => [],
            'by_barcode' => [],
            'similar_names' => []
        ];
        
        // Exact name matches
        if (!empty($model->name)) {
            $duplicates['by_name'] = StockItem::where('company_id', $companyId)
                ->where('id', '!=', $model->id)
                ->where('name', 'LIKE', $model->name)
                ->limit(10)
                ->get();
        }
        
        // SKU matches
        if (!empty($model->sku)) {
            $duplicates['by_sku'] = StockItem::where('company_id', $companyId)
                ->where('id', '!=', $model->id)
                ->where('sku', $model->sku)
                ->limit(10)
                ->get();
        }
        
        // Barcode matches
        if (!empty($model->barcode)) {
            $duplicates['by_barcode'] = StockItem::where('company_id', $companyId)
                ->where('id', '!=', $model->id)
                ->where('barcode', $model->barcode)
                ->limit(10)
                ->get();
        }
        
        // Similar names (using SOUNDEX or LIKE)
        if (!empty($model->name)) {
            $duplicates['similar_names'] = StockItem::where('company_id', $companyId)
                ->where('id', '!=', $model->id)
                ->where('name', 'LIKE', '%' . substr($model->name, 0, 5) . '%')
                ->limit(10)
                ->get();
        }
        
        // Build HTML response
        $html = $this->buildDuplicateReport($model, $duplicates);
        
        return $this->response()->html($html)->modal([
            'title' => '🔍 Duplicate Detection: ' . $model->name,
            'width' => '900px'
        ]);
    }
    
    private function buildDuplicateReport($model, $duplicates)
    {
        $html = '<div style="max-height: 500px; overflow-y: auto;">';
        
        // Summary
        $totalDuplicates = count($duplicates['by_name']) + 
                          count($duplicates['by_sku']) + 
                          count($duplicates['by_barcode']) +
                          count($duplicates['similar_names']);
        
        if ($totalDuplicates === 0) {
            $html .= '<div style="text-align: center; padding: 40px;">';
            $html .= '<div style="font-size: 48px;">✅</div>';
            $html .= '<h3 style="color: #4caf50;">No Duplicates Found!</h3>';
            $html .= '<p style="color: #666;">This product appears to be unique.</p>';
            $html .= '</div>';
        } else {
            $html .= '<div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 20px;">';
            $html .= '<strong>⚠️ Warning:</strong> Found ' . $totalDuplicates . ' potential duplicate(s)!';
            $html .= '</div>';
            
            // Current product info
            $html .= '<div style="padding: 15px; background: #e3f2fd; border-radius: 4px; margin-bottom: 20px;">';
            $html .= '<h4 style="margin-top: 0;">Current Product</h4>';
            $html .= '<div><strong>Name:</strong> ' . e($model->name) . '</div>';
            $html .= '<div><strong>SKU:</strong> ' . e($model->sku) . '</div>';
            $html .= '<div><strong>Barcode:</strong> ' . e($model->barcode ?? 'N/A') . '</div>';
            $html .= '<div><strong>Price:</strong> UGX ' . number_format($model->selling_price) . '</div>';
            $html .= '</div>';
            
            // Exact name duplicates
            if (!empty($duplicates['by_name'])) {
                $html .= $this->renderDuplicateSection(
                    '🎯 Exact Name Matches',
                    $duplicates['by_name'],
                    '#f44336'
                );
            }
            
            // SKU duplicates
            if (!empty($duplicates['by_sku'])) {
                $html .= $this->renderDuplicateSection(
                    '🏷️ SKU Duplicates',
                    $duplicates['by_sku'],
                    '#ff5722'
                );
            }
            
            // Barcode duplicates
            if (!empty($duplicates['by_barcode'])) {
                $html .= $this->renderDuplicateSection(
                    '📊 Barcode Duplicates',
                    $duplicates['by_barcode'],
                    '#ff9800'
                );
            }
            
            // Similar names
            if (!empty($duplicates['similar_names'])) {
                $html .= $this->renderDuplicateSection(
                    '🔎 Similar Names',
                    $duplicates['similar_names'],
                    '#ffc107'
                );
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    private function renderDuplicateSection($title, $items, $color)
    {
        $html = '<div style="margin-bottom: 25px;">';
        $html .= '<h4 style="color: ' . $color . '; border-bottom: 2px solid ' . $color . '; padding-bottom: 8px;">';
        $html .= $title . ' (' . count($items) . ')';
        $html .= '</h4>';
        
        $html .= '<table class="table table-bordered" style="margin-bottom: 0;">';
        $html .= '<thead><tr style="background: #f5f5f5;">';
        $html .= '<th>ID</th><th>Name</th><th>SKU</th><th>Barcode</th><th>Stock</th><th>Price</th><th>Action</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . $item->id . '</td>';
            $html .= '<td>' . e($item->name) . '</td>';
            $html .= '<td>' . e($item->sku) . '</td>';
            $html .= '<td>' . e($item->barcode ?? '-') . '</td>';
            $html .= '<td>' . number_format($item->current_quantity) . '</td>';
            $html .= '<td>UGX ' . number_format($item->selling_price) . '</td>';
            $html .= '<td>';
            $html .= '<a href="' . admin_url('stock-items/' . $item->id . '/edit') . '" target="_blank" class="btn btn-xs btn-primary">View</a>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        return $html;
    }
}
