<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class GenerateBarcode extends RowAction
{
    public $name = '🏷️ Generate Barcode';

    public function handle(Model $model)
    {
        // Generate barcode if not exists
        if (empty($model->barcode)) {
            // Generate EAN-13 format barcode (13 digits)
            $prefix = '880'; // Country code (Uganda example)
            $companyCode = str_pad(substr($model->company_id, 0, 4), 4, '0', STR_PAD_LEFT);
            $itemCode = str_pad(substr($model->id, 0, 5), 5, '0', STR_PAD_LEFT);
            
            // First 12 digits
            $barcode12 = $prefix . $companyCode . $itemCode;
            
            // Calculate check digit (EAN-13 algorithm)
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $digit = (int)$barcode12[$i];
                $sum += ($i % 2 === 0) ? $digit : $digit * 3;
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            
            $model->barcode = $barcode12 . $checkDigit;
            $model->save();

            return $this->response()->success('Barcode generated: ' . $model->barcode)->refresh();
        } else {
            return $this->response()->warning('Product already has barcode: ' . $model->barcode);
        }
    }

    public function dialog()
    {
        $this->confirm('Generate barcode for this product?', 'This will create a unique EAN-13 barcode.');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-xs btn-warning">
            <i class="fa fa-barcode"></i> Barcode
        </a>
HTML;
    }
}
