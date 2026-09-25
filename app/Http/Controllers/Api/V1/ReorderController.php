<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shop\ProductStatsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reorder list (plan A5/A7, P4-3): GET reorder-suggestions · POST reorder-suggestions/orders { items: [{stock_item_id, quantity, supplier_id?, unit_cost?}] } */
class ReorderController extends Controller
{
    use ApiResponse;

    public function index(Request $request, ProductStatsService $stats)
    {
        return $this->success($stats->suggestions((int) $request->user()->company_id), 'Reorder suggestions.');
    }

    public function orders(Request $request, ProductStatsService $stats)
    {
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $orders = $stats->createOrders($companyId, (int) $request->user()->id, $data['items']);

        return $this->created(collect($orders)->map(fn ($o) => $o->load(['supplier', 'items.product'])), count($orders).' draft purchase order(s) created.');
    }
}
