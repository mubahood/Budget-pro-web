<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalActivity;
use App\Services\Rentals\RentalAccess;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The audit trail (rentals_settings): who did what and when, staff, tenants, landlords and the nightly run. */
class ActivityController extends RentalsController
{
    /**
     * GET rentals/activity?subject_type&subject_id&action&actor_type&actor_id&from&to&q: `action` is a beginning (`payment.`: a kind of line, as the web's), `actor_type` with `actor_id` one named person, `from` and `to` days on the account's clock,
     * `q` the line's words. `meta.people` on the first page: [{type, id, name}], everyone with a line on this account's trail.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_settings');
            $this->wholeAccount($request);
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
            if ($request->filled('actor_id')) {
                $q->where('actor_id', (int) $request->query('actor_id'));
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = self::like($term);
                $q->where(fn ($w) => $w->where('summary', 'like', $like)->orWhere('action', 'like', $like));
            }
            if (in_array($request->query('actor_type'), ['staff', 'tenant', 'landlord', 'system'], true)) {
                $q->where('actor_type', $request->query('actor_type'));
            }
            $tz = LocalDate::timezone($this->cid($request));
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('created_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc());
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('created_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc());
            }
            $p = $this->paginate($q->orderByDesc('id'), $request);
            $present = $this->present($request);

            return $this->page($p, collect($p->items())->map(fn ($a) => $present->activity($a))->all(), $p->currentPage() === 1 ? ['people' => $this->people($this->cid($request))] : []);
        });
    }

    /** Everyone with a line on this account's trail, each once. @return list<array{type: string, id: int, name: string}> */
    private function people(int $cid): array
    {
        $actors = RentalActivity::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('actor_id')->distinct()->get(['actor_type', 'actor_id'])->groupBy('actor_type');
        $out = [];
        foreach (['staff' => 'admin_users', 'tenant' => 'rental_tenants', 'landlord' => 'rental_landlords'] as $type => $table) {
            $ids = ($actors->get($type) ?? collect())->pluck('actor_id')->all();
            foreach ($ids === [] ? [] : DB::table($table)->where('company_id', $cid)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']) as $r) {
                $out[] = ['type' => $type, 'id' => (int) $r->id, 'name' => (string) $r->name];
            }
        }

        return $out;
    }
}
