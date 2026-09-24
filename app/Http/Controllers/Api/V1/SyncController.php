<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Device;
use App\Models\StockItem;
use App\Models\SyncConflict;
use App\Services\Shop\StockService;
use App\Services\Sync\SyncApplier;
use App\Services\Sync\SyncPuller;
use App\Services\Sync\SyncRegistry;
use App\Support\Sync\SyncSequence;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sync protocol v2 (plan Appendix A, P1-3/P1-5).
 *
 *  POST /sync/push                      batches of ops, one transaction per batch, idempotent
 *  GET  /sync/pull?table=&since_seq=&limit=   (or tables=a,b,c) seq cursor + paging
 *  POST /sync/bootstrap                 first-install snapshot, paged
 *  GET  /sync/conflicts                 conflict inbox
 *  POST /sync/conflicts/{id}/resolve    { choice: mine|server|merged|counted|ignore, data? }
 *
 * Push requires a registered, non-revoked device (X-Device-Id header or body device_id).
 */
class SyncController extends Controller
{
    use ApiResponse;

    private function company(Request $request): Company
    {
        return $request->attributes->get('company') ?? Company::findOrFail($request->user()->company_id);
    }

    private function device(Request $request, Company $company): ?Device
    {
        $id = (string) ($request->header('X-Device-Id') ?: $request->input('device_id', ''));
        if ($id === '') {
            return null;
        }
        $device = Device::withoutGlobalScopes()->where('company_id', $company->id)->where('device_id', $id)->first();
        if ($device) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $device;
    }

    public function push(Request $request)
    {
        $request->validate([
            'batches' => ['required', 'array', 'min:1', 'max:100'],
            'batches.*.batch_uuid' => ['required', 'string', 'max:36'],
            'batches.*.ops' => ['required', 'array', 'min:1', 'max:500'],
            'batches.*.ops.*.table' => ['required', 'string'],
            'batches.*.ops.*.uuid' => ['required', 'string', 'max:36'],
            'batches.*.ops.*.action' => ['nullable', 'string', 'in:insert,upsert,update,delete,void'],
        ]);
        $company = $this->company($request);
        $device = $this->device($request, $company);
        if ($device === null) {
            return $this->error('Register this device first (POST /devices/register) and send X-Device-Id.', 422, ['code' => 'device_not_registered']);
        }
        if ($device->isRevoked()) {
            return $this->error('This device has been revoked by the owner.', 403, ['code' => 'device_revoked']);
        }

        $out = app(SyncApplier::class)->push($company, $device, (int) $request->user()->id, $request->input('batches'));
        $out['entitlement_state'] = $company->accessState();
        $out['device_time_offset_ms'] = $request->filled('device_time') ? SyncSequence::nowMs() - (int) $request->input('device_time') : null;

        return $this->success($out, 'Sync push processed.');
    }

    public function pull(Request $request)
    {
        $data = $request->validate([
            'table' => ['required_without:tables', 'nullable', 'string'],
            'tables' => ['required_without:table', 'nullable', 'string'],
            'since_seq' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SyncPuller::MAX_LIMIT],
        ]);
        $company = $this->company($request);
        $puller = app(SyncPuller::class);
        $since = (int) ($data['since_seq'] ?? 0);
        $limit = (int) ($data['limit'] ?? 500);

        try {
            if (! empty($data['tables'])) {
                $out = [];
                foreach (array_filter(array_map('trim', explode(',', $data['tables']))) as $table) {
                    $out[$table] = $puller->pull((int) $company->id, $table, $since, $limit);
                }

                return $this->success(['tables' => $out, 'server_time' => SyncSequence::nowMs()], 'Pulled.');
            }
            $res = $puller->pull((int) $company->id, (string) $data['table'], $since, $limit);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        if ($device = $this->device($request, $company)) {
            $device->forceFill(['last_pull_seq' => max((int) $device->last_pull_seq, $res['next_seq'])])->saveQuietly();
        }

        return $this->success($res + ['server_time' => SyncSequence::nowMs()], 'Pulled.');
    }

    public function bootstrap(Request $request)
    {
        $data = $request->validate([
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $company = $this->company($request);
        $tables = $data['tables'] ?? SyncRegistry::keys();
        $out = app(SyncPuller::class)->bootstrap((int) $company->id, $tables, (int) ($data['page'] ?? 1), (int) ($data['page_size'] ?? 500));

        return $this->success($out + ['server_time' => SyncSequence::nowMs(), 'tables_order' => SyncRegistry::keys()], 'Bootstrap snapshot.');
    }

    public function conflicts(Request $request)
    {
        $state = $request->query('state', 'open');
        $rows = SyncConflict::withoutGlobalScopes()->where('company_id', $request->user()->company_id)
            ->when($state !== 'all', fn ($q) => $q->where('state', $state))
            ->orderByDesc('id')->limit(200)->get();

        return $this->success($rows, 'Conflicts listed.');
    }

    public function resolve(Request $request, $id)
    {
        $data = $request->validate([
            'choice' => ['required', 'in:mine,server,merged,counted,ignore'],
            'data' => ['nullable', 'array'],
            'counted_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        $company = $this->company($request);
        $conflict = SyncConflict::withoutGlobalScopes()->where('company_id', $company->id)->find($id);
        if ($conflict === null) {
            return $this->notFound('Conflict not found.');
        }
        if ($conflict->state !== 'open') {
            return $this->success($conflict, 'Already resolved.');
        }

        try {
            if (in_array($data['choice'], ['mine', 'merged'], true) && $conflict->code !== 'stock_exception') {
                $this->reapply($request, $company, $conflict, $data['choice'] === 'merged' ? ($data['data'] ?? []) : null);
            }
            if ($data['choice'] === 'counted' && $conflict->code === 'stock_exception') {
                $this->recordCount($request, $company, $conflict, (float) ($data['counted_quantity'] ?? 0));
            }
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        $conflict->state = 'resolved';
        $conflict->resolution = $data['choice'];
        $conflict->resolved_by_id = $request->user()->id;
        $conflict->resolved_at = now();
        $conflict->save();

        return $this->success($conflict, 'Conflict resolved.');
    }

    /** "Keep mine" / "merged": re-apply the device's op on top of the current server version. */
    private function reapply(Request $request, Company $company, SyncConflict $conflict, ?array $merged): void
    {
        $op = $conflict->local_json ?? [];
        $current = $conflict->server_json ?? [];
        $op['version'] = (int) ($current['version'] ?? 0);
        $op['client_updated_at'] = SyncSequence::nowMs();
        $op['action'] = $op['action'] ?? 'update';
        if ($merged !== null) {
            $op['data'] = array_merge($op['data'] ?? [], $merged);
            unset($op['changed_fields']);
        }
        if ($op['action'] === 'delete') {
            $op['version'] = PHP_INT_MAX;
        }
        $result = app(SyncApplier::class)->applyBatch($company, null, (int) $request->user()->id, [
            'batch_uuid' => (string) Str::uuid(), 'kind' => 'conflict_resolution', 'ops' => [$op + ['op_uuid' => (string) Str::uuid(), 'table' => $conflict->table_name, 'uuid' => $conflict->row_uuid]],
        ]);
        if (($result['status'] ?? '') !== 'applied') {
            throw BusinessRuleException::make('resolution_failed', 'Could not apply your version: '.($result['ops'][0]['message'] ?? $result['ops'][0]['code'] ?? 'unknown'));
        }
    }

    /** Stock exception "Mark counted": adjust the product to the counted quantity with one movement. */
    private function recordCount(Request $request, Company $company, SyncConflict $conflict, float $counted): void
    {
        $productUuid = $conflict->local_json['product_uuid'] ?? null;
        $product = $productUuid ? StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('uuid', $productUuid)->first() : null;
        if ($product === null) {
            throw BusinessRuleException::make('product_not_found', 'The product for this conflict no longer exists.');
        }
        $diff = round($counted - (float) $product->current_quantity, 3);
        if ($diff == 0.0) {
            return;
        }
        (new StockService())->record([
            'stock_item_id' => $product->id, 'type' => $diff > 0 ? 'Adjustment In' : 'Adjustment Out', 'quantity' => abs($diff),
            'description' => 'Stock count after offline oversell (conflict #'.$conflict->id.')', 'created_by_id' => (int) $request->user()->id, 'allow_negative' => true,
        ]);
    }
}
