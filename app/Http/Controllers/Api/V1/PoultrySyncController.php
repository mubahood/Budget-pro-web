<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PoultryBatch;
use App\Models\PoultryCustomer;
use App\Models\PoultryDailyRecord;
use App\Models\PoultryEggTransaction;
use App\Models\PoultryExpense;
use App\Models\PoultryFarmType;
use App\Models\PoultryFeedStock;
use App\Models\PoultryFeedType;
use App\Models\PoultryHealthEvent;
use App\Models\PoultryMortalityEvent;
use App\Models\PoultryProductionGuideTask;
use App\Models\PoultrySale;
use App\Models\PoultryVaccinationEvent;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Poultry sync — BACKEND_API_MASTER_TASKS.md §8.4/§9.4. Thin: resolves the
 * `table` key to a model and delegates to that model's PoultrySyncable /
 * PoultryReferenceSyncable trait for the actual push/pull/conflict logic,
 * matching the mobile client's already-built offline-first sync engine
 * (budget-pro-mobo: lib/poultry/services/poultry_sync_service.dart) field
 * for field.
 */
class PoultrySyncController extends Controller
{
    use ApiResponse;

    /** Tenant-scoped tables — company-isolated, push + pull. */
    private const TENANT_MODELS = [
        'batches' => PoultryBatch::class,
        'feed_types' => PoultryFeedType::class,
        'customers' => PoultryCustomer::class,
        'daily_records' => PoultryDailyRecord::class,
        'feed_stock' => PoultryFeedStock::class,
        'sales' => PoultrySale::class,
        'expenses' => PoultryExpense::class,
        'egg_tx' => PoultryEggTransaction::class,
        'mortality_events' => PoultryMortalityEvent::class,
        'health_events' => PoultryHealthEvent::class,
        'vacc_events' => PoultryVaccinationEvent::class,
    ];

    /** Platform-wide reference tables — pull-only, no company scoping. */
    private const REFERENCE_MODELS = [
        'farm_types' => PoultryFarmType::class,
        'production_guide_tasks' => PoultryProductionGuideTask::class,
    ];

    public function pull(Request $request)
    {
        $table = (string) $request->query('table');
        $since = (int) $request->query('since', 0);

        if (isset(self::REFERENCE_MODELS[$table])) {
            [$rows, $newCursor] = self::REFERENCE_MODELS[$table]::syncPull($since);

            return $this->success(['rows' => $rows, 'new_cursor' => $newCursor]);
        }

        if (! isset(self::TENANT_MODELS[$table])) {
            return $this->error('Unknown sync table: '.$table, 422);
        }

        $companyId = (int) $request->user()->company_id;
        [$rows, $newCursor] = self::TENANT_MODELS[$table]::syncPull($companyId, $since);

        return $this->success(['rows' => $rows, 'new_cursor' => $newCursor]);
    }

    public function push(Request $request)
    {
        $data = $request->validate([
            'table' => 'required|string',
            'uuid' => 'required|string|max:191',
            'json' => 'required|array',
            'is_delete' => 'sometimes|boolean',
        ]);

        $table = $data['table'];

        if (isset(self::REFERENCE_MODELS[$table])) {
            // Defensive: farm types / guide tasks are admin-authored only —
            // a farmer's app has no legitimate reason to push here, but
            // reject cleanly rather than silently accepting/ignoring.
            return $this->error('This is read-only reference data — pushes are not accepted.', 422);
        }

        if (! isset(self::TENANT_MODELS[$table])) {
            return $this->error('Unknown sync table: '.$table, 422);
        }

        $companyId = (int) $request->user()->company_id;
        $payload = $data['json'];
        $enteredBy = isset($payload['entered_by']) ? (string) $payload['entered_by'] : null;

        $result = self::TENANT_MODELS[$table]::syncPush(
            $companyId,
            $data['uuid'],
            $payload,
            (bool) ($data['is_delete'] ?? false),
            $enteredBy
        );

        return $this->success([
            'ok' => true,
            'conflict' => $result['conflict'],
            'server_data' => $result['server_data'],
        ]);
    }
}
