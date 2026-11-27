<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\KinPurse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The family purse (SACCO books beside the tree). GET for the family; POST {enabled: true, member_ids?} for the head only. */
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
}
