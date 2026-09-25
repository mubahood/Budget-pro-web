<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialRecord;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Services\Shop\SupplierService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Suppliers (basic, P2-4): CRUD, statement (receipts − payments), payments. */
class SupplierController extends BaseCrudController
{
    protected string $modelClass = Supplier::class;

    protected string $resourceName = 'Supplier';

    protected array $writable = ['name', 'phone', 'email', 'address', 'payment_terms_days', 'lead_time_days', 'notes', 'is_active'];

    protected array $searchable = ['name', 'phone', 'email'];

    protected array $sortable = ['id', 'name', 'balance', 'created_at'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function statement(Request $request, $id)
    {
        /** @var Supplier|null $s */
        $s = $this->findOwned($request, $id);
        if ($s === null) {
            return $this->notFound('Supplier not found.');
        }
        $entries = [];
        foreach (GoodsReceipt::withoutGlobalScopes()->where('supplier_id', $s->id)->get() as $g) {
            $entries[] = ['date' => (string) $g->received_on?->toDateString(), 'type' => 'receipt', 'ref' => $g->number, 'description' => 'Goods received '.$g->number, 'debit' => 0.0, 'credit' => round((float) $g->total_cost, 2)];
            if ((float) $g->amount_paid > 0) {
                $entries[] = ['date' => (string) $g->received_on?->toDateString(), 'type' => 'payment', 'ref' => $g->number, 'description' => 'Paid on delivery', 'debit' => round((float) $g->amount_paid, 2), 'credit' => 0.0];
            }
        }
        foreach (FinancialRecord::withoutGlobalScopes()->where('source_type', 'supplier_payment')->where('source_id', $s->id)->get() as $p) {
            $entries[] = ['date' => (string) $p->date?->toDateString(), 'type' => 'payment', 'ref' => $p->receipt, 'description' => 'Payment ('.$p->payment_method.')', 'debit' => round((float) $p->amount, 2), 'credit' => 0.0];
        }
        foreach (\App\Models\PurchaseReturn::withoutGlobalScopes()->where('supplier_id', $s->id)->get() as $r) {
            $entries[] = ['date' => (string) $r->returned_on->toDateString(), 'type' => 'return', 'ref' => $r->number, 'description' => 'Goods returned'.($r->reason ? ": {$r->reason}" : ''), 'debit' => round((float) $r->total_value, 2), 'credit' => 0.0];
            if ((float) $r->refund_amount > 0) {
                $entries[] = ['date' => (string) $r->returned_on->toDateString(), 'type' => 'refund', 'ref' => $r->number, 'description' => 'Refund received', 'debit' => 0.0, 'credit' => round((float) $r->refund_amount, 2)];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $running = 0.0;
        foreach ($entries as &$e) {
            $running = round($running + $e['credit'] - $e['debit'], 2);
            $e['balance'] = $running;
        }

        return $this->success(['supplier' => $s, 'entries' => $entries, 'closing_balance' => $running], 'Statement.');
    }

    public function pay(Request $request, $id)
    {
        /** @var Supplier|null $s */
        $s = $this->findOwned($request, $id);
        if ($s === null) {
            return $this->notFound('Supplier not found.');
        }
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['nullable', 'string', 'max:30'], 'reference' => ['nullable', 'string', 'max:191']]);
        try {
            $row = (new SupplierService())->pay($s, (float) $data['amount'], $data['method'] ?? 'cash', (int) $request->user()->id, $data['reference'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['ledger' => $row, 'supplier' => $s->fresh()], 'Payment recorded.');
    }
}
