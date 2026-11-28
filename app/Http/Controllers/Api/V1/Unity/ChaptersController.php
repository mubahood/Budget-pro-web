<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\UnityChapter;
use App\Services\Unity\MemberFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The association's places (Organisation plan and the trial): regions (kind region) and chapters, a chapter perhaps inside a
 * region (parent_id), each with an optional short code. A chapter with members, or a region with chapters, is not removed.
 */
class ChaptersController extends UnityController
{
    /** GET unity/chapters?kind=region|chapter: each with its current members (a region: those in its chapters). */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $counts = MemberFilters::current($cid)->whereNotNull('chapter_id')->groupBy('chapter_id')->selectRaw('chapter_id, COUNT(*) AS n')->pluck('n', 'chapter_id');
        $rows = UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when(in_array($k = $request->query('kind'), ['region', 'chapter'], true), fn ($q) => $q->where('kind', $k))
            ->orderByRaw("kind = 'chapter'")->orderBy('sort_order')->orderBy('name')->get();
        $all = UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->get(['id', 'kind', 'parent_id']);
        $count = fn ($c) => $c->kind === 'region'
            ? (int) $all->where('parent_id', $c->id)->sum(fn ($x) => (int) ($counts[$x->id] ?? 0))
            : (int) ($counts[$c->id] ?? 0);

        return $this->success($rows->map(fn ($c) => self::shape($c) + ['members' => $count($c)])->values()->all(), 'Listed successfully.');
    }

    /** GET unity/chapters/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::shape($this->find($request, $id))));
    }

    /** POST unity/chapters {name, kind?, parent_id?, code?, lead_member_id?, location?, notes?, sort_order?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules(true));

        return $this->attempt(function () use ($request, $d) {
            $c = new UnityChapter($d + ['company_id' => $this->cid($request), 'created_by_id' => $request->user()->id]);
            $c->save();

            return $this->created(self::shape($c->fresh()), 'Chapter added.');
        });
    }

    /** PUT|PATCH unity/chapters/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $c = $this->find($request, $id);
            $c->fill($d)->save();

            return $this->success(self::shape($c->fresh()), 'Saved.');
        });
    }

    /** DELETE unity/chapters/{id}: only one with no members (409 chapter_in_use otherwise). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->find($request, $id)->delete();

            return $this->success(null, 'Chapter removed.');
        });
    }

    private function find(Request $request, int $id): UnityChapter
    {
        return UnityChapter::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(bool $creating): array
    {
        return ['name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'], 'kind' => ['sometimes', 'in:region,chapter'],
            'parent_id' => ['sometimes', 'nullable', 'integer'], 'code' => ['sometimes', 'nullable', 'string', 'max:12'], 'lead_member_id' => ['sometimes', 'nullable', 'integer'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'], 'notes' => ['sometimes', 'nullable', 'string', 'max:500'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityChapter $c): array
    {
        return ['id' => (int) $c->id, 'uuid' => $c->uuid, 'name' => $c->name, 'kind' => $c->kind ?: 'chapter', 'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            'code' => $c->code, 'lead_member_id' => $c->lead_member_id ? (int) $c->lead_member_id : null,
            'location' => $c->location, 'notes' => $c->notes, 'sort_order' => (int) $c->sort_order, 'version' => (int) $c->version];
    }
}
