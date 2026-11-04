<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalPayment;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files behind Estate Ledger's signed links (RentalLinks; no session, the signature is the key): a receipt or a landlord
 * statement as a PDF, a report as CSV, XLSX or PDF, and a photo or document. An expired or altered link answers 403;
 * opening a PDF, a report or a private file checks again that the person who asked may still see it (a staff login's
 * permission and properties, or a tenant's or landlord's own sign-in), so a link from a closed sign-in stops working.
 */
class DownloadsController extends Controller
{
    /** GET rentals/pdf/receipt?company_id&payment_id&by&by_id (signed) */
    public function receipt(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $p = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('payment_id'));
            if ($p === null || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_view') && RentalAccess::inScope($u, (int) $p->property_id),
                fn (RentalLogin $l) => $l->subject_type === 'tenant' && (int) $l->subject_id === (int) $p->tenant_id)) {
                return $this->missing();
            }

            return response(Receipt::pdf($p), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="receipt-'.$p->number.'.pdf"',
                'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/pdf/statement?company_id&landlord_id&from&to&by&by_id (signed) */
    public function statement(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('landlord_id'));
            [$from, $to] = [(string) $request->query('from'), (string) $request->query('to')];
            if ($l === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
                || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_landlords') && RentalAccess::propertyIds($u) === null,
                    fn (RentalLogin $login) => $login->subject_type === 'landlord' && (int) $login->subject_id === (int) $l->id)) {
                return $this->missing();
            }

            return response(StatementPdf::render($l, $from, $to), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.StatementPdf::filename($l, $from, $to).'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/report-download?company_id&user_id&key&format&… (signed): the report, run again for the person who asked. */
    public function report(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $user = User::withoutGlobalScopes()->find((int) $request->query('user_id'));
            if ($user === null || (int) $user->company_id !== $cid || Company::withoutGlobalScopes()->find($cid) === null) {
                return $this->missing();
            }
            $report = (new RentalReports())->run((string) $request->query('key'), $cid, $user, $request->only(RentalLinks::REPORT_PARAMS));
            [$bytes, $type, $name] = RentalExport::render($report, $cid, (string) $request->query('format'));

            return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/files/{uuid}?company_id&by&by_id (signed): a photo or document of the account. */
    public function file(Request $request, string $uuid): Response
    {
        return $this->guard($request, function () use ($request, $uuid) {
            $f = StoredFile::withoutGlobalScopes()->where('company_id', (int) $request->query('company_id'))->where('uuid', $uuid)->first();
            if ($f !== null && $f->isPrivate()) {
                return PrivateFiles::respond($f, $request) ?? $this->missing();
            }
            if ($f === null || ! Storage::disk('public')->exists($f->path)) {
                return $this->missing();
            }

            return Storage::disk('public')->response($f->path, basename((string) $f->path), ['Content-Type' => $f->mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=86400']);
        });
    }

    /**
     * The person behind the link may still see it: a staff login of the account ($staff), or an open sign-in of a
     * tenant or landlord of the account ($portal).
     *
     * @param  callable(User): bool  $staff
     * @param  callable(RentalLogin): bool  $portal
     */
    private function maySee(Request $request, int $cid, callable $staff, callable $portal): bool
    {
        $id = (int) $request->query('by_id');
        if ($request->query('by') === 'staff') {
            $u = User::withoutGlobalScopes()->find($id);

            return $u !== null && (int) $u->company_id === $cid && $staff($u);
        }
        if ($request->query('by') === 'portal') {
            $l = app(RentalLogins::class)->resolve($id);

            return $l !== null && (int) $l->company_id === $cid && $portal($l);
        }

        return false;
    }

    private function missing(): Response
    {
        return response()->json(['code' => 0, 'message' => 'Not found.', 'data' => null, 'errors' => ['code' => 'not_found']], 404);
    }

    /** @param callable(): Response $fn */
    private function guard(Request $request, callable $fn): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return response()->json(['code' => 0, 'message' => 'This link has expired. Ask for it again.', 'data' => null, 'errors' => ['code' => 'link_expired']], 403);
        }
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return response()->json(['code' => 0, 'message' => $e->getMessage(), 'data' => null, 'errors' => $e->toErrors()], match (true) {
                $e->errorCode() === 'forbidden' => 403,
                in_array($e->errorCode(), RentalsController::NOT_FOUND, true) => 404,
                default => 422,
            });
        }
    }
}
