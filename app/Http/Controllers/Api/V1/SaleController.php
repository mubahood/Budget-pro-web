<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Services\Shop\SaleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales / POS (v1).
 *
 *  POST   sales/checkout            atomic checkout (idempotent on client_uuid)
 *  POST   sales                     alias of checkout
 *  GET    sales, sales/{id}         reads (tenant-scoped, with lines + payments)
 *  PATCH  sales/{id}                customer/notes edits; amount_paid increase records a payment
 *  POST   sales/{id}/payments       record a (partial) payment on a credit sale
 *  POST   sales/{id}/void           reverse stock + payments + ledger (nothing deleted)
 *  DELETE sales/{id}                refused (422) — use void
 */
class SaleController extends BaseCrudController
{
    protected string $modelClass = SaleRecord::class;

    protected string $resourceName = 'Sale';

    protected array $searchable = ['customer_name', 'customer_phone', 'receipt_number', 'invoice_number'];

    protected array $sortable = ['id', 'sale_date', 'total_amount', 'created_at'];

    protected array $filterable = ['payment_status', 'status', 'payment_method', 'sale_date', 'voided_at', 'customer_id', 'shift_id', 'created_by_id'];

    protected array $listWith = ['saleRecordItems'];

    protected array $showWith = ['saleRecordItems', 'payments', 'returns.items', 'customer', 'createdBy'];

    protected string $optionLabel = 'receipt_number';

    protected array $writable = ['customer_name', 'customer_phone', 'customer_address', 'amount_paid', 'payment_method', 'payment_status', 'notes'];

    public function store(Request $request)
    {
        return $this->checkout($request);
    }

    public function checkout(Request $request)
    {
        $companyId = $this->companyId($request);

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:191'],
            'sale_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['nullable', 'string', 'max:30'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:191'],
            'payments.*.provider' => ['nullable', 'string', 'max:50'],
        ]);

        $data['payments_explicit'] = $request->has('payments');
        if (! \App\Services\Team\Permissions::can($request->user(), 'discount') && $this->changesPrices($companyId, $data)) {
            return $this->error('Your role cannot give discounts or change prices.', 403, ['code' => 'forbidden', 'permission' => 'discount']);
        }
        try {
            $result = (new SaleService())->checkout($companyId, (int) $request->user()->id, $data);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        $sale = $this->transform($result['sale']);
        if ($result['replayed']) {
            return $this->success($sale, 'Sale already recorded (idempotent replay).');
        }

        return $this->created($sale, 'Sale recorded successfully.');
    }

    /** A discount, or a unit price different from the product's price in that unit. */
    private function changesPrices(int $companyId, array $data): bool
    {
        if ((float) ($data['discount_amount'] ?? 0) > 0) {
            return true;
        }
        foreach ($data['items'] as $line) {
            if ((float) ($line['discount_amount'] ?? 0) > 0) {
                return true;
            }
            if (! isset($line['unit_price'])) {
                continue;
            }
            $product = \App\Models\StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($line['stock_item_id']);
            $factor = ! empty($line['unit_id']) ? (float) (\App\Models\Unit::withoutGlobalScopes()->find($line['unit_id'])?->factor ?: 1) : 1.0;
            if ($product && abs((float) $line['unit_price'] - round((float) $product->selling_price * $factor, 2)) > 0.005) {
                return true;
            }
        }

        return false;
    }

    public function addPayment(Request $request, $id)
    {
        /** @var SaleRecord|null $sale */
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:191'],
            'provider' => ['nullable', 'string', 'max:50'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payment = (new SaleService())->addPayment($sale, $data, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        $fresh = $this->findOwned($request, $id, $this->showWith);

        return $this->created(['payment' => $payment, 'sale' => $this->transform($fresh)], 'Payment recorded.');
    }

    public function void(Request $request, $id)
    {
        /** @var SaleRecord|null $sale */
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $voided = (new SaleService())->void($sale, $data['reason'] ?? null, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($this->transform($voided), 'Sale voided; stock and ledger reversed.');
    }

    /** POST sales/{id}/returns { items:[{sale_item_id, quantity, restock?}], reason?, refund_method?, client_uuid?, shift_id? } */
    public function returns(Request $request, $id)
    {
        /** @var SaleRecord|null $sale */
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.restock' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_method' => ['nullable', 'string', 'max:30'],
            'client_uuid' => ['nullable', 'uuid'],
            'shift_id' => ['nullable', 'integer'],
        ]);
        try {
            $return = (new \App\Services\Shop\ReturnService())->create($sale, $data['items'], (int) $request->user()->id, $data['reason'] ?? null, $data['refund_method'] ?? 'cash', $data['client_uuid'] ?? null, $data['shift_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['return' => $return, 'sale' => $this->transform($this->findOwned($request, $id, $this->showWith))], 'Return recorded.');
    }

    /** GET sales/{id}/receipt.txt — WhatsApp-ready text. */
    public function receiptText(Request $request, $id)
    {
        /** @var SaleRecord|null $sale */
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }

        return response((new \App\Services\Shop\ReceiptService())->text($sale), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** GET sales/{id}/receipt.pdf */
    public function receiptPdf(Request $request, $id)
    {
        /** @var SaleRecord|null $sale */
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }

        return response((new \App\Services\Shop\ReceiptService())->pdf($sale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.($sale->receipt_number ?: $sale->id).'.pdf"',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $sale = $this->findOwned($request, $id);
        if ($sale === null) {
            return $this->notFound('Sale not found.');
        }

        return $this->error('Sales cannot be deleted. Void the sale instead (POST sales/{id}/void).', 422, ['code' => 'delete_not_allowed']);
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'customer_name' => ['sometimes', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'amount_paid' => ['sometimes', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', Rule::in(['Paid', 'Partial', 'Unpaid'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function transform(Model $model)
    {
        $model->setAttribute('is_voided', $model->getAttribute('voided_at') !== null);

        return $model;
    }
}
