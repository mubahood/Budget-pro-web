<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Services\Unity\Insights;
use App\Services\Unity\Memberships;
use App\Services\Unity\UnityAccess;
use App\Services\Unity\Tree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The home briefing (insights), what the signed-in person may do (access) and the organisation tree. */
class HomeController extends UnityController
{
    /** GET unity/access: open to everyone on the team. */
    public function access(Request $request): JsonResponse
    {
        return $this->success(UnityAccess::for($this->company($request), $request->user()), 'Access.');
    }

    /** GET unity/insights (unity_view): the briefing; money is null without unity_finance (docs 12, 13). */
    public function insights(Request $request): JsonResponse
    {
        Memberships::refreshStanding($this->cid($request));

        return $this->withAccess($request, Insights::build($this->company($request), $this->can($request, 'unity_finance')), 'Insights.');
    }

    /** GET unity/tree?node=org|region:{id}|chapter:{id}|committee:{id}|unassigned&status&category_id&paid_up&page&per_page(≤100) */
    public function tree(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $per = max(1, min(100, (int) $request->query('per_page', '50')));
            $page = $this->pageNo($request);
            $r = Tree::node($this->cid($request), (string) $request->query('node', 'org'), $request->query(), $page, $per);
            $last = max(1, (int) ceil($r['total'] / $per));

            $pagination = ['current_page' => $page, 'per_page' => $per, 'total' => $r['total'], 'last_page' => $last, 'has_more' => $page < $last];

            return $this->success(['node' => $r['node'], 'children' => $r['children'], 'pagination' => $pagination], 'Tree.', 200, ['pagination' => $pagination]);
        });
    }

    /** GET unity/tree/outline: every region, chapter and committee in one list (cached on the phone). */
    public function outline(Request $request): JsonResponse
    {
        return $this->success(Tree::outline($this->cid($request)), 'Outline.');
    }

    /** GET unity/tree/find?q= : up to 20 hits with their path. */
    public function find(Request $request): JsonResponse
    {
        return $this->success(Tree::find($this->cid($request), (string) $request->query('q', '')), 'Found.');
    }
}
