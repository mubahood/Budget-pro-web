<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSavingsProductVersion;
use App\Models\SaccoShareAccount;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\Shares\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Savings products and accounts, share accounts, share purchases and transfers (04 §13.2). */
class SavingsController extends SaccoController
{
    /** GET sacco/savings-products */
    public function products(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->success(SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderBy('code')->get()->map(fn ($p) => Present::product($p))->all());
    }

    /** GET sacco/savings-products/{id}: with every version of its terms. */
    public function product(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id) {
            $p = (new SavingsProducts())->find($this->cid($request), $id);

            return $this->success(Present::product($p) + ['versions' => SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $p->id)->orderBy('terms_version')->get()->toArray()]);
        });
    }

    /** POST sacco/savings-products (sacco_settings) */
    public function storeProduct(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created(Present::product((new SavingsProducts())->create($this->company($request), $request->all(), $this->user($request))), 'Product created.'));
    }

    /**
     * PUT sacco/savings-products/{id} (sacco_settings): name and is_active change at once; terms (rates, fees,
     * minimums, notice…) become a `product_terms_change` request for a second officer (202, with the request).
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $svc = new SavingsProducts();
        $terms = Arr::only($request->all(), SaccoSavingsProduct::TERMS);

        return $this->attempt(function () use ($request, $id, $svc, $terms) {
            $plain = Arr::only($request->all(), ['name', 'is_active']);
            $product = $plain === [] ? $svc->find($this->cid($request), $id) : $svc->update($this->company($request), $id, $plain, $this->user($request));
            if ($terms === []) {
                return $this->success(Present::product($product), 'Product saved.');
            }
            $approval = $svc->requestTermsChange($this->company($request), $id, $terms, $this->user($request), $request->input('effective_from'), $request->input('uuid'));

            return $this->success(['product' => Present::product($product->fresh()), 'approval' => Present::approval($approval, $this->user($request))], 'The new terms wait for a second officer.', 202);
        });
    }

    /** GET sacco/savings-accounts?member_id&product_id&status */
    public function accounts(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', (int) $v))->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', (int) $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->orderBy('account_no')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($a) => Present::savings($a))->all());
    }

    public function account(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success(Present::savings((new SavingsAccounts())->find($this->cid($request), $id))));
    }

    /** POST sacco/savings-accounts {member_id, product_id, opened_on?, uuid?} (sacco_teller) */
    public function open(Request $request): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'opened_on' => ['nullable', 'date'], 'uuid' => ['nullable', 'uuid']]);

        return $this->attempt(function () use ($request, $d) {
            if (! empty($d['uuid']) && ($existing = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('uuid', strtolower($d['uuid']))->first())) {
                return $this->success(Present::savings($existing), 'Account already opened.');
            }

            return $this->created(Present::savings((new SavingsAccounts())->open($this->company($request), (int) $d['member_id'], (int) $d['product_id'], $this->user($request), $d)), 'Account opened.');
        });
    }

    /** POST sacco/savings-accounts/{id}/freeze {reason} | unfreeze | close {reason} (sacco_approve) */
    public function freeze(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::savings((new SavingsAccounts())->freeze($this->company($request), $id, (string) $request->input('reason', ''), $this->user($request))), 'Account frozen.'));
    }

    public function unfreeze(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::savings((new SavingsAccounts())->unfreeze($this->company($request), $id, $this->user($request))), 'Account active again.'));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::savings((new SavingsAccounts())->close($this->company($request), $id, (string) $request->input('reason', ''), $this->user($request))), 'Account closed.'));
    }

    /** GET sacco/share-accounts?member_id */
    public function shareAccounts(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $this->cid($request))->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', (int) $v))
            ->orderBy('account_no')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($a) => Present::shares($a))->all());
    }

    /** POST sacco/shares/purchase {uuid?, member_id, amount_minor | shares_count, channel?, value_date?, reference?} (sacco_teller) */
    public function purchase(Request $request): JsonResponse
    {
        $d = $request->validate(['uuid' => ['nullable', 'uuid'], 'member_id' => ['required', 'integer'], 'amount_minor' => \App\Support\Rules\SaccoRules::minor(false, 1),
            'shares_count' => ['required_without:amount_minor', 'integer', 'min:1', \App\Support\Rules\SaccoRules::noFloat()], 'channel' => ['nullable', 'in:cash,bank'],
            'value_date' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:80'], 'narration' => ['nullable', 'string', 'max:255']]);
        if (isset($d['shares_count'])) {
            $d['shares_count'] = (int) $d['shares_count'];
        }

        return $this->attempt(function () use ($request, $d) {
            $r = (new ShareService())->purchase($this->company($request), $d, $this->user($request));

            return $r['replayed'] ? $this->success(Present::transaction($r['transaction']), 'Already recorded.') : $this->created(Present::transaction($r['transaction']), 'Shares bought.');
        });
    }

    /** POST sacco/shares/transfer {from_member_id, to_member_id, shares_count, reason, uuid?} (sacco_teller; a second officer approves) */
    public function transfer(Request $request): JsonResponse
    {
        $d = $request->validate(['from_member_id' => ['required', 'integer'], 'to_member_id' => ['required', 'integer'], 'shares_count' => ['required', 'integer', 'min:1', \App\Support\Rules\SaccoRules::noFloat()],
            'reason' => ['required', 'string', 'max:200'], 'uuid' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->success(Present::approval((new ShareService())->transfer($this->company($request), (int) $d['from_member_id'], (int) $d['to_member_id'],
            (int) $d['shares_count'], $d['reason'], $this->user($request), $d['uuid'] ?? null), $this->user($request)), 'The transfer waits for a second officer.', 202));
    }
}
