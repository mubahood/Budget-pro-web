<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Shop\ProductStatsService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

/** Reorder list on the web (plan A5/A7, P4-3): tick, adjust, turn into draft purchase orders per supplier. */
class ReorderSuggestionController extends Controller
{
    public function index(Content $content, ProductStatsService $stats)
    {
        return $content->title('Reorder list')->description('Products at or below their minimum, with a suggested quantity')
            ->body(view('admin.reorder-suggestions', ['rows' => $stats->suggestions((int) Admin::user()->company_id)]));
    }

    public function orders(ProductStatsService $stats)
    {
        $picks = collect((array) request('items', []))->filter(fn ($r) => ! empty($r['pick']) && (float) ($r['quantity'] ?? 0) > 0)
            ->map(fn ($r, $id) => ['stock_item_id' => (int) $id, 'quantity' => $r['quantity'], 'supplier_id' => ($r['supplier_id'] ?? '') !== '' ? (int) $r['supplier_id'] : null])->values()->all();
        if ($picks === []) {
            admin_warning('Nothing selected', 'Tick the products to order.');

            return back();
        }
        $orders = $stats->createOrders((int) Admin::user()->company_id, (int) Admin::user()->id, $picks);
        admin_success('Draft orders created', collect($orders)->pluck('number')->implode(', ').'. Check and send them to your suppliers.');

        return redirect(admin_url('purchase-orders'));
    }
}
