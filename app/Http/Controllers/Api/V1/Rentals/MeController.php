<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\RentalLogin;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\Portal\TenantRequests;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A tenant's or landlord's own records (`rentals/me/*`, a portal token from PortalAuthController, KR-16). Every answer
 * comes from PersonView or TenantRequests keyed by the token's sign-in, never by a request parameter: a record that
 * is not the person's is a 404.
 */
class MeController extends Controller
{
    use ApiResponse;

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

    // ── A tenant ──

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

            return $request->query('format') === 'pdf' ? $this->success($view->statementPdf($month), 'Link ready.') : $this->success($view->statement($month));
        });
    }

    /** GET rentals/me/payouts */
    public function payouts(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->payouts($this->pageNo($request), $this->perPage($request))));
    }

    /** GET rentals/me/arrears: by unit, read only. */
    public function arrears(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->page($this->view($request)->arrears($this->pageNo($request), $this->perPage($request, 100))));
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
