<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class PrintLabels extends BatchAction
{
    public $name = '🖨️ Print Labels';

    public function handle(Collection $collection, Request $request)
    {
        $labelType = $request->get('label_type', 'barcode');
        $copiesPerItem = intval($request->get('copies', 1));

        // Generate print data
        $printData = [];
        
        foreach ($collection as $model) {
            for ($i = 0; $i < $copiesPerItem; $i++) {
                $printData[] = [
                    'name' => $model->name,
                    'sku' => $model->sku,
                    'barcode' => $model->barcode ?? 'N/A',
                    'price' => 'UGX ' . number_format($model->selling_price),
                    'category' => $model->stock_sub_category->name ?? 'N/A',
                ];
            }
        }

        // Store print data in session for print page
        session(['print_labels_data' => $printData]);
        session(['print_labels_type' => $labelType]);

        // Return success with redirect to print page
        return $this->response()->success('Opening print preview...')->redirect(admin_url('print-labels'));
    }

    public function form()
    {
        $this->select('label_type', 'Label Type')
             ->options([
                 'barcode' => '🏷️ Barcode Only (Small)',
                 'price_tag' => '💰 Price Tag (Medium)',
                 'full_label' => '📦 Full Product Label (Large)',
             ])
             ->default('barcode')
             ->required();

        $this->number('copies', 'Copies per Product')
             ->attribute(['min' => 1, 'max' => 100])
             ->default(1)
             ->required()
             ->help('How many labels to print for each product');

        $this->html('<div class="alert alert-info" style="margin-top: 15px;">
            <strong><i class="fa fa-info-circle"></i> Label Types:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>Barcode Only:</strong> Small label (1.5" x 1") with barcode and SKU</li>
                <li><strong>Price Tag:</strong> Medium label (2" x 1.5") with barcode, name, and price</li>
                <li><strong>Full Label:</strong> Large label (3" x 2") with all product details</li>
            </ul>
            <p style="margin-top: 10px;"><strong>Tip:</strong> Use browser print dialog to adjust print settings.</p>
        </div>');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-primary print-labels">
            <i class="fa fa-print"></i> Print Labels
        </a>
HTML;
    }
}
