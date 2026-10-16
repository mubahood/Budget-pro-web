<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StandaloneProduct;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform › Standalone products (docs/STANDALONE_EDITION_PLAN.md §11, §22): what a copy sells for,
 * with no deploy (R10). Prices in UGX (and optionally US dollars), months of updates, the limits a
 * licence carries (team logins, stores, phones — never products or sales) and the white-label flag.
 * Orders already made keep the price and limits they were made at. Platform group: 404 on standalone.
 */
class StandaloneProductController extends Controller
{
    public function index(Request $request, Content $content)
    {
        $products = StandaloneProduct::query()->orderBy('sort')->orderBy('price_ugx')->get();
        $editing = $request->filled('edit') ? $products->firstWhere('id', (int) $request->query('edit')) : null;

        return $content->title('Standalone products')->description('What a copy on the buyer\'s own server sells for')
            ->body(view('admin.standalone-products', ['products' => $products, 'editing' => $editing]));
    }

    public function store(Request $request)
    {
        StandaloneProduct::create($this->validated($request, null));
        admin_success('Product saved', 'It shows on the public page when it is on sale and a release is published.');

        return redirect(admin_url('standalone-products'));
    }

    public function update(Request $request, int $id)
    {
        $product = StandaloneProduct::query()->findOrFail($id);
        $product->update($this->validated($request, $product));
        admin_success('Product saved', 'Orders already made keep the price, months and limits they were made at.');

        return redirect(admin_url('standalone-products'));
    }

    public function toggle(int $id)
    {
        $product = StandaloneProduct::query()->findOrFail($id);
        $product->update(['is_active' => ! $product->is_active]);
        admin_success($product->is_active ? 'On sale' : 'Off sale', $product->name.($product->is_active ? ' is offered on the public page.' : ' is no longer offered.'));

        return redirect(admin_url('standalone-products'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?StandaloneProduct $product): array
    {
        if (blank($request->input('slug'))) {
            $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        }
        $money = ['nullable', 'numeric', 'min:1', 'max:999999999999'];
        $limit = ['nullable', 'integer', 'min:1', 'max:100000'];
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'slug' => ['required', 'alpha_dash', 'max:190', Rule::unique('standalone_products', 'slug')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_ugx' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'price_usd' => $money, 'renewal_price_ugx' => $money, 'renewal_price_usd' => $money,
            'updates_months' => ['required', 'integer', 'min:1', 'max:60'],
            'max_users' => $limit, 'max_locations' => $limit, 'max_devices' => $limit,
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return [
            'name' => trim($data['name']), 'slug' => $data['slug'], 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'price_ugx' => $data['price_ugx'], 'price_usd' => $data['price_usd'] ?? null,
            'renewal_price_ugx' => $data['renewal_price_ugx'] ?? null, 'renewal_price_usd' => $data['renewal_price_usd'] ?? null,
            'updates_months' => (int) $data['updates_months'],
            'limits' => StandaloneProduct::normaliseLimits(array_intersect_key($data, StandaloneProduct::LIMITS)),
            'white_label' => $request->boolean('white_label'), 'is_active' => $request->boolean('is_active'),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}
