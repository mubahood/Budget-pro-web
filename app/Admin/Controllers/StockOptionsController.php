<?php

namespace App\Admin\Controllers;

use App\Models\StockCategory;
use App\Models\StockSubCategory;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Pick-lists for the stock screens (product form → category, stock movement →
 * product, quick-add category modal). Always the signed-in user's own shop:
 * any company_id in the request is ignored.
 *
 * Responses use the shape laravel-admin's `->ajax()` select expects:
 * `{data: [{id, text}], next_page_url}`.
 */
class StockOptionsController extends Controller
{
    private const PER_PAGE = 20;

    public function subCategories(Request $request): JsonResponse
    {
        $companyId = (int) Admin::user()->company_id;
        $q = trim((string) $request->get('q', ''));

        $query = DB::table('stock_sub_categories as s')
            ->leftJoin('stock_categories as c', 'c.id', '=', 's.stock_category_id')
            ->where('s.company_id', $companyId)
            ->where('s.is_deleted', 0)
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('s.name', 'like', '%'.$q.'%')->orWhere('c.name', 'like', '%'.$q.'%')))
            ->orderBy('s.name')
            ->select(['s.id', 's.name', 's.measurement_unit', 'c.name as category']);

        return $this->page($query, $request, fn ($r) => trim($r->name.($r->category ? ' - '.$r->category : '')).($r->measurement_unit ? ' ('.$r->measurement_unit.')' : ''));
    }

    public function stockItems(Request $request): JsonResponse
    {
        $companyId = (int) Admin::user()->company_id;
        $q = trim((string) $request->get('q', ''));

        $query = DB::table('stock_items as i')
            ->leftJoin('stock_sub_categories as s', 's.id', '=', 'i.stock_sub_category_id')
            ->where('i.company_id', $companyId)
            ->where('i.is_deleted', 0)
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('i.name', 'like', '%'.$q.'%')->orWhere('i.sku', 'like', '%'.$q.'%')->orWhere('i.barcode', $q)))
            ->orderBy('i.name')
            ->select(['i.id', 'i.name', 'i.sku', 'i.current_quantity', 's.measurement_unit']);

        return $this->page($query, $request, fn ($r) => $r->name.' (in stock: '.self::qty($r->current_quantity).($r->measurement_unit ? ' '.$r->measurement_unit : '').')');
    }

    /** Main categories for the quick-add modal. */
    public function categories(): JsonResponse
    {
        $rows = DB::table('stock_categories')->where('company_id', (int) Admin::user()->company_id)->where('is_deleted', 0)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'text' => $r->name, 'name' => $r->name])->all()]);
    }

    /** Quick-add: a main category, or (with parent_id) a sub-category under one. */
    public function storeCategory(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Admin::user();
        if (! Permissions::can($user, 'manage_products')) {
            return response()->json(['status' => 'error', 'message' => 'Your role does not allow adding categories.'], 403);
        }
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return response()->json(['status' => 'error', 'message' => 'Enter a category name.'], 422);
        }
        $companyId = (int) $user->company_id;
        $status = $request->input('status') === 'Inactive' ? 'Inactive' : 'Active';
        $parentId = (int) $request->input('parent_id', 0);

        if ($parentId > 0) {
            $parent = StockCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($parentId);
            if ($parent === null) {
                return response()->json(['status' => 'error', 'message' => 'Category not found.'], 422);
            }
            $row = StockSubCategory::create(['company_id' => $companyId, 'stock_category_id' => $parent->id, 'name' => $name, 'description' => $request->input('description'),
                'status' => $status, 'measurement_unit' => trim((string) $request->input('measurement_unit', '')) ?: 'pcs']);
        } else {
            $row = StockCategory::create(['company_id' => $companyId, 'name' => $name, 'description' => $request->input('description'), 'status' => $status]);
        }

        return response()->json(['status' => 'success', 'id' => $row->id, 'name' => $row->name]);
    }

    private function page($query, Request $request, \Closure $text): JsonResponse
    {
        $page = max(1, (int) $request->get('page', 1));
        $rows = (clone $query)->forPage($page, self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;

        return response()->json([
            'data' => $rows->take(self::PER_PAGE)->map(fn ($r) => ['id' => $r->id, 'text' => $text($r)])->values()->all(),
            'next_page_url' => $more ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ]);
    }

    private static function qty($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    }
}
