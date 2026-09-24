<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Extra barcodes per product, unique per company (plan A8 `product-barcodes`). */
class ProductBarcodeController extends BaseCrudController
{
    protected string $modelClass = ProductBarcode::class;

    protected string $resourceName = 'Barcode';

    protected array $writable = ['stock_item_id', 'barcode', 'unit_id'];

    protected array $searchable = ['barcode'];

    protected array $filterable = ['stock_item_id'];

    protected string $optionLabel = 'barcode';

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = $this->companyId($request);

        return [
            'stock_item_id' => [$existing ? 'sometimes' : 'required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'barcode' => [$existing ? 'sometimes' : 'required', 'string', 'max:64',
                Rule::unique('product_barcodes', 'barcode')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($existing?->getKey())],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId)],
        ];
    }
}
