<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Services\Shop\CustomerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Customers + debt book (plan A8, P2-4).
 *  GET  customers?filter[has_balance]=1   ·  GET customers/{id}/statement?from&to
 *  POST customers/{id}/payments { amount, method, reference?, client_uuid? }  — allocated oldest-first
 */
class CustomerController extends BaseCrudController
{
    protected string $modelClass = Customer::class;

    protected string $resourceName = 'Customer';

    protected array $writable = \App\Support\Rules\CustomerRules::WRITABLE;

    protected array $searchable = ['name', 'phone', 'email'];

    protected array $sortable = ['id', 'name', 'balance', 'created_at'];

    protected array $filterable = ['is_active', 'balance'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return \App\Support\Rules\CustomerRules::rules($this->companyId($request), $existing?->getKey(), $existing !== null);
    }

    public function statement(Request $request, $id)
    {
        /** @var Customer|null $c */
        $c = $this->findOwned($request, $id);
        if ($c === null) {
            return $this->notFound('Customer not found.');
        }
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->success((new CustomerService())->statement($c, $data['from'] ?? null, $data['to'] ?? null), 'Statement.');
    }

    public function pay(Request $request, $id)
    {
        /** @var Customer|null $c */
        $c = $this->findOwned($request, $id);
        if ($c === null) {
            return $this->notFound('Customer not found.');
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:191'],
            'client_uuid' => ['nullable', 'uuid'],
            'shift_id' => ['nullable', 'integer'],
        ]);
        try {
            $payments = (new CustomerService())->receivePayment($c, (float) $data['amount'], $data['method'] ?? 'cash', (int) $request->user()->id, $data['reference'] ?? null, $data['client_uuid'] ?? null, $data['shift_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['payments' => $payments, 'customer' => $c->fresh()], 'Payment recorded.');
    }
}
