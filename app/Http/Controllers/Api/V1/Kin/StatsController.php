<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\FamilyStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET kin/stats: the family in numbers, for everyone in the family. */
class StatsController extends KinController
{
    public function show(Request $request): JsonResponse
    {
        // The next gathering carries the sign-in's own answer (my_rsvp), for the RSVP buttons on the phone's home.
        $me = \App\Services\Sacco\Dues\DuesService::personOf($request->user(), $this->cid($request));

        return $this->success((new FamilyStats($this->company($request), $me ? (int) $me->id : null))->all(), 'The family in numbers.');
    }
}
