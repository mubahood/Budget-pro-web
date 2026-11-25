<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\KinScope;
use App\Models\KinLink;
use App\Services\Kin\TreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A child's link to a parent. TreeService guards every one (no self-link, same family, no cycle, one birth father and mother). */
class LinksController extends KinController
{
    /** POST kin/links {child_id, parent_id, role: father|mother|parent, kind?: birth|adoptive|step|guardian, notes?} → the link and any warnings (a child older than the parent). */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['child_id' => ['required', 'integer'], 'parent_id' => ['required', 'integer'], 'role' => ['required', 'in:'.implode(',', config('kin.link_roles'))],
            'kind' => ['sometimes', 'in:'.implode(',', config('kin.link_kinds'))], 'notes' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $d) {
            KinScope::guard($request->user(), $this->cid($request), (int) $d['child_id'], (int) $d['parent_id']);
            $link = new KinLink($d + ['company_id' => $this->cid($request), 'kind' => 'birth', 'created_by_id' => $request->user()->id]);
            $link->save();

            return $this->success(self::shape($link) + ['warnings' => TreeService::warningsFor($link)], 'Linked.', 201);
        });
    }

    /** DELETE kin/links/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $link = KinLink::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
            KinScope::guard($request->user(), $this->cid($request), (int) $link->child_id, (int) $link->parent_id);
            $link->delete();

            return $this->success(null, 'Link removed.');
        });
    }

    /** @return array<string, mixed> */
    public static function shape(KinLink $l): array
    {
        return ['id' => (int) $l->id, 'uuid' => $l->uuid, 'child_id' => (int) $l->child_id, 'parent_id' => (int) $l->parent_id, 'role' => $l->role, 'kind' => $l->kind,
            'notes' => $l->notes, 'version' => (int) $l->version];
    }
}
