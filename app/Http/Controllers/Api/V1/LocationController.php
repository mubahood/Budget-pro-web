<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:255']]);
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
        /** @var object{is_default: int|bool}|null $loc */
        $loc = DB::table('locations')->where('company_id', $companyId)->find($id);
        if (! $loc) {
            return $this->notFound('Location not found.');
        }
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120', Rule::unique('locations', 'name')->where('company_id', $companyId)->ignore($id)],
            'address' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        if (isset($data['is_active']) && ! $data['is_active'] && $loc->is_default) {
            return $this->error('The main location cannot be closed.', 422, ['code' => 'default_location']);
        }
        if (isset($data['is_active']) && ! $data['is_active'] && DB::table('stock_levels')->where('location_id', $id)->where('quantity', '!=', 0)->exists()) {
            return $this->error('Move or count the stock at this location to zero before closing it.', 422, ['code' => 'location_has_stock']);
        }
        DB::table('locations')->where('id', $id)->update($data + ['updated_at' => now()]);

        return $this->success(DB::table('locations')->find($id), 'Location updated.');
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
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'], 'to_location_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);
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
