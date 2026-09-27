<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\Concerns\StoreFeatureGate;
use App\Http\Controllers\Controller;
use App\Services\Shop\DebtService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Debtors (who owes the shop: customer accounts AND credit sales under a typed name) and creditors
 * (suppliers the shop owes), like the web Debts screen, through DebtService.
 *
 * A debtor's key is 'c:<customer id>' or 'n:<lower-cased name>'. It goes in the path URL-encoded
 * (debts/c%3A12/sales), or — for a name with a slash — as `key` in the query/body (debts/sales?key=…).
 */
class DebtController extends Controller
{
    use ApiResponse, StoreFeatureGate;

    public function __construct(private readonly DebtService $debts)
    {
    }

    private function key(Request $request, ?string $key): string
    {
        return trim(rawurldecode((string) ($key ?? $request->input('key', ''))));
    }

    /** GET debts?tab=debtors|creditors&q= */
    public function index(Request $request)
    {
        if ($deny = $this->needsAny($request, 'sell', 'view_reports', 'restock')) {
            return $deny;
        }
        $data = $request->validate(['tab' => ['nullable', 'in:debtors,creditors,owed,owing'], 'q' => ['nullable', 'string', 'max:100']]);
        $cid = (int) $this->company($request)->id;
        $q = trim((string) ($data['q'] ?? ''));
        if (in_array($data['tab'] ?? 'debtors', ['creditors', 'owing'], true)) {
            if ($deny = $this->needsAny($request, 'restock', 'view_reports')) {
                return $deny;
            }
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $rows = DB::table('suppliers')->where('company_id', $cid)->where('is_deleted', 0)->where('balance', '>', 0)
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
                ->orderByDesc('balance')->get(['id', 'uuid', 'name', 'phone', 'balance'])
                ->map(fn ($s) => ['key' => 's:'.$s->id, 'type' => 'supplier', 'supplier_id' => (int) $s->id, 'supplier_uuid' => $s->uuid, 'name' => (string) $s->name,
                    'phone' => $s->phone, 'owed' => round((float) $s->balance, 2)])->values()->all();

            return $this->success($rows, 'Creditors.', 200, ['tab' => 'creditors', 'total' => round(array_sum(array_column($rows, 'owed')), 2), 'count' => count($rows)]);
        }
        $rows = $this->debts->debtors($cid, $q);
        $uuids = DB::table('customers')->where('company_id', $cid)->whereIn('id', array_filter(array_column($rows, 'customer_id')))->pluck('uuid', 'id');
        foreach ($rows as &$r) {
            $r['customer_uuid'] = $r['customer_id'] ? ($uuids[$r['customer_id']] ?? null) : null;
        }

        return $this->success($rows, 'Debtors.', 200, ['tab' => 'debtors', 'total' => round(array_sum(array_column($rows, 'owed')), 2), 'count' => count($rows)]);
    }

    /** GET debts/{key}/sales (or debts/sales?key=) — the open sales behind a debtor, oldest first. */
    public function sales(Request $request, ?string $key = null)
    {
        if ($deny = $this->needsAny($request, 'sell', 'view_reports', 'restock')) {
            return $deny;
        }
        $key = $this->key($request, $key);
        if (! preg_match('/^[cn]:/', $key)) {
            return $this->error('Unknown debtor.', 422, ['code' => 'invalid_key']);
        }
        $rows = $this->debts->openSales((int) $this->company($request)->id, $key);
        $uuids = DB::table('sale_records')->whereIn('id', $rows->pluck('id'))->pluck('uuid', 'id');

        return $this->success($rows->map(fn ($s) => (array) $s + ['uuid' => $uuids[$s->id] ?? null])->values(), 'Open sales.', 200,
            ['key' => $key, 'owed' => round((float) $rows->sum('balance'), 2)]);
    }

    /** POST debts/{key}/receive (or debts/receive {key}) {amount, method, reference?, shift_id?} */
    public function receive(Request $request, ?string $key = null)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys(array_diff_key(config('onboarding.payment_methods', ['cash' => 'Cash']), ['credit' => 1])))],
            'reference' => ['nullable', 'string', 'max:100'],
            'shift_id' => ['nullable', 'integer'],
        ]);
        $cid = (int) $this->company($request)->id;
        $key = $this->key($request, $key);
        if (! preg_match('/^[cn]:/', $key)) {
            return $this->error('Unknown debtor.', 422, ['code' => 'invalid_key']);
        }
        if (! empty($data['shift_id']) && ! DB::table('shifts')->where('company_id', $cid)->where('id', $data['shift_id'])->exists()) {
            return $this->error('Shift not found.', 422, ['code' => 'shift_not_found']);
        }
        try {
            $applied = $this->debts->receive($cid, $key, (float) $data['amount'], $data['method'], (int) $request->user()->id, $data['reference'] ?? null, $data['shift_id'] ?? null);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFound('Debtor not found.');
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $debtor = collect($this->debts->debtors($cid))->firstWhere('key', $key);

        return $this->success(['received' => $applied, 'debtor' => $debtor, 'owed' => $debtor['owed'] ?? 0.0], 'Payment received.');
    }

    /** POST debts/{key}/adopt (or debts/adopt {key}) {name, phone?} — a name becomes a customer account; its sales move onto it. */
    public function adopt(Request $request, ?string $key = null)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30']]);
        $cid = (int) $this->company($request)->id;
        try {
            $customer = $this->debts->adopt($cid, $this->key($request, $key), (int) $request->user()->id, $data);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
        $customer = $customer->fresh() ?? $customer;

        return $this->success(['key' => 'c:'.$customer->id, 'customer' => ['id' => $customer->id, 'uuid' => $customer->uuid, 'name' => $customer->name,
            'phone' => $customer->phone, 'balance' => round((float) $customer->balance, 2), 'server_seq' => (int) $customer->server_seq]], $customer->name.' is now a customer.');
    }
}
