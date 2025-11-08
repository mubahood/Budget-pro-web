<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class GenerateQRCode extends RowAction
{
    public $name = '📱 QR Code';

    public function handle(Model $model)
    {
        // Generate product data for QR code
        $productData = [
            'id' => $model->id,
            'name' => $model->name,
            'sku' => $model->sku,
            'barcode' => $model->barcode,
            'price' => $model->selling_price,
            'url' => admin_url('stock-items/' . $model->id)
        ];
        
        $dataString = json_encode($productData);
        
        // Use Google Charts API for QR code generation (free, no API key needed)
        $qrCodeUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($dataString);
        
        // Alternative: Use QR Server API
        $qrCodeUrlAlt = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($dataString);
        
        $html = '<div style="text-align: center; padding: 20px;">';
        
        // QR Code Display
        $html .= '<div style="margin-bottom: 20px;">';
        $html .= '<img src="' . $qrCodeUrlAlt . '" style="max-width: 100%; border: 10px solid #f5f5f5; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">';
        $html .= '</div>';
        
        // Product Info
        $html .= '<div style="padding: 20px; background: #f8f9fa; border-radius: 8px; margin-bottom: 20px; text-align: left;">';
        $html .= '<h4 style="margin-top: 0;">📦 Product Information</h4>';
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">';
        $html .= '<div><strong>Name:</strong> ' . e($model->name) . '</div>';
        $html .= '<div><strong>SKU:</strong> ' . e($model->sku) . '</div>';
        $html .= '<div><strong>Barcode:</strong> ' . e($model->barcode ?? 'N/A') . '</div>';
        $html .= '<div><strong>Price:</strong> UGX ' . number_format($model->selling_price) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Download options
        $html .= '<div style="margin-top: 20px;">';
        $html .= '<h5>Download QR Code:</h5>';
        $html .= '<div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">';
        
        // Small size
        $html .= '<a href="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($dataString) . '" download="qr_small_' . $model->sku . '.png" class="btn btn-sm btn-default">';
        $html .= '📱 Small (150x150)';
        $html .= '</a>';
        
        // Medium size
        $html .= '<a href="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($dataString) . '" download="qr_medium_' . $model->sku . '.png" class="btn btn-sm btn-primary">';
        $html .= '📱 Medium (300x300)';
        $html .= '</a>';
        
        // Large size
        $html .= '<a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($dataString) . '" download="qr_large_' . $model->sku . '.png" class="btn btn-sm btn-success">';
        $html .= '📱 Large (500x500)';
        $html .= '</a>';
        
        // Print size
        $html .= '<a href="https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&data=' . urlencode($dataString) . '" download="qr_print_' . $model->sku . '.png" class="btn btn-sm btn-warning">';
        $html .= '🖨️ Print (1000x1000)';
        $html .= '</a>';
        
        $html .= '</div>';
        $html .= '</div>';
        
        // QR Code Data
        $html .= '<div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 6px; text-align: left;">';
        $html .= '<strong>📋 QR Code Data:</strong>';
        $html .= '<pre style="margin: 10px 0 0 0; background: white; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;">';
        $html .= json_encode($productData, JSON_PRETTY_PRINT);
        $html .= '</pre>';
        $html .= '</div>';
        
        // Usage Instructions
        $html .= '<div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; text-align: left;">';
        $html .= '<strong>💡 How to use:</strong>';
        $html .= '<ul style="margin: 10px 0 0 0; padding-left: 20px;">';
        $html .= '<li>Scan with any QR code reader app</li>';
        $html .= '<li>Print and attach to product packaging</li>';
        $html .= '<li>Share with customers for quick product info</li>';
        $html .= '<li>Use in marketing materials</li>';
        $html .= '</ul>';
        $html .= '</div>';
        
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => '📱 QR Code: ' . $model->name,
            'width' => '600px'
        ]);
    }
}
