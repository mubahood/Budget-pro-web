<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalActivity;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The audit trail (rentals_settings): who did what and when, staff, tenants, landlords and the nightly run. */
class ActivityController extends RentalsController
{
    /** GET rentals/activity?subject_type&subject_id&action&actor_type&from&to */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_settings');
            $q = RentalActivity::withoutGlobalScopes()->where('company_id', $this->cid($request));
            if ($request->filled('subject_type')) {
                $q->where('subject_type', ucfirst((string) $request->query('subject_type')));
            }
            if ($request->filled('subject_id')) {
                $q->where('subject_id', (int) $request->query('subject_id'));
            }
            if ($request->filled('action')) {
                $q->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('action')).'%');
            }
            if (in_array($request->query('actor_type'), ['staff', 'tenant', 'landlord', 'system'], true)) {
                $q->where('actor_type', $request->query('actor_type'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('created_at', '>=', $from.' 00:00:00');
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('created_at', '<=', $to.' 23:59:59');
            }
            $p = $this->paginate($q->orderByDesc('id'), $request);
            $present = $this->present($request);

            return $this->page($p, collect($p->items())->map(fn ($a) => $present->activity($a))->all());
        });
    }
}
