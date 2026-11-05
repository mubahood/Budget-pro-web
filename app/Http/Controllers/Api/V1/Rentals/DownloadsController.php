<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\RentalPayoutRun;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Landlords\PayoutAdvice;
use App\Services\Rentals\Landlords\PayoutRuns;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\LandlordReports;
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

            $copy = $request->filled('statement_id') ? StatementBook::byId($l, (int) $request->query('statement_id')) : null;
            if ($request->filled('statement_id') && $copy === null) {
                return $this->missing();
            }

            return response(StatementPdf::render($l, $from, $to, $copy?->id), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.StatementPdf::filename($l, $from, $to).'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/pdf/payout-advice?company_id&payout_id&by&by_id (signed) */
    public function payoutAdvice(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $p = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('payout_id'));
            if ($p === null || RentalsModule::isOwnerMode($cid) || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_landlords') && RentalAccess::propertyIds($u) === null,
                fn (RentalLogin $login) => $login->subject_type === 'landlord' && (int) $login->subject_id === (int) $p->landlord_id)) {
                return $this->missing();
            }

            return response(PayoutAdvice::pdf($p), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.PayoutAdvice::filename($p).'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/pdf/tenant-statement?company_id&lease_id&by&by_id (signed): the tenant's own statement, read again through their own sign-in. */
    public function tenantStatement(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $login = $request->query('by') === 'portal' ? app(RentalLogins::class)->resolve((int) $request->query('by_id')) : null;
            if ($login === null || (int) $login->company_id !== $cid || $login->subject_type !== 'tenant') {
                return $this->missing();
            }
            $file = PersonView::for($login)->tenantStatementFile((int) $request->query('lease_id'));
            if ($file === null) {
                return $this->missing();
            }

            return response($file['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$file['filename'].'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /**
     * GET rentals/pdf/lease-statement?company_id&lease_id&format&from&to&by&by_id (signed): a tenancy's statement for staff who may see the lease, read again for them. Who may see it is
     * checked here: it belongs with the lease, not with the reports permission (the web's own link).
     */
    public function leaseStatement(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('lease_id'));
            if ($lease === null || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_view') && RentalAccess::inScope($u, (int) $lease->property_id), fn (RentalLogin $l) => false)) {
                return $this->missing();
            }
            $report = (new RentalReports())->run('tenant_statement', $cid, null, ['lease_id' => (int) $lease->id] + $request->only(['from', 'to']));
            [$bytes, $type, $name] = RentalExport::render($report, $cid, (string) $request->query('format'));

            return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/pdf/year-summary?company_id&landlord_id&year&format&by&by_id (signed): a landlord's year, for them or for staff of the whole account. */
    public function yearSummary(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $year = (string) $request->query('year');
            $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('landlord_id'));
            if ($l === null || preg_match('/^\d{4}$/', $year) !== 1 || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_landlords') && RentalAccess::propertyIds($u) === null,
                fn (RentalLogin $login) => $login->subject_type === 'landlord' && (int) $login->subject_id === (int) $l->id)) {
                return $this->missing();
            }
            $format = (string) $request->query('format') === 'xlsx' ? 'xlsx' : 'pdf';
            [$bytes, $type, $name] = LandlordReports::yearFile($l, $year, $format);

            return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** GET rentals/pdf/payout-run?company_id&run_id&format&by&by_id (signed): the sheet to take to the bank. */
    public function payoutRun(Request $request): Response
    {
        return $this->guard($request, function () use ($request) {
            $cid = (int) $request->query('company_id');
            $run = RentalPayoutRun::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->query('run_id'));
            if ($run === null || RentalsModule::isOwnerMode($cid) || ! $this->maySee($request, $cid, fn (User $u) => RentalAccess::can($u, 'rentals_landlords') && RentalAccess::propertyIds($u) === null, fn (RentalLogin $l) => false)) {
                return $this->missing();
            }
            [$bytes, $type, $name] = RentalExport::render(PayoutRuns::sheet($run), $cid, (string) $request->query('format'));

            return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
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
