<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\KinBranch;
use App\Models\SaccoMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The family's branches (houses). A branch with people in it is not removed: move them first. */
class BranchesController extends KinController
{
    /** GET kin/branches: each with how many people are in it. */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $counts = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNotNull('branch_id')
            ->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS n')->pluck('n', 'branch_id');
        $rows = KinBranch::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get();

        return $this->success($rows->map(fn ($b) => self::shape($b) + ['people' => (int) ($counts[$b->id] ?? 0)])->values()->all(), 'Listed successfully.');
    }

    /** GET kin/branches/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::shape($this->find($request, $id))));
    }

    /** POST kin/branches {name, founder_id?, coordinator_user_id?, notes?, sort_order?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules($this->cid($request), true));

        return $this->attempt(function () use ($request, $d) {
            $b = new KinBranch($d + ['company_id' => $this->cid($request), 'created_by_id' => $request->user()->id]);
            $b->save();

            return $this->created(self::shape($b), 'Branch added.');
        });
    }

    /** PUT|PATCH kin/branches/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules($this->cid($request), false));

        return $this->attempt(function () use ($request, $id, $d) {
            $b = $this->find($request, $id);
            $b->fill($d)->save();

            return $this->success(self::shape($b->fresh()), 'Saved.');
        });
    }

    /** DELETE kin/branches/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $b = $this->find($request, $id);
            $n = SaccoMember::withoutGlobalScopes()->where('company_id', $b->company_id)->where('is_deleted', 0)->where('branch_id', $b->id)->count();
            if ($n > 0) {
                throw BusinessRuleException::make('branch_in_use', "This branch has {$n} ".($n === 1 ? 'person' : 'people').' in it. Move them to another branch first.', ['people' => $n]);
            }
            $b->delete();

            return $this->success(null, 'Branch removed.');
        });
    }

    private function find(Request $request, int $id): KinBranch
    {
        return KinBranch::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(int $cid, bool $creating): array
    {
        $coordinator = \Illuminate\Validation\Rule::exists('admin_users', 'id')->where('company_id', $cid);

        return ['name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'], 'founder_id' => ['nullable', 'integer'], 'coordinator_user_id' => ['nullable', 'integer', $coordinator],
            'notes' => ['nullable', 'string', 'max:500'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']];
    }

    /** @return array<string, mixed> */
    public static function shape(KinBranch $b): array
    {
        return ['id' => (int) $b->id, 'uuid' => $b->uuid, 'name' => $b->name, 'founder_id' => $b->founder_id ? (int) $b->founder_id : null,
            'coordinator_user_id' => $b->coordinator_user_id ? (int) $b->coordinator_user_id : null, 'notes' => $b->notes, 'sort_order' => (int) $b->sort_order, 'version' => (int) $b->version];
    }
}
