<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Services\Rentals\Figures;
use App\Services\Rentals\RentalAccess;
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
            $o['demo'] = (bool) $this->company($request)->is_demo; // the app says so on its tabs
            if (RentalAccess::propertyIds($this->user($request)) !== null) {
                $o['landlords_minor'] = null; // as on Today: what is owed to landlords is between the office and them (EL-26)
            }

            return $this->success($o);
        });
    }
}
