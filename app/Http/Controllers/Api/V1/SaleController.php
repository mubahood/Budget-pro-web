<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales / POS.
 *
 * Reads (list/show) come from BaseCrudController. Creating a sale is a checkout
 * operation: it persists the header + line items, then runs the model's
 * processAndCompute() which snapshots costs, creates stock movements (deducting
 * inventory), computes profit, and generates the receipt number.
 */
class SaleController extends BaseCrudController
{
    protected string $modelClass = SaleRecord::class;
    protected string $resourceName = 'Sale';

    protected array $searchable = ['customer_name', 'customer_phone', 'receipt_number', 'invoice_number'];
    protected array $sortable = ['id', 'sale_date', 'total_amount', 'created_at'];
    protected array $filterable = ['payment_status', 'status', 'payment_method'];
    protected array $listWith = ['saleRecordItems'];
    protected array $showWith = ['saleRecordItems', 'createdBy'];
    protected string $optionLabel = 'receipt_number';

    // Only these header fields may be edited after a sale is created.
    protected array $writable = ['customer_name', 'customer_phone', 'customer_address', 'amount_paid', 'payment_method', 'payment_status', 'status', 'notes'];

    /**
     * Create a sale (POS checkout).
     */
    public function store(Request $request)
    {
        return $this->checkout($request);
    }

    public function checkout(Request $request)
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'sale_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => [
                'required',
                Rule::exists('stock_items', 'id')->where('company_id', $companyId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $period = FinancialPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'Active')
            ->first();

        if ($period === null) {
            return $this->error('No active financial period. Please create/activate one before recording sales.', 422);
        }

        $sale = new SaleRecord();
        $sale->company_id = $companyId;
        $sale->financial_period_id = $period->id;
        $sale->created_by_id = (int) $request->user()->id;
        $sale->sale_date = $data['sale_date'] ?? now();
        $sale->customer_name = $data['customer_name'] ?? 'Walk-in Customer';
        $sale->customer_phone = $data['customer_phone'] ?? null;
        $sale->customer_address = $data['customer_address'] ?? null;
        $sale->payment_method = $data['payment_method'] ?? 'Cash';
        $sale->amount_paid = $data['amount_paid'] ?? 0;
        $sale->notes = $data['notes'] ?? null;
        $sale->status = 'completed';
        $sale->save(); // header first (creating hook generates receipt/invoice numbers)

        foreach ($data['items'] as $line) {
            $item = new SaleRecordItem();
            $item->sale_record_id = $sale->id;
            $item->stock_item_id = $line['stock_item_id'];
            $item->quantity = $line['quantity'];
            $item->unit_price = $line['unit_price'] ?? 0;
            $item->save();
        }

        $sale->load('saleRecordItems');
        $result = $sale->processAndCompute();

        if (! ($result['success'] ?? false)) {
            // Roll back the half-created sale so we don't leave an empty header.
            $sale->saleRecordItems()->delete();
            $sale->delete();

            return $this->error($result['message'] ?? 'Sale could not be processed.', 422);
        }

        $fresh = SaleRecord::withoutGlobalScopes()->with(['saleRecordItems'])->find($sale->id);

        return $this->created($fresh, 'Sale recorded successfully.');
    }

    /**
     * Limited header update (payment / customer details only — not line items).
     */
    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'customer_name' => ['sometimes', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'amount_paid' => ['sometimes', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
