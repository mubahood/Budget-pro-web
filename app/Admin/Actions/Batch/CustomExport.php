<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class CustomExport extends BatchAction
{
    public $name = '📊 Custom Export';

    public function form()
    {
        $this->checkbox('fields', 'Select Fields to Export')
            ->options([
                'id' => 'ID',
                'name' => 'Product Name',
                'sku' => 'SKU',
                'barcode' => 'Barcode',
                'category' => 'Category',
                'buying_price' => 'Buying Price',
                'selling_price' => 'Selling Price',
                'current_quantity' => 'Current Stock',
                'original_quantity' => 'Initial Stock',
                'stock_value' => 'Stock Value',
                'profit_margin' => 'Profit Margin',
                'description' => 'Description',
                'measuring_unit' => 'Unit',
                'created_at' => 'Date Added',
            ])
            ->default(['name', 'sku', 'buying_price', 'selling_price', 'current_quantity']);
        
        $this->select('format', 'Export Format')
            ->options([
                'csv' => '📄 CSV',
                'excel' => '📊 Excel (XLSX)',
                'pdf' => '📑 PDF',
                'json' => '🔧 JSON',
            ])
            ->default('csv')
            ->rules('required');
    }

    public function handle(Collection $collection, Request $request)
    {
        $fields = $request->get('fields', []);
        $format = $request->get('format', 'csv');
        
        if (empty($fields)) {
            return $this->response()->error('⚠️ Please select at least one field to export!');
        }
        
        // Build export data
        $data = [];
        $headers = [];
        
        foreach ($fields as $field) {
            $headers[] = $this->getFieldLabel($field);
        }
        
        foreach ($collection as $model) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $this->getFieldValue($model, $field);
            }
            $data[] = $row;
        }
        
        // Generate file
        $filename = 'custom_export_' . date('Y-m-d_H-i-s') . '.' . $format;
        
        switch ($format) {
            case 'csv':
                return $this->exportCSV($headers, $data, $filename);
            case 'excel':
                return $this->exportExcel($headers, $data, $filename);
            case 'json':
                return $this->exportJSON($headers, $data, $filename);
            case 'pdf':
                return $this->exportPDF($headers, $data, $filename);
            default:
                return $this->exportCSV($headers, $data, $filename);
        }
    }
    
    private function getFieldLabel($field)
    {
        $labels = [
            'id' => 'ID',
            'name' => 'Product Name',
            'sku' => 'SKU',
            'barcode' => 'Barcode',
            'category' => 'Category',
            'buying_price' => 'Buying Price',
            'selling_price' => 'Selling Price',
            'current_quantity' => 'Current Stock',
            'original_quantity' => 'Initial Stock',
            'stock_value' => 'Stock Value',
            'profit_margin' => 'Profit Margin %',
            'description' => 'Description',
            'measuring_unit' => 'Unit',
            'created_at' => 'Date Added',
        ];
        
        return $labels[$field] ?? $field;
    }
    
    private function getFieldValue($model, $field)
    {
        switch ($field) {
            case 'category':
                return $model->stock_category->name ?? 'N/A';
            case 'stock_value':
                return $model->current_quantity * $model->buying_price;
            case 'profit_margin':
                return $model->buying_price > 0 ? 
                    round((($model->selling_price - $model->buying_price) / $model->buying_price) * 100, 2) : 0;
            case 'created_at':
                return date('Y-m-d H:i:s', strtotime($model->created_at));
            default:
                return $model->$field ?? '';
        }
    }
    
    private function exportCSV($headers, $data, $filename)
    {
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $headers);
        
        foreach ($data as $row) {
            fputcsv($csv, $row);
        }
        
        rewind($csv);
        $output = stream_get_contents($csv);
        fclose($csv);
        
        return response($output, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    
    private function exportJSON($headers, $data, $filename)
    {
        $result = [];
        
        foreach ($data as $row) {
            $item = [];
            foreach ($headers as $index => $header) {
                $item[$header] = $row[$index];
            }
            $result[] = $item;
        }
        
        return response()->json($result)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    
    private function exportExcel($headers, $data, $filename)
    {
        // Simplified Excel export (would need PhpSpreadsheet library)
        return $this->exportCSV($headers, $data, str_replace('.xlsx', '.csv', $filename));
    }
    
    private function exportPDF($headers, $data, $filename)
    {
        // Simplified PDF export (would need dompdf library)
        return $this->exportCSV($headers, $data, str_replace('.pdf', '.csv', $filename));
    }
}
