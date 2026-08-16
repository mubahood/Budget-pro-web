<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Web AJAX helpers for the laravel-admin panel.
 *
 * These endpoints are served from routes/web.php behind the `admin.auth`
 * middleware and use the admin session (Admin::user()). They are NOT part of
 * the REST API — the mobile/third-party API lives under /api/v1 (see
 * app/Http/Controllers/Api/V1). Every method here scopes to the logged-in
 * admin's company_id.
 */
class ApiController extends BaseController
{
    /**
     * Quick Add Product — AJAX endpoint for instant product creation.
     */
    public function product_quick_add(Request $r)
    {
        $u = \Encore\Admin\Facades\Admin::user();

        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }

        $r->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
        ]);

        try {
            $sku = $r->get('sku');
            if (empty($sku)) {
                $sku = 'PROD-'.time().'-'.rand(1000, 9999);
            }

            $product = new \App\Models\StockItem();
            $product->company_id = $u->company_id;
            $product->name = $r->get('name');
            $product->sku = $sku;
            $product->barcode = $r->get('barcode', '');
            $product->stock_sub_category_id = $r->get('stock_sub_category_id');
            $product->buying_price = $r->get('buying_price', 0);
            $product->selling_price = $r->get('selling_price');
            $product->current_quantity = $r->get('current_quantity', 0);
            $product->original_quantity = $r->get('current_quantity', 0);
            $product->created_by_id = $u->id;
            $product->description = $r->get('description', '');

            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Product added successfully!',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'selling_price' => number_format($product->selling_price),
                    'stock' => number_format($product->current_quantity),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick Sale Recording — AJAX endpoint.
     */
    public function quick_sale_record(Request $r)
    {
        $u = \Encore\Admin\Facades\Admin::user();

        if ($u == null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $validator = \Validator::make($r->all(), [
                'stock_item_id' => 'required|exists:stock_items,id',
                'quantity' => 'required|numeric|min:1',
                'price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $stockItem = \App\Models\StockItem::find($r->stock_item_id);

            if ($stockItem == null || $stockItem->company_id != $u->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            if ($stockItem->current_quantity < $r->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock! Available: '.$stockItem->current_quantity.' units',
                ], 422);
            }

            $salePrice = $r->price ?? $stockItem->selling_price;

            $stockRecord = new \App\Models\StockRecord();
            $stockRecord->company_id = $u->company_id;
            $stockRecord->stock_item_id = $stockItem->id;
            $stockRecord->quantity = abs($r->quantity);
            $stockRecord->type = 'Sale';
            $stockRecord->created_by_id = $u->id;
            $stockRecord->description = $r->description ?? 'Quick sale recorded';
            $stockRecord->save();

            $stockItem->refresh();

            $totalAmount = $salePrice * $r->quantity;
            $profit = ($salePrice - $stockItem->buying_price) * $r->quantity;

            return response()->json([
                'success' => true,
                'message' => 'Sale recorded successfully!',
                'data' => [
                    'id' => $stockRecord->id,
                    'product' => $stockItem->name,
                    'quantity' => $r->quantity,
                    'price' => $salePrice,
                    'total' => $totalAmount,
                    'profit' => $profit,
                    'remaining_stock' => $stockItem->current_quantity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Global Search — AJAX endpoint for the admin command palette.
     */
    public function global_search(Request $r)
    {
        $u = \Encore\Admin\Facades\Admin::user();

        if ($u == null) {
            return response()->json([
                'products' => [],
                'categories' => [],
                'sales' => [],
            ], 401);
        }

        $query = $r->get('q', '');
        $companyId = $u->company_id;

        $products = \App\Models\StockItem::where('company_id', $companyId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'current_quantity', 'selling_price']);

        $categories = \App\Models\StockSubCategory::where('company_id', $companyId)
            ->where('name', 'like', "%{$query}%")
            ->withCount('stock_items')
            ->limit(5)
            ->get(['id', 'name']);

        $sales = \App\Models\StockRecord::where('company_id', $companyId)
            ->whereHas('stock_item', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->with('stock_item:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $salesFormatted = $sales->map(function ($sale) {
            return [
                'id' => $sale->id,
                'product_name' => $sale->stock_item ? $sale->stock_item->name : 'N/A',
                'date' => date('d M Y', strtotime($sale->created_at)),
                'quantity' => $sale->quantity,
                'total' => $sale->total,
            ];
        });

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'sales' => $salesFormatted,
        ]);
    }
}
