<?php

namespace App\Admin\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Look-ups for select2 fields in the admin forms (always scoped to the signed-in user's shop). */
class AjaxController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * Products to sell: active, not deleted, services included (they never run out).
     * Shape follows laravel-admin's select2 ajax contract: {data: [{id, text, ...}], next_page_url}.
     */
    public function products(): JsonResponse
    {
        $companyId = (int) Admin::user()->company_id;
        $q = trim((string) request('q', ''));
        $page = max(1, (int) request('page', 1));

        $query = DB::table('stock_items')
            ->select('id', 'name', 'sku', 'barcode', 'selling_price', 'current_quantity', 'track_stock', 'allow_negative_stock')
            ->where('company_id', $companyId)
            ->where('is_deleted', 0)
            ->where(fn ($w) => $w->where('is_active', 1)->orWhereNull('is_active'));

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($q, $like, $companyId) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', $q)
                    ->orWhereIn('id', DB::table('product_barcodes')->select('stock_item_id')
                        ->where('company_id', $companyId)->where('is_deleted', 0)->where('barcode', $q));
            });
        }

        $rows = $query->orderBy('name')->orderBy('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;

        $data = $rows->take(self::PER_PAGE)->map(function ($p) {
            $tracked = (bool) ($p->track_stock ?? true);
            $stock = round((float) $p->current_quantity, 3);
            $price = round((float) $p->selling_price, 2);
            $stockText = ! $tracked ? 'service' : ($stock > 0 ? SaleRecordController::qty($stock).' in stock' : 'out of stock');

            return [
                'id' => (int) $p->id,
                'text' => e($p->name.($p->sku ? ' ('.$p->sku.')' : '').' — '.$stockText.' — '.number_format($price)), // select2 here renders markup
                'name' => $p->name,
                'price' => $price,
                'stock' => $stock,
                'track_stock' => $tracked,
                'allow_negative_stock' => (bool) $p->allow_negative_stock,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'next_page_url' => $more ? admin_url('ajax/products').'?'.http_build_query(['q' => $q, 'page' => $page + 1]) : null,
        ]);
    }
}
