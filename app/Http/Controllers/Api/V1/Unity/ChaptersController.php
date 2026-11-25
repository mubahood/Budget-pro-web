<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\SaccoMember;
use App\Models\UnityChapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The association's chapters (Organisation plan and the trial). A chapter with members is not removed: move them first. */
class ChaptersController extends UnityController
{
    /** GET unity/chapters: each with how many members are in it. */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $counts = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNotNull('chapter_id')
            ->groupBy('chapter_id')->selectRaw('chapter_id, COUNT(*) AS n')->pluck('n', 'chapter_id');
        $rows = UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get();

        return $this->success($rows->map(fn ($c) => self::shape($c) + ['members' => (int) ($counts[$c->id] ?? 0)])->values()->all(), 'Listed successfully.');
    }

    /** GET unity/chapters/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::shape($this->find($request, $id))));
    }

    /** POST unity/chapters {name, lead_member_id?, location?, notes?, sort_order?} */
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
        return ['name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'], 'lead_member_id' => ['sometimes', 'nullable', 'integer'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'], 'notes' => ['sometimes', 'nullable', 'string', 'max:500'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityChapter $c): array
    {
        return ['id' => (int) $c->id, 'uuid' => $c->uuid, 'name' => $c->name, 'lead_member_id' => $c->lead_member_id ? (int) $c->lead_member_id : null,
            'location' => $c->location, 'notes' => $c->notes, 'sort_order' => (int) $c->sort_order, 'version' => (int) $c->version];
    }
}
