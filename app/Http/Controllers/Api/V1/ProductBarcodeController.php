<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Extra barcodes per product, unique per company (plan A8 `product-barcodes`). */
class ProductBarcodeController extends BaseCrudController
{
    protected string $modelClass = ProductBarcode::class;

    protected string $resourceName = 'Barcode';

    protected array $writable = \App\Support\Rules\ProductBarcodeRules::WRITABLE;

    protected array $searchable = ['barcode'];

    protected array $filterable = ['stock_item_id'];

    protected string $optionLabel = 'barcode';

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\ProductBarcodeRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }
}
