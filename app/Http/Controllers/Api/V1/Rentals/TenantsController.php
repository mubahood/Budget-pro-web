<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalLogin;
use App\Models\RentalTenant;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Figures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tenants: people and companies who rent, their leases and balances, and their own sign-in. A caretaker sees their properties' tenants. */
class TenantsController extends RentalsController
{
    /** GET rentals/tenants?q&status=active|former */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = Tenants::visibleTo(RentalTenant::withoutGlobalScopes()->where('company_id', $cid), $this->user($request));
            if (in_array($request->query('status'), ['active', 'former'], true)) {
                $q->where('status', $request->query('status'));
            }
            self::search($q, $request->query('q'), ['name', 'code', 'phone', 'phone_e164', 'alt_phone', 'email']);
            $p = $this->paginate($q->orderBy('name')->orderBy('id'), $request);
            $tenants = collect($p->items());
            $logins = RentalLogin::withoutGlobalScopes()->where('company_id', $cid)->where('subject_type', 'tenant')->whereNull('disabled_at')
                ->whereIn('subject_id', $tenants->pluck('id')->all() ?: [0])->pluck('subject_id')->map(fn ($x) => (int) $x)->all();
            $figures = Figures::tenants($cid, $tenants->all(), $this->user($request));
            $present = $this->present($request)->primeFiles($tenants->pluck('photo_uuid'))->prime(collect($figures)->flatMap(fn ($f) => array_column($f['leases'], 'lease'))->all());

            return $this->page($p, $tenants->map(fn ($t) => $present->tenant($t, $figures[(int) $t->id], in_array((int) $t->id, $logins, true)))->all());
        });
    }

    /** POST rentals/tenants {kind, name, phone, alt_phone, email, id_type, id_number, occupation, employer, next_of_kin_name, next_of_kin_phone, photo_uuid, notes, allow_duplicate} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->shape($request,
            app(Tenants::class)->create($this->user($request), $request->only([...Tenants::WRITABLE, 'allow_duplicate']))), 'Tenant added.'));
    }

    /** GET rentals/tenants/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, app(Tenants::class)->find($this->user($request), $id))));
    }

    /** PUT rentals/tenants/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $tenant = app(Tenants::class)->find($this->user($request), $id);

            return $this->success($this->shape($request, app(Tenants::class)->update($this->user($request), $tenant, $request->only(Tenants::WRITABLE))), 'Saved.');
        });
    }

    /** GET rentals/tenants/{id}/login: their sign-in as it stands, what the plan and this server allow, and where they sign in. */
    public function loginDetails(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $tenant = app(Tenants::class)->find($this->user($request), $id);

            return $this->success(LandlordsController::loginDetailsOf($this->company($request), 'tenant', (int) $tenant->id, $tenant->email, $tenant->phone));
        });
    }

    /** POST rentals/tenants/{id}/login {enabled, email, phone, password}: open (or update) or close the tenant's own sign-in. */
    public function login(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $tenant = app(Tenants::class)->find($this->user($request), $id);

            return $this->success(LandlordsController::loginState(LandlordsController::toggleLogin($request, $this->user($request), 'tenant', (int) $tenant->id)), 'Saved.');
        });
    }

    private function shape(Request $request, RentalTenant $t): array
    {
        $login = RentalLogin::withoutGlobalScopes()->where('company_id', $t->company_id)->where('subject_type', 'tenant')->where('subject_id', $t->id)->whereNull('disabled_at')->exists();

        return $this->present($request)->tenant($t, Figures::tenant($t, $this->user($request)), $login);
    }
}
