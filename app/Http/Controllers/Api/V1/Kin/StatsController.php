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
        return $this->success((new FamilyStats($this->company($request)))->all(), 'The family in numbers.');
    }
}
