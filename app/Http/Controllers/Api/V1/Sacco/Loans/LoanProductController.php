<?php

namespace App\Http\Controllers\Api\V1\Sacco\Loans;

use App\Http\Controllers\Api\V1\Sacco\SaccoController;
use App\Models\SaccoLoanProduct;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\SaccoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Loan products (04 §13.2): read with sacco_view, changed with sacco_settings; the preview is the key facts a member sees. */
class LoanProductController extends SaccoController
{
    /** GET sacco/loan-products */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->success(SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderBy('code')->get()->map(fn ($p) => LoanPresent::product($p))->all());
    }

    /** GET sacco/loan-products/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);

        return $p === null ? $this->notFound('Not found.') : $this->success(LoanPresent::product($p));
    }

    /** POST sacco/loan-products (sacco_settings): fields, or a preset (pdm, emyooga, vsla) with overrides. */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            SaccoAccess::authorize($this->user($request), 'sacco_settings');

            return $this->success(LoanPresent::product((new LoanProducts())->create($this->cid($request), $request->all(), (int) $this->user($request)->id)), 'Product created.', 201);
        });
    }

    /** PUT sacco/loan-products/{id} (sacco_settings) */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            SaccoAccess::authorize($this->user($request), 'sacco_settings');

            return $this->success(LoanPresent::product((new LoanProducts())->update($this->cid($request), $id, $request->all(), (int) $this->user($request)->id)), 'Product saved.');
        });
    }

    /** POST sacco/loan-products/{id}/preview {amount_minor, term, disburse_on}: schedule and key facts */
    public function preview(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $amount = $request->input('amount_minor');
        $term = $request->input('term');
        if (! is_int($amount) || ! is_int($term)) {
            return $this->error('Send amount_minor and term as whole numbers.', 422, ['code' => 'invalid_amount']);
        }

        return $this->attempt(fn () => $this->success((new LoanProducts())->preview($this->cid($request), $id, $amount, $term, $request->input('disburse_on'))));
    }
}
