<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class QuickSupplierInfo extends RowAction
{
    public $name = '🚚 Supplier Info';

    public function handle(Model $model)
    {
        $html = '<div style="padding: 20px;">';
        
        if (empty($model->supplier_id)) {
            $html .= '<div style="text-align: center; padding: 40px;">';
            $html .= '<div style="font-size: 48px; color: #999;">📦</div>';
            $html .= '<h4 style="color: #666;">No Supplier Information</h4>';
            $html .= '<p>This product doesn\'t have a supplier assigned yet.</p>';
            $html .= '<a href="' . admin_url('stock-items/' . $model->id . '/edit') . '" class="btn btn-primary">';
            $html .= '<i class="fa fa-plus"></i> Add Supplier';
            $html .= '</a>';
            $html .= '</div>';
        } else {
            // Try to get supplier info (assuming you have a Supplier model)
            try {
                $supplier = \App\Models\Supplier::find($model->supplier_id);
                
                if ($supplier) {
                    $html .= '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; margin: -20px -20px 20px -20px;">';
                    $html .= '<h3 style="margin: 0; color: white;">🚚 ' . e($supplier->name) . '</h3>';
                    $html .= '</div>';
                    
                    // Supplier details grid
                    $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">';
                    
                    $html .= '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
                    $html .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">Contact Person</div>';
                    $html .= '<div style="font-weight: 600;">' . e($supplier->contact_person ?? 'N/A') . '</div>';
                    $html .= '</div>';
                    
                    $html .= '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
                    $html .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">📞 Phone</div>';
                    $html .= '<div style="font-weight: 600;">' . e($supplier->phone ?? 'N/A') . '</div>';
                    $html .= '</div>';
                    
                    $html .= '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
                    $html .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">📧 Email</div>';
                    $html .= '<div style="font-weight: 600;">' . e($supplier->email ?? 'N/A') . '</div>';
                    $html .= '</div>';
                    
                    $html .= '<div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">';
                    $html .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">📍 Address</div>';
                    $html .= '<div style="font-weight: 600;">' . e($supplier->address ?? 'N/A') . '</div>';
                    $html .= '</div>';
                    
                    $html .= '</div>';
                    
                    // Additional info
                    if (!empty($supplier->notes)) {
                        $html .= '<div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 15px;">';
                        $html .= '<strong>📝 Notes:</strong><br>' . nl2br(e($supplier->notes));
                        $html .= '</div>';
                    }
                    
                    // Action buttons
                    $html .= '<div style="text-align: center; margin-top: 20px;">';
                    $html .= '<a href="' . admin_url('suppliers/' . $supplier->id . '/edit') . '" class="btn btn-primary" target="_blank">';
                    $html .= '<i class="fa fa-edit"></i> Edit Supplier';
                    $html .= '</a>';
                    $html .= '</div>';
                } else {
                    $html .= '<p style="color: #999; text-align: center;">Supplier not found (ID: ' . $model->supplier_id . ')</p>';
                }
            } catch (\Exception $e) {
                $html .= '<div style="padding: 20px; background: #ffebee; border-left: 4px solid #f44336; border-radius: 4px;">';
                $html .= '<strong>⚠️ Error:</strong> Could not load supplier information.';
                $html .= '</div>';
            }
        }
        
        // Product-supplier specific info
        $html .= '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">';
        $html .= '<h4>Product Purchase Info</h4>';
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">';
        
        $html .= '<div><strong>Last Purchase Price:</strong> UGX ' . number_format($model->buying_price) . '</div>';
        $html .= '<div><strong>Current Selling Price:</strong> UGX ' . number_format($model->selling_price) . '</div>';
        
        if ($model->reorder_level) {
            $html .= '<div><strong>Reorder Level:</strong> ' . number_format($model->reorder_level) . ' units</div>';
        }
        
        if ($model->reorder_quantity) {
            $html .= '<div><strong>Reorder Quantity:</strong> ' . number_format($model->reorder_quantity) . ' units</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => '🚚 Supplier: ' . $model->name,
            'width' => '700px'
        ]);
    }
}
