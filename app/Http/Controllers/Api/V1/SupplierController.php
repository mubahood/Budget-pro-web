<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\Supplier;
use App\Services\Shop\SupplierService;
use App\Support\Rules\SupplierRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Suppliers (basic, P2-4): CRUD, statement (receipts − payments), payments. */
class SupplierController extends BaseCrudController
{
    protected string $modelClass = Supplier::class;

    protected string $resourceName = 'Supplier';

    protected array $writable = SupplierRules::WRITABLE;

    protected array $searchable = ['name', 'phone', 'email'];

    protected array $sortable = ['id', 'name', 'balance', 'created_at'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return SupplierRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    public function statement(Request $request, $id)
    {
        /** @var Supplier|null $s */
        $s = $this->findOwned($request, $id);
        if ($s === null) {
            return $this->notFound('Supplier not found.');
        }
        $st = (new SupplierService())->statement($s);

        return $this->success(['supplier' => $s, 'entries' => $st['entries'], 'closing_balance' => $st['closing_balance']], 'Statement.');
    }

    public function pay(Request $request, $id)
    {
        /** @var Supplier|null $s */
        $s = $this->findOwned($request, $id);
        if ($s === null) {
            return $this->notFound('Supplier not found.');
        }
        $data = $request->validate(SupplierRules::paymentRules() + ['client_uuid' => ['nullable', 'uuid']]);
        $svc = new SupplierService();
        $pay = fn () => $svc->pay($s, (float) $data['amount'], $data['method'] ?? 'cash', (int) $request->user()->id, $data['reference'] ?? null, $data['client_uuid'] ?? null);
        try {
            try {
                $row = $pay();
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $row = $pay(); // the same client_uuid saved by a concurrent retry: answer with that payment
            }
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        // S6: a retry with a client_uuid already used answers with the original payment (200, not a second one).
        if (! $row->wasRecentlyCreated) {
            return $this->success(['ledger' => $row, 'supplier' => $s->fresh(), 'replayed' => true], 'Payment already recorded.');
        }

        return $this->created(['ledger' => $row, 'supplier' => $s->fresh()], 'Payment recorded.');
    }
}
