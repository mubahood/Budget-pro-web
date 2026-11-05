<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\RentalPaidReport;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\Portal\PaidReports;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\Portal\TenantRequests;
use App\Services\Rentals\RentalLinks;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * A tenant's or landlord's own records (`rentals/me/*`, a portal token from PortalAuthController, KR-16). Every answer
 * comes from PersonView or TenantRequests keyed by the token's sign-in, never by a request parameter: a record that
 * is not the person's is a 404.
 */
class MeController extends Controller
{
    use ApiResponse;

    /** As RentalsController::callAction. */
    public function callAction($method, $parameters)
    {
        if (RentalsController::idTooLong($parameters)) {
            return $this->notMine();
        }
        if (! RentalsController::idsAreIds(request()->all())) {
            return $this->error('Give a record\'s id as a whole number.', 422, ['code' => 'field_invalid']);
        }

        return parent::callAction($method, $parameters);
    }

    /** GET rentals/me: Person (§9.1). */
    public function show(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->success($this->view($request)->me()));
    }

    /** POST rentals/me/logout: this token stops working. */
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('rental_token')?->delete();

        return $this->success(null, 'Signed out.');
    }

    /** POST rentals/me/password {password}: set or change the person's own password (after signing in with a code). */
    public function password(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            app(RentalLogins::class)->setPassword($this->login($request), (string) $request->input('password', ''));

            return $this->success(null, 'Password saved.');
        });
    }

    /** GET rentals/me/home: what the person's first screen reads: a tenant's homes and next bills, or a landlord's money, empty units and what waits for their answer. */
    public function home(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $base = ['type' => (string) $this->login($request)->subject_type, 'account' => $view->account(), 'name' => (string) $view->subject()->name];
            if ($view->isLandlord()) {
                $home = $view->landlordHome();
                $home['month'] += ['direct_minor' => 0];

                return $this->success($base + $home + ['approvals_waiting' => count(LandlordApprovals::waiting($view))]);
            }
            $cid = $view->companyId();
            /** @var RentalTenant $tenant */
            $tenant = $view->subject();
            $figures = Figures::tenant($tenant);
            $homes = $view->homes();
            $notices = TenantRequests::pendingNotices($cid, array_column($homes, 'id'));
            $last = $view->payments(null, 1, 1)->items()[0] ?? null;

            return $this->success($base + ['owed_minor' => (int) $figures['owed_minor'], 'credit_minor' => (int) $figures['credit_minor'],
                'homes' => array_map(function (array $h) use ($notices) {
                    $n = $h['status'] === 'active' ? ($notices[(int) $h['id']] ?? null) : null;

                    return $h + ['notice_request' => $n === null ? null : ['move_out_on' => $n['move_out_on'], 'notes' => $n['notes'], 'recorded_at' => $n['recorded_at']]];
                }, $homes),
                'last_payment' => $last, 'paid_reports_waiting' => RentalPaidReport::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', (int) $tenant->id)->where('status', 'pending')->count(),
                'repairs_open' => RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', (int) $tenant->id)->whereIn('status', ['open', 'in_progress'])->count()]);
        });
    }

    // ── A tenant ──

    /** GET rentals/me/paid-reports: what the tenant said they paid and what became of it, newest first. */
    public function paidReports(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->tenantOnly($view);
            $rows = PaidReports::forLogin($this->login($request), 50);
            $numbers = RentalPayment::withoutGlobalScopes()->where('company_id', $view->companyId())->whereIn('id', $rows->pluck('payment_id')->filter()->all() ?: [0])->pluck('number', 'id');
            $view->present()->primeFiles($rows->pluck('photo_uuid'));

            return $this->page(new Paginator($rows->map(fn (RentalPaidReport $r) => $this->paidReportShape($view, $r, $numbers->all()))->values()->all(), $rows->count(), max(1, $rows->count()), 1));
        });
    }

    /** POST rentals/me/paid-reports {uuid, amount_minor, method, reference, paid_on, note, lease_id, photo_uuid}: "I have paid". A claim: it touches no bill. The same uuid is one report. */
    public function reportPaid(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->tenantOnly($view);
            $data = $request->only(['uuid', 'amount_minor', 'method', 'reference', 'paid_on', 'note', 'lease_id', 'photo_uuid']);
            $r = app(PaidReports::class)->report($this->login($request), $data);
            $shape = $this->paidReportShape($view, $r['report'], []);

            return $r['replayed'] ? $this->success($shape, 'Already reported.') : $this->created($shape, 'Told the office. They will confirm it.');
        });
    }

    /** GET rentals/me/lease/{id}/statement-link: a 30-minute link to the tenant's own statement for that home. */
    public function statementLink(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            $view = $this->view($request);
            if ($view->leases()->firstWhere('id', $id) === null) {
                return $this->notMine();
            }

            return $this->success(RentalLinks::tenantStatementPdf($view->companyId(), $id, (int) $this->login($request)->id), 'Link ready.');
        });
    }

    /** GET rentals/me/charges?lease_id&status=open|paid|void */
    public function charges(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->charges($request->filled('lease_id') ? (int) $request->query('lease_id') : null,
            $request->query('status') !== null ? (string) $request->query('status') : null, $this->pageNo($request), $this->perPage($request, 100))));
    }

    /** GET rentals/me/payments?lease_id */
    public function payments(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->payments($request->filled('lease_id') ? (int) $request->query('lease_id') : null,
            $this->pageNo($request), $this->perPage($request))));
    }

    /** GET rentals/me/payments/{id} */
    public function payment(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => ($p = $this->view($request)->payment($id)) === null ? $this->notMine() : $this->success($p));
    }

    /** GET rentals/me/payments/{id}/receipt: {text, pdf_url} */
    public function receipt(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => ($r = $this->view($request)->receipt($id)) === null ? $this->notMine() : $this->success($r));
    }

    /** GET rentals/me/lease/{id}: Lease (§9.1) without the agency's side, and a notice waiting for the office. */
    public function lease(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => ($l = $this->view($request)->lease($id)) === null ? $this->notMine() : $this->success($l));
    }

    /** GET rentals/me/maintenance */
    public function maintenance(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->maintenance($this->pageNo($request), $this->perPage($request))));
    }

    /** POST rentals/me/maintenance {uuid, lease_id, title, details, priority, photos[]}: report a problem at home. */
    public function report(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $r = app(TenantRequests::class)->maintenance($this->login($request), $request->only(['uuid', 'lease_id', 'title', 'details', 'priority', 'photos']));
            $shape = $this->view($request)->present()->prime([$r['maintenance']])->maintenance($r['maintenance'], true);

            return $r['replayed'] ? $this->success($shape, 'Already reported.') : $this->created($shape, 'Reported. The office will follow it up.');
        });
    }

    /** POST rentals/me/notice {lease_id, move_out_on, notes, uuid}: tell the office the day you plan to leave (they confirm it). */
    public function notice(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $r = app(TenantRequests::class)->notice($this->login($request), $request->only(['uuid', 'lease_id', 'move_out_on', 'notes']));
            $replayed = $r['replayed'];
            unset($r['replayed']);

            return $replayed ? $this->success($r, 'Already sent.') : $this->created($r, 'Your notice is with the office. They will confirm it.');
        });
    }

    /** POST rentals/me/files (multipart: uuid, file): a photo of a problem (8 MB, jpg/png/webp/pdf), for POST rentals/me/maintenance. */
    public function upload(Request $request): JsonResponse
    {
        $d = $request->validate(['uuid' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,pdf']]);

        return $this->guard(function () use ($request, $d) {
            $r = app(TenantRequests::class)->photo($this->login($request), (string) $d['uuid'], $request->file('file'));
            $shape = ['file_uuid' => $r['file']->uuid, 'purpose' => $r['file']->purpose];

            return $r['replayed'] ? $this->success($shape, 'File already uploaded.') : $this->created($shape, 'File uploaded.');
        });
    }

    // ── A landlord ──

    /** GET rentals/me/approvals: the repairs waiting for this landlord's answer, oldest first. */
    public function approvals(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->landlordOnly($view);
            $rows = LandlordApprovals::waiting($view);

            return $this->page(new Paginator($rows, count($rows), max(1, count($rows)), 1));
        });
    }

    /** POST rentals/me/approvals/{id} {yes, note}: a note is needed with a no. */
    public function answerApproval(Request $request, int $id): JsonResponse
    {
        $request->validate(['yes' => ['required', 'boolean']]);

        return $this->guard(function () use ($request, $id) {
            $this->landlordOnly($this->view($request));
            $m = app(LandlordApprovals::class)->answer($this->login($request), $id, $request->boolean('yes'), $request->filled('note') ? (string) $request->input('note') : null);

            return $this->success(['id' => (int) $m->id, 'number' => (string) $m->number, 'approval' => ['status' => (string) $m->approval_status, 'note' => $m->approval_note, 'at' => $m->approval_at?->toIso8601String()]], 'Your answer is with the office.');
        });
    }

    /** GET rentals/me/repairs: repairs at their properties, with the landlord's own answer on each. Never which staff member has it, nor a tenant's phone. */
    public function repairs(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $p = $view->repairs($this->pageNo($request), $this->perPage($request, 25));
            $rows = array_values($p->items());
            $answers = LandlordApprovals::answersFor($view, $rows);
            $tickets = RentalMaintenance::withoutGlobalScopes()->where('company_id', $view->companyId())->whereIn('id', array_column($rows, 'id') ?: [0])->get(['id', 'quote_minor', 'approval_status', 'status'])->keyBy('id');
            $shaped = array_map(function (array $r) use ($answers, $tickets) {
                $t = $tickets->get($r['id']);

                return $r + ['quote_minor' => $t?->quote_minor === null ? null : (int) $t->quote_minor, 'waiting_answer' => $t !== null && $t->approval_status === 'asked' && in_array($t->status, ['open', 'in_progress'], true), 'answer' => $answers[(int) $r['id']] ?? null];
            }, $rows);

            return $this->page(new Paginator($shaped, $p->total(), $p->perPage(), $p->currentPage()));
        });
    }

    /** GET rentals/me/expenses: what was spent and charged to them, each with what they agreed. */
    public function expenses(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $p = $view->expenses($this->pageNo($request), $this->perPage($request, 25));
            $rows = array_values($p->items());
            $words = LandlordApprovals::expenseWords($view, $rows);

            return $this->page(new Paginator(array_map(fn (array $e) => $e + ['word' => $words[(int) $e['id']] ?? null], $rows), $p->total(), $p->perPage(), $p->currentPage()));
        });
    }

    /** GET rentals/me/statements: their closed months with an issued statement, newest first. */
    public function statements(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->landlordOnly($view);
            $months = $view->issuedMonths();
            krsort($months);
            $rows = [];
            foreach ($months as $month => $s) {
                $rows[] = ['month' => (string) $month, 'number' => $s['number'], 'issued_on' => $s['issued_on']];
            }

            return $this->success($rows, 'Listed successfully.', 200, ['first_year' => $view->firstStatementYear()]);
        });
    }

    /** GET rentals/me/properties: their properties with each unit's state and who pays. */
    public function properties(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->properties($this->pageNo($request), $this->perPage($request))));
    }

    /** GET rentals/me/statement?month=Y-m[&format=pdf]: the month's statement, or a 30-minute link to its PDF. */
    public function statement(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $month = (string) $request->query('month', \App\Support\LocalDate::today((int) $this->login($request)->company_id)->format('Y-m'));
            $view = $this->view($request);

            if ($request->query('format') === 'pdf') {
                return $this->success($view->statementPdf($month), 'Link ready.');
            }
            $statement = $view->statement($month);
            $statement['totals'] = (array) $statement['totals'] + ['direct_minor' => 0];

            return $this->success($statement);
        });
    }

    /** GET rentals/me/payouts */
    public function payouts(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->payouts($this->pageNo($request), $this->perPage($request))));
    }

    /** GET rentals/me/payouts/{id}/advice: a 30-minute link to that payout's advice. */
    public function payoutAdvice(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            $view = $this->view($request);
            $this->landlordOnly($view);
            $link = $view->payoutAdviceLink($id);

            return $link === null ? $this->notMine() : $this->success($link, 'Link ready.');
        });
    }

    /** GET rentals/me/sheet?month=Y-m: their units for a month, door by door: who paid, who owes, which stand empty. This month by default. */
    public function sheet(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->landlordOnly($view);

            return $this->success($view->monthSheet((string) $request->query('month', \App\Support\LocalDate::today($view->companyId())->format('Y-m'))));
        });
    }

    /** GET rentals/me/year-summary?year=Y&format=pdf|xlsx: a 30-minute link to their year summary; with no format, the years they can ask for. */
    public function yearSummary(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $view = $this->view($request);
            $this->landlordOnly($view);
            $first = $view->firstStatementYear();
            $thisYear = (int) \App\Support\LocalDate::today($view->companyId())->format('Y');
            if (! $request->filled('format')) {
                return $this->success(['years' => $first <= $thisYear ? range($thisYear, $first) : [$thisYear]]);
            }

            return $this->success($view->yearSummaryLink((string) $request->query('year', (string) ($thisYear - 1)), (string) $request->query('format')), 'Link ready.');
        });
    }

    /** GET rentals/me/arrears: by unit, read only. */
    public function arrears(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->arrears($this->pageNo($request), $this->perPage($request, 100))));
    }

    /** @param array<int|string, mixed> $numbers payment id => its number @return array<string, mixed> */
    private function paidReportShape(PersonView $view, RentalPaidReport $r, array $numbers): array
    {
        return ['id' => (int) $r->id, 'uuid' => (string) $r->uuid, 'lease_id' => (int) $r->lease_id, 'amount_minor' => (int) $r->amount_minor, 'method' => (string) $r->method, 'reference' => $r->reference, 'paid_on' => $r->paid_on->toDateString(), 'note' => $r->note,
            'photo_url' => $view->present()->fileUrl($r->photo_uuid), 'status' => (string) $r->status, 'decline_reason' => $r->decline_reason, 'decided_at' => $r->decided_at?->toIso8601String(),
            'receipt' => $r->payment_id !== null && isset($numbers[(int) $r->payment_id]) ? ['payment_id' => (int) $r->payment_id, 'number' => (string) $numbers[(int) $r->payment_id]] : null, 'reported_at' => $r->created_at?->toIso8601String()];
    }

    private function tenantOnly(PersonView $view): void
    {
        if (! $view->isTenant()) {
            throw BusinessRuleException::make('not_found', 'This is for tenants.');
        }
    }

    private function landlordOnly(PersonView $view): void
    {
        if (! $view->isLandlord()) {
            throw BusinessRuleException::make('not_found', 'This is for landlords.');
        }
    }

    private function login(Request $request): RentalLogin
    {
        return $request->attributes->get('rental_login');
    }

    private function view(Request $request): PersonView
    {
        $v = $request->attributes->get('rental_view');
        if (! $v instanceof PersonView) {
            $v = PersonView::for($this->login($request));
            $request->attributes->set('rental_view', $v);
        }

        return $v;
    }

    private function page(LengthAwarePaginator $p): JsonResponse
    {
        return $this->success(array_values($p->items()), 'Listed successfully.', 200, ['pagination' => RentalsController::pagination($p)]);
    }

    private function pageNo(Request $request): int
    {
        return max(1, (int) $request->query('page', '1'));
    }

    private function perPage(Request $request, int $default = 50): int
    {
        return max(1, min(RentalsController::MAX_PER_PAGE, (int) $request->query('per_page', (string) $default)));
    }

    private function notMine(): JsonResponse
    {
        return $this->error('That record was not found.', 404, ['code' => 'not_found']);
    }

    /** @param callable(): JsonResponse $fn */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            $status = match (true) {
                $e->errorCode() === 'portal_session_invalid' => 401,
                in_array($e->errorCode(), RentalsController::NOT_FOUND, true) => 404,
                in_array($e->errorCode(), RentalsController::CONFLICT, true) => 409,
                default => 422,
            };

            return $this->error($e->getMessage(), $status, $e->toErrors());
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return $this->error('Give dates like 2026-10-31.', 422, ['code' => 'date_invalid']);
        } catch (\ErrorException|\TypeError $e) {
            if (! RentalsController::listWhereOneValueBelongs($e)) {
                throw $e;
            }

            return $this->error('One of the fields holds a list where one value belongs.', 422, ['code' => 'field_invalid']);
        }
    }
}
