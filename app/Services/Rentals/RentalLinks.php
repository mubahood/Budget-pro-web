<?php

namespace App\Services\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\StoredFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Estate Ledger's signed links (docs/estate-ledger/04-architecture.md §9, §18), for a phone's browser that has no session: a
 * receipt or a landlord statement as a PDF (30 minutes), and a photo or document (`files`) of the account. A link
 * carries ids only, never a name, amount or phone number; opening a PDF or a private file (PrivateFiles) checks again
 * that the person who asked may still see it (a staff login's permission and properties, or a tenant's or landlord's
 * own sign-in).
 */
final class RentalLinks
{
    public const MINUTES = 30;

    /** A link sent to a landlord in a message: a week. */
    public const SHARED_MINUTES = 10080;

    /** Who asks: a staff login (`staff`, user id) or a tenant's or landlord's own sign-in (`portal`, login id). */
    public const VIEWERS = ['staff', 'portal'];

    /** @return array{url: string, expires_in: int} */
    public static function receiptPdf(RentalPayment $payment, string $viewer, int $viewerId): array
    {
        return self::sign('rentals.pdf.receipt', ['company_id' => (int) $payment->company_id, 'payment_id' => (int) $payment->id, 'by' => $viewer, 'by_id' => $viewerId]);
    }

    /**
     * A landlord's statement as a PDF. $minutes is longer for a link put in a message to the landlord, who may open it
     * days later (SHARED_MINUTES); it still checks the person who made it may see the statement. $statementId names one
     * issued statement, so a link keeps opening that copy.
     *
     * @return array{url: string, expires_in: int}
     */
    public static function statementPdf(RentalLandlord $landlord, string $from, string $to, string $viewer, int $viewerId, int $minutes = self::MINUTES, ?int $statementId = null): array
    {
        return self::sign('rentals.pdf.statement', ['company_id' => (int) $landlord->company_id, 'landlord_id' => (int) $landlord->id, 'from' => $from, 'to' => $to,
            'by' => $viewer, 'by_id' => $viewerId] + ($statementId !== null ? ['statement_id' => $statementId] : []), $minutes);
    }

    /**
     * A payout's advice as a PDF. $minutes as for a statement: longer for a link put in a message to the landlord.
     *
     * @return array{url: string, expires_in: int}
     */
    public static function payoutAdvicePdf(RentalPayout $payout, string $viewer, int $viewerId, int $minutes = self::MINUTES): array
    {
        return self::sign('rentals.pdf.payout-advice', ['company_id' => (int) $payout->company_id, 'payout_id' => (int) $payout->id, 'by' => $viewer, 'by_id' => $viewerId], $minutes);
    }

    /**
     * A tenant's statement for one of their homes as a PDF, from the day the lease began to today (PersonView::tenantStatementFile), for their own sign-in.
     *
     * @return array{url: string, expires_in: int}
     */
    public static function tenantStatementPdf(int $companyId, int $leaseId, int $loginId): array
    {
        return self::sign('rentals.pdf.tenant-statement', ['company_id' => $companyId, 'lease_id' => $leaseId, 'by' => 'portal', 'by_id' => $loginId]);
    }

    /**
     * A lease's statement for staff who may see the lease: it belongs with the lease, not with the reports permission (as the web's own link). `format` is pdf, xlsx or csv.
     *
     * @param  array<string, mixed>  $period  from, to (optional)
     * @return array{url: string, expires_in: int}
     */
    public static function leaseStatement(int $companyId, int $leaseId, int $userId, string $format = 'pdf', array $period = []): array
    {
        $period = array_filter(array_intersect_key($period, array_flip(['from', 'to'])), fn ($v) => is_string($v) && $v !== '');

        return self::sign('rentals.pdf.lease-statement', ['company_id' => $companyId, 'lease_id' => $leaseId, 'format' => $format, 'by' => 'staff', 'by_id' => $userId] + $period);
    }

    /**
     * A landlord's year summary as a PDF or Excel file. $minutes as for a statement: longer for a link put in a message to the landlord.
     *
     * @return array{url: string, expires_in: int}
     */
    public static function yearSummary(RentalLandlord $landlord, string $year, string $format, string $viewer, int $viewerId, int $minutes = self::MINUTES): array
    {
        return self::sign('rentals.pdf.year-summary', ['company_id' => (int) $landlord->company_id, 'landlord_id' => (int) $landlord->id, 'year' => $year, 'format' => $format, 'by' => $viewer, 'by_id' => $viewerId], $minutes);
    }

    /**
     * A payout run's sheet (PayoutRuns::sheet) as a PDF, Excel or CSV file, for a staff login.
     *
     * @return array{url: string, expires_in: int}
     */
    public static function payoutRunSheet(int $companyId, int $runId, string $format, int $userId): array
    {
        return self::sign('rentals.pdf.payout-run', ['company_id' => $companyId, 'run_id' => $runId, 'format' => $format, 'by' => 'staff', 'by_id' => $userId]);
    }

    /** The filters a report download carries (RentalReports): ids and dates only. */
    public const REPORT_PARAMS = ['month', 'year', 'from', 'to', 'property_id', 'landlord_id', 'as_of', 'days', 'lease_id', 'method', 'q', 'status', 'state', 'category', 'bucket', 'view'];

    /**
     * A report as CSV, XLSX or PDF (staff only); opening it runs the report again for the person who asked.
     *
     * @param  array<string, mixed>  $params
     * @return array{url: string, expires_in: int}
     */
    public static function report(int $companyId, int $userId, string $key, string $format, array $params): array
    {
        $params = array_filter(array_intersect_key($params, array_flip(self::REPORT_PARAMS)), fn ($v) => is_scalar($v) && (string) $v !== '');

        return self::sign('rentals.report.download', ['company_id' => $companyId, 'user_id' => $userId, 'key' => $key, 'format' => $format] + $params);
    }

    /**
     * A photo or document of the account. The expiry is a day boundary at least a week ahead, so the link stays the
     * same all day (a phone's image cache keeps working) and lasts for a week of offline reading. A private file is
     * linked only for a named person ($viewer, $viewerId).
     */
    public static function file(int $companyId, ?string $uuid, ?string $viewer = null, ?int $viewerId = null): ?string
    {
        return self::files($companyId, [$uuid], $viewer, $viewerId)[(string) $uuid] ?? null;
    }

    /**
     * Links for many files at once (one query). Unknown uuids, files of another account, and private files with no
     * person named get none.
     *
     * @param  iterable<?string>  $uuids
     * @return array<string, string> uuid => url
     */
    public static function files(int $companyId, iterable $uuids, ?string $viewer = null, ?int $viewerId = null): array
    {
        $list = [];
        foreach ($uuids as $u) {
            if (is_string($u) && Str::isUuid($u)) {
                $list[] = $u;
            }
        }
        if ($list === []) {
            return [];
        }
        $known = StoredFile::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('uuid', array_values(array_unique($list)))->pluck('purpose', 'uuid')->all();
        $person = in_array($viewer, self::VIEWERS, true) && $viewerId !== null ? ['by' => $viewer, 'by_id' => $viewerId] : null;
        $expires = Carbon::now()->startOfDay()->addDays(8);
        $out = [];
        foreach ($known as $u => $purpose) {
            $private = PrivateFiles::isPrivate($purpose);
            if ($private && $person === null) {
                continue;
            }
            $out[(string) $u] = url(URL::temporarySignedRoute('rentals.file', $expires, ['uuid' => (string) $u, 'company_id' => $companyId] + ($private ? $person : []), false));
        }

        return $out;
    }

    /** @return array{url: string, expires_in: int} */
    private static function sign(string $route, array $params, int $minutes = self::MINUTES): array
    {
        return ['url' => url(URL::temporarySignedRoute($route, now()->addMinutes($minutes), $params, false)), 'expires_in' => $minutes * 60];
    }
}
