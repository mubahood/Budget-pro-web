<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\SaccoMember;
use App\Services\Kin\KinPeople;
use App\Services\Kin\KinScope;
use App\Services\Kin\TreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The family's people (sacco_members rows): list, one with their relatives, add and change. Never deleted. */
class PeopleController extends KinController
{
    /** GET kin/people?q&status&branch_id&generation&per_page&page */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $q = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id');
        if (in_array($status = $request->query('status'), config('kin.statuses'), true)) {
            $q->where('status', $status);
        }
        if ($request->filled('branch_id')) {
            $q->where('branch_id', (int) $request->query('branch_id'));
        }
        if ($request->filled('generation')) {
            $q->where('generation', (int) $request->query('generation'));
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('other_names', 'like', $like)
                ->orWhere('member_no', 'like', $like));
        }
        $p = $q->orderByRaw('generation IS NULL, generation')->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($this->perPage($request), ['*'], 'page', max(1, (int) $request->query('page', '1')));
        $officer = $this->officer($request);

        return $this->page($p, collect($p->items())->map(fn ($m) => KinPeople::present($m, $officer))->all());
    }

    /** GET kin/people/{id}: the person, their parents, children, siblings and spouses. */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $person = KinPeople::find($this->cid($request), $id);

            return $this->success(KinPeople::present($person, $this->officer($request)) + TreeService::relatives($this->cid($request), (int) $person->id));
        });
    }

    /** POST kin/people {first_name, last_name?, other_names?, gender?, date_of_birth?, birth_date_precision?, status?, date_of_death?, place_of_death?, phone_e164?, email?, ...} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $d = KinScope::placeNew($request->user(), $this->cid($request), $request->validate(KinPeople::rules($this->cid($request), true)), true);
            $person = app(KinPeople::class)->create($this->company($request), $request->user(), $d);

            return $this->created(KinPeople::present($person, true), 'Person added.');
        });
    }

    /** PUT|PATCH kin/people/{id}: only the fields sent change. */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $person = KinPeople::find($this->cid($request), $id);
            KinScope::guard($request->user(), $this->cid($request), (int) $person->id);
            $d = KinScope::placeNew($request->user(), $this->cid($request), $request->validate(KinPeople::rules($this->cid($request), false)), false);
            $person = app(KinPeople::class)->update($this->company($request), $person, $d);

            return $this->success(KinPeople::present($person, true), 'Saved.');
        });
    }
}
