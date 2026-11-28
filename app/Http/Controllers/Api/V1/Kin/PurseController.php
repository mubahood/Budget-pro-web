<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\KinPurse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The family purse (savings books beside the tree). GET for the family; POST {enabled: true, member_ids?} for the head only; POST members for kin_dues. */
class PurseController extends KinController
{
    public function show(Request $request): JsonResponse
    {
        return $this->success(KinPurse::status($this->company($request), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'accepted'], 'member_ids' => ['sometimes', 'array', 'max:500'], 'member_ids.*' => ['integer']]);

        return $this->attempt(function () use ($request, $d) {
            $company = app(KinPurse::class)->enable($this->company($request), $request->user(), array_map('intval', $d['member_ids'] ?? []));

            return $this->success(KinPurse::status($company, $request->user()), 'The family purse is on.');
        });
    }

    /** POST kin/purse/members {member_id, in_purse}: kin_dues or the head puts one person in the purse or takes them out. */
    public function members(Request $request): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'in_purse' => ['required', 'boolean']]);

        return $this->attempt(function () use ($request, $d) {
            $company = $this->company($request);
            $p = app(KinPurse::class)->setMember($company, (int) $d['member_id'], (bool) $d['in_purse'], $request->user());

            return $this->success(['member_id' => (int) $p->id, 'name' => $p->full_name, 'in_purse' => (bool) $p->in_purse, 'purse' => KinPurse::status($company, $request->user())],
                $p->in_purse ? "{$p->full_name} is in the purse." : "{$p->full_name} is out of the purse.");
        });
    }
}
