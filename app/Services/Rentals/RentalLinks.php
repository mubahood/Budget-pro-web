<?php

namespace App\Services\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalPayment;
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

    /** Who asks: a staff login (`staff`, user id) or a tenant's or landlord's own sign-in (`portal`, login id). */
    public const VIEWERS = ['staff', 'portal'];

    /** @return array{url: string, expires_in: int} */
    public static function receiptPdf(RentalPayment $payment, string $viewer, int $viewerId): array
    {
        return self::sign('rentals.pdf.receipt', ['company_id' => (int) $payment->company_id, 'payment_id' => (int) $payment->id, 'by' => $viewer, 'by_id' => $viewerId]);
    }

    /** @return array{url: string, expires_in: int} */
    public static function statementPdf(RentalLandlord $landlord, string $from, string $to, string $viewer, int $viewerId): array
    {
        return self::sign('rentals.pdf.statement', ['company_id' => (int) $landlord->company_id, 'landlord_id' => (int) $landlord->id, 'from' => $from, 'to' => $to,
            'by' => $viewer, 'by_id' => $viewerId]);
    }

    /** The filters a report download carries (RentalReports): ids and dates only. */
    public const REPORT_PARAMS = ['month', 'from', 'to', 'property_id', 'landlord_id', 'as_of', 'days', 'lease_id', 'method', 'q', 'status', 'state', 'category', 'bucket', 'view'];

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
    private static function sign(string $route, array $params): array
    {
        return ['url' => url(URL::temporarySignedRoute($route, now()->addMinutes(self::MINUTES), $params, false)), 'expires_in' => self::MINUTES * 60];
    }
}
