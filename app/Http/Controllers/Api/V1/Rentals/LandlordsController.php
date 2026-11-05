<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalProperty;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Landlords (agency mode): who owns the properties, their balance, their statement and their own sign-in. */
class LandlordsController extends RentalsController
{
    /** GET rentals/landlords?q&status: each with its properties, units and balance. */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid);
            if (($ids = RentalAccess::propertyIds($this->user($request))) !== null) {
                $q->whereIn('id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids ?: [0])->select('landlord_id'));
            }
            if (in_array($request->query('status'), ['active', 'inactive'], true)) {
                $q->where('status', $request->query('status'));
            }
            self::search($q, $request->query('q'), ['name', 'code', 'phone', 'phone_e164', 'email']);
            $p = $this->paginate($q->orderBy('name')->orderBy('id'), $request);
            $figures = Figures::landlords($cid, collect($p->items())->pluck('id')->map(fn ($x) => (int) $x)->all());
            $present = $this->present($request);
            $payout = $this->seesPayout($request);

            return $this->page($p, collect($p->items())->map(fn ($l) => $present->landlord($l, $figures[(int) $l->id] ?? null, false, $payout))->all());
        });
    }

    /** POST rentals/landlords {name, phone, email, address, id_number, payout_method, payout_details, commission_type, commission_bp, commission_minor, notes} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->shape($request, app(Landlords::class)->create($this->user($request), $request->only(Landlords::WRITABLE))), 'Landlord added.'));
    }

    /** GET rentals/landlords/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, $this->find($request, $id))));
    }

    /** PUT rentals/landlords/{id} (with inactive_reason when marking a landlord with a balance as no longer with us) */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request,
            app(Landlords::class)->update($this->user($request), $this->find($request, $id), $request->only([...Landlords::WRITABLE, 'inactive_reason']))), 'Saved.'));
    }

    /** GET rentals/landlords/{id}/statement?from&to[&format=pdf]: the statement (json), or a 30-minute link to its PDF. */
    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $landlord = $this->find($request, $id);
            [$from, $to] = $this->period($request);
            if ($request->query('format') === 'pdf') {
                return $this->success(RentalLinks::statementPdf($landlord, $from, $to, 'staff', (int) $this->user($request)->id), 'Link ready.');
            }

            return $this->success(StatementBook::read($landlord, $from, $to));
        });
    }

    /** POST rentals/landlords/{id}/login {enabled, email, phone, password}: open (or update) or close the landlord's own sign-in. */
    public function login(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $landlord = $this->find($request, $id);

            return $this->success(self::loginState(self::toggleLogin($request, $this->user($request), 'landlord', (int) $landlord->id)), 'Saved.');
        });
    }

    /** Open, update or close a tenant's or landlord's sign-in from a staff request. */
    public static function toggleLogin(Request $request, \App\Models\User $by, string $type, int $id): ?RentalLogin
    {
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : true;
        if (! $enabled) {
            return app(RentalLogins::class)->disable($by, $type, $id);
        }
        $str = fn (string $k) => $request->filled($k) ? trim((string) $request->input($k)) : null;

        return app(RentalLogins::class)->enable($by, $type, $id, $str('email'), $str('phone'), $request->filled('password') ? (string) $request->input('password') : null);
    }

    /** What staff see of a sign-in. @return array<string, mixed> */
    public static function loginState(?RentalLogin $l): array
    {
        return ['has_login' => $l !== null && $l->disabled_at === null, 'login' => $l === null ? null : ['id' => (int) $l->id, 'email' => $l->email, 'phone' => $l->phone_e164,
            'enabled' => $l->disabled_at === null, 'has_password' => $l->password !== null, 'locked' => $l->locked_until !== null && $l->locked_until->isFuture(),
            'last_login_at' => $l->last_login_at?->toIso8601String()]];
    }

    /** from and to (Y-m-d): the month so far by default. @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $today = LocalDate::today($this->cid($request));
        $from = $this->day($request, 'from') ?? $today->copy()->startOfMonth()->toDateString();
        $to = $this->day($request, 'to') ?? $today->toDateString();
        if ($from > $to) {
            throw BusinessRuleException::make('date_invalid', 'The start of the period is after its end.');
        }

        return [$from, $to];
    }

    private function find(Request $request, int $id): RentalLandlord
    {
        $l = app(Landlords::class)->find($this->user($request), $id);
        $ids = RentalAccess::propertyIds($this->user($request));
        if ($ids !== null && ! RentalProperty::withoutGlobalScopes()->where('company_id', $l->company_id)->where('landlord_id', $l->id)->whereIn('id', $ids ?: [0])->exists()) {
            throw $this->notFoundRecord('landlord');
        }

        return $l;
    }

    private function shape(Request $request, RentalLandlord $l): array
    {
        return $this->present($request)->landlord($l, Figures::landlords($this->cid($request), [(int) $l->id])[(int) $l->id] ?? null, false, $this->seesPayout($request));
    }

    /** A caretaker never sees how a landlord is paid (§7), whatever their role was given. */
    private function seesPayout(Request $request): bool
    {
        return RentalAccess::propertyIds($this->user($request)) === null;
    }
}
