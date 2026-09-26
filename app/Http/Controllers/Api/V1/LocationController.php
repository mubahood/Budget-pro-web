<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use App\Support\Rules\LocationRules;
use App\Support\Rules\TransferRules;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Locations, stock per location, transfers and which phone sells where (plan P4-4).
 *  GET/POST locations · PUT locations/{id} · GET stock-levels?stock_item_id= · GET/POST stock-transfers
 *  PUT devices/{id}/location { location_id }
 */
class LocationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        LocationStock::defaultLocation($companyId);
        $rows = DB::table('locations')->where('company_id', $companyId)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($l) => (array) $l + ['phones' => DB::table('devices')->where('company_id', $companyId)->where('location_id', $l->id)->count(),
                'stock_value' => round((float) DB::table('stock_levels as s')->join('stock_items as p', 'p.id', '=', 's.stock_item_id')->where('s.location_id', $l->id)->where('s.quantity', '>', 0)->sum(DB::raw('s.quantity * p.buying_price')), 2)]);

        return $this->success($rows, 'Locations.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(LocationRules::rules((int) $request->user()->company_id));
        try {
            $id = $this->transfers->createLocation(Company::withoutGlobalScopes()->findOrFail($request->user()->company_id), $data['name'], $data['address'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(DB::table('locations')->find($id), 'Location added.');
    }

    public function update(Request $request, $id)
    {
        $companyId = (int) $request->user()->company_id;
        if (! DB::table('locations')->where('company_id', $companyId)->where('id', $id)->exists()) {
            return $this->notFound('Location not found.');
        }
        $data = $request->validate(LocationRules::rules($companyId, (int) $id, true));
        try {
            $loc = $this->transfers->updateLocation($companyId, (int) $id, $data);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($loc, 'Location updated.');
    }

    public function levels(Request $request)
    {
        return $this->success($this->transfers->levels((int) $request->user()->company_id, $request->integer('stock_item_id') ?: null), 'Stock per location.');
    }

    public function transfersIndex(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        $rows = DB::table('stock_transfers as t')->where('t.company_id', $companyId)->join('locations as f', 'f.id', '=', 't.from_location_id')->join('locations as to', 'to.id', '=', 't.to_location_id')
            ->orderByDesc('t.id')->limit(200)->get(['t.id', 't.number', 't.created_at', 'f.name as from', 'to.name as to', 't.notes']);

        return $this->success($rows, 'Transfers.');
    }

    public function transfer(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate(TransferRules::rules($companyId));
        try {
            $id = $this->transfers->transfer($companyId, (int) $request->user()->id, (int) $data['from_location_id'], (int) $data['to_location_id'], $data['items'], $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['id' => $id, 'number' => DB::table('stock_transfers')->where('id', $id)->value('number'), 'levels' => $this->transfers->levels($companyId)], 'Stock moved.');
    }

    public function deviceLocation(Request $request, $id)
    {
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate(['location_id' => ['nullable', 'integer']]);
        if (! empty($data['location_id'])) {
            try {
                LocationStock::assertLocation($companyId, (int) $data['location_id']);
            } catch (BusinessRuleException $e) {
                return $this->error($e->getMessage(), 422, $e->toErrors());
            }
        }
        $n = DB::table('devices')->where('company_id', $companyId)->where('id', $id)->update(['location_id' => $data['location_id'] ?? null, 'updated_at' => now()]);

        return $n ? $this->success(null, 'This phone now sells from that location.') : $this->notFound('Device not found.');
    }
}
