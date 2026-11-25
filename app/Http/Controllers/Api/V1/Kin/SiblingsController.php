<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\KinLink;
use App\Services\Kin\KinPeople;
use App\Services\Kin\KinScope;
use App\Services\Kin\TreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A brother or sister in one step: the sibling gets the person's parents (the same role and kind of link). There are no
 * sibling rows; brothers and sisters are read from shared parents (TreeService::relatives), so a person needs at least
 * one parent recorded first. Either an existing person (sibling_id) or a new one (person: the fields of POST kin/people).
 */
class SiblingsController extends KinController
{
    /** POST kin/siblings {person_id, sibling_id? | person: {first_name, ...}} → the sibling and their new parent links. */
    public function store(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $d = $request->validate(['person_id' => ['required', 'integer'], 'sibling_id' => ['nullable', 'integer', 'required_without:person'],
            'person' => ['nullable', 'array', 'required_without:sibling_id']]);
        $fields = isset($d['person']) && ! isset($d['sibling_id'])
            ? validator($d['person'], KinPeople::rules($cid, true))->validate() : null;

        return $this->attempt(function () use ($request, $cid, $d, $fields) {
            $person = KinPeople::find($cid, (int) $d['person_id']);
            $parents = KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->where('child_id', $person->id)->orderBy('id')->get();
            if ($parents->isEmpty()) {
                throw BusinessRuleException::make('no_parents', 'Add a parent first: brothers and sisters share parents.');
            }

            return DB::transaction(function () use ($request, $cid, $d, $fields, $person, $parents) {
                if ($fields !== null) {
                    $fields = KinScope::placeNew($request->user(), $cid, $fields + ['branch_id' => $person->branch_id], true);
                    KinScope::guard($request->user(), $cid, (int) $person->id);
                    $sibling = app(KinPeople::class)->create($this->company($request), $request->user(), $fields);
                } else {
                    $sibling = KinPeople::find($cid, (int) $d['sibling_id']);
                    KinScope::guard($request->user(), $cid, (int) $person->id, (int) $sibling->id);
                    if ((int) $sibling->id === (int) $person->id) {
                        throw BusinessRuleException::make('self_link', 'A person is not their own brother or sister.');
                    }
                }
                $links = [];
                $warnings = [];
                foreach ($parents as $p) {
                    $have = KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
                        ->where('child_id', $sibling->id)->where('parent_id', $p->parent_id)->exists();
                    if ($have) {
                        continue;
                    }
                    $link = new KinLink(['company_id' => $cid, 'child_id' => $sibling->id, 'parent_id' => $p->parent_id, 'role' => $p->role,
                        'kind' => $p->kind, 'created_by_id' => $request->user()->id]);
                    $link->save();
                    $links[] = LinksController::shape($link);
                    $warnings = array_merge($warnings, TreeService::warningsFor($link));
                }

                return $this->success(['sibling' => KinPeople::present($sibling->fresh(), true), 'links' => $links, 'warnings' => $warnings],
                    $fields !== null ? 'Person added.' : 'Linked.', 201);
            });
        });
    }
}
