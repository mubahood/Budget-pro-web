<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalProperty;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
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

    /** GET rentals/landlords/{id}/sheet?month=: every unit of the landlord for a month, as of its last day (UnitSheet), with a 30-minute link to its PDF. Agency staff, never a caretaker. */
    public function sheet(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $by = $this->user($request);
            RentalAccess::authorize($by, 'rentals_landlords');
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            if (RentalsModule::isOwnerMode($cid)) {
                throw BusinessRuleException::make('agency_only', 'This is for agencies that pay landlords.');
            }
            $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($id) ?? throw $this->notFoundRecord('landlord');
            $month = $this->monthFrom($request, $cid, LocalDate::today($cid)->format('Y-m'));
            $asOf = MonthClose::lastDay($month);
            $rows = UnitSheet::rows($l, $asOf);
            $sum = fn (string $k) => (int) array_sum(array_column($rows, $k));
            $link = RentalLinks::report($cid, (int) $by->id, 'landlord_rent_sheet', 'pdf', ['landlord_id' => (int) $l->id, 'month' => $month]);

            return $this->success(['landlord' => ['id' => (int) $l->id, 'code' => $l->code, 'name' => (string) $l->name], 'month' => $month, 'as_of' => $asOf, 'currency' => $this->present($request)->currency(), 'rows' => $rows,
                'totals' => ['rent_minor' => $sum('rent_minor'), 'billed_minor' => $sum('billed_minor'), 'paid_minor' => $sum('paid_minor'), 'direct_minor' => $sum('direct_minor'), 'owed_minor' => $sum('owed_minor')],
                'pdf_url' => $link['url'], 'expires_in' => $link['expires_in']]);
        });
    }

    /** POST rentals/landlords {name, phone, email, address, id_number, payout_method, payout_details, commission_type, commission_bp, commission_minor, notes} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);

            return $this->created($this->shape($request, app(Landlords::class)->create($this->user($request), $request->only(Landlords::WRITABLE))), 'Landlord added.');
        });
    }

    /** GET rentals/landlords/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, $this->find($request, $id))));
    }

    /** PUT rentals/landlords/{id} (with inactive_reason when marking a landlord with a balance as no longer with us) */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $landlord = $this->find($request, $id);
            $this->wholeAccount($request);

            return $this->success($this->shape($request, app(Landlords::class)->update($this->user($request), $landlord, $request->only([...Landlords::WRITABLE, 'inactive_reason']))), 'Saved.');
        });
    }

    /** GET rentals/landlords/{id}/statement?from&to[&format=pdf]: the statement (json), or a 30-minute link to its PDF. */
    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $landlord = $this->find($request, $id);
            $this->wholeAccount($request);
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
            $this->wholeAccount($request);

            return $this->success(self::loginState(self::toggleLogin($request, $this->user($request), 'landlord', (int) $landlord->id)), 'Saved.');
        });
    }

    /** GET rentals/landlords/{id}/login: their sign-in as it stands, what the plan and this server allow, and where they sign in. */
    public function loginDetails(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $landlord = $this->find($request, $id);
            if ($landlord->is_self || RentalsModule::isOwnerMode($this->cid($request))) {
                throw BusinessRuleException::make('owner_mode', 'Landlords sign in when the account manages property for landlords (agency).');
            }

            return $this->success(self::loginDetailsOf($this->company($request), 'landlord', (int) $landlord->id, $landlord->email, $landlord->phone));
        });
    }

    /** What a staff screen needs to open a person's sign-in. @return array<string, mixed> */
    public static function loginDetailsOf(\App\Models\Company $company, string $type, int $id, ?string $email, ?string $phone): array
    {
        $base = rtrim((string) (config('rentals.portal_url') ?: config('saas.new_ui_url')), '/');

        return self::loginState(RentalLogins::forSubject((int) $company->id, $type, $id)) + ['portal_on_plan' => RentalLogins::portalOnPlan($company), 'codes_on' => RentalLogins::codesOn(),
            'sign_in_url' => $base === '' ? null : $base.'/my', 'person' => ['email' => $email, 'phone' => $phone]];
    }

    /** Open, update or close a tenant's or landlord's sign-in from a staff request. */
    public static function toggleLogin(Request $request, \App\Models\User $by, string $type, int $id): ?RentalLogin
    {
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : true;
        if (! $enabled) {
            return app(RentalLogins::class)->disable($by, $type, $id);
        }
        $str = fn (string $k) => $request->filled($k) ? trim((string) $request->input($k)) : null;
        if ($request->hasHeader('X-App-Version')) {
            self::assertCanSignIn($by, $type, $id, $str('email'), $str('password'));
        }

        return app(RentalLogins::class)->enable($by, $type, $id, $str('email'), $str('phone'), $request->filled('password') ? (string) $request->input('password') : null);
    }

    /** The web's check, for the phone: with no password yet, a code must be able to reach them, or they would wait for nothing. Codes go by email only. */
    private static function assertCanSignIn(\App\Models\User $by, string $type, int $id, ?string $email, ?string $password): void
    {
        $cid = (int) $by->company_id;
        if ($password !== null || RentalLogins::forSubject($cid, $type, $id)?->password !== null) {
            return;
        }
        $subject = $type === 'tenant' ? \App\Models\RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->find($id) : RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($id);
        $address = trim((string) ($email ?? $subject?->email));
        if ($address !== '' && RentalLogins::codesOn()) {
            return;
        }
        $why = $address === '' ? 'With no email, set a password: sign-in codes go by email only.' : 'Email is switched off on this service, so no code can reach them. Set a first password and give it to them yourself.';
        throw BusinessRuleException::make('password_required', $why, ['errors' => ['password' => [$why]]]);
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

    /** A landlord's money and how they are paid: for those the web lets open a landlord (rentals_landlords). Never a caretaker (§7), whatever their role was given. */
    private function seesPayout(Request $request): bool
    {
        $by = $this->user($request);

        return RentalAccess::propertyIds($by) === null && RentalAccess::can($by, 'rentals_landlords');
    }
}
