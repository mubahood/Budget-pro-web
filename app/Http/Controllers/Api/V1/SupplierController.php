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
        $data = $request->validate(SupplierRules::paymentRules());
        try {
            $row = (new SupplierService())->pay($s, (float) $data['amount'], $data['method'] ?? 'cash', (int) $request->user()->id, $data['reference'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['ledger' => $row, 'supplier' => $s->fresh()], 'Payment recorded.');
    }
}
