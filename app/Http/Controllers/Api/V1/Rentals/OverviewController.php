<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Services\Rentals\Figures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The home screen's figures (docs/estate-ledger/04-architecture.md §9.1 Overview). */
class OverviewController extends RentalsController
{
    /** GET rentals/overview: units and occupancy, this month, arrears, money held, what needs attention, today's payments. */
    public function show(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $o = Figures::overview($this->user($request));
            $present = $this->present($request)->prime($o['today']);
            $o['today'] = array_map(fn ($p) => $present->paymentBrief($p), $o['today']);

            return $this->success($o);
        });
    }
}
