<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\SaccoMember;
use App\Models\UnityCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Membership categories and their fees (minor units) for a period in months (0: for life). One in use is switched off, not removed. */
class CategoriesController extends UnityController
{
    /** GET unity/categories: each with how many members are in it. */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $counts = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS n')->pluck('n', 'category_id');
        $rows = UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get();

        return $this->success($rows->map(fn ($c) => self::shape($c) + ['members' => (int) ($counts[$c->id] ?? 0)])->values()->all(), 'Listed successfully.');
    }

    /** GET unity/categories/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::shape($this->find($request, $id))));
    }

    /** POST unity/categories {name, fee_minor, period_months (0 = life), benefits?, is_active?, sort_order?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules(true));

        return $this->attempt(function () use ($request, $d) {
            $c = new UnityCategory($d + ['company_id' => $this->cid($request), 'created_by_id' => $request->user()->id]);
            $c->save();

            return $this->created(self::shape($c->fresh()), 'Category added.');
        });
    }

    /** PUT|PATCH unity/categories/{id}: a new fee applies to periods recorded from now on. */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $c = $this->find($request, $id);
            $c->fill($d)->save();

            return $this->success(self::shape($c->fresh()), 'Saved.');
        });
    }

    /** DELETE unity/categories/{id}: only one nobody uses (409 category_in_use otherwise). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->find($request, $id)->delete();

            return $this->success(null, 'Category removed.');
        });
    }

    private function find(Request $request, int $id): UnityCategory
    {
        return UnityCategory::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return ['name' => [$req, 'string', 'max:120'], 'fee_minor' => [$req, 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR],
            'period_months' => [$req, 'integer', 'min:0', 'max:120'], 'benefits' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityCategory $c): array
    {
        return ['id' => (int) $c->id, 'uuid' => $c->uuid, 'name' => $c->name, 'fee_minor' => (int) $c->fee_minor, 'period_months' => (int) $c->period_months,
            'is_life' => $c->isLife(), 'benefits' => $c->benefits, 'is_active' => (bool) $c->is_active, 'sort_order' => (int) $c->sort_order, 'version' => (int) $c->version];
    }
}
