<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Services\Unity\Memberships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SummaryController extends UnityController
{
    /** GET unity/summary: members, in good standing (life included), lapsed, life, none yet, expiring in 30 days, collected this year (minor units). */
    public function show(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        Memberships::refreshStanding($cid);

        return $this->success(Memberships::summary($cid) + ['currency' => (string) ($this->company($request)->currency ?: 'UGX')], 'Summary.');
    }
}
