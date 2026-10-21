<?php

namespace App\Services\Sacco\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use Illuminate\Support\Facades\URL;

/**
 * Report downloads through signed links (04 §12, the EventPrintController pattern): the API answers a PDF, XLSX or
 * CSV request with a 30-minute relative-signed link to `sacco.report.download`, which a phone's browser can open
 * without a session. The link carries only ids and the report's parameters, never a name, amount or phone number;
 * opening it checks again that the person may still read the report.
 */
final class SaccoReportLinks
{
    public const MINUTES = 30;

    /** @param array<string, mixed> $params @return array{url: string, expires_in: int} */
    public static function make(Company $company, User $user, string $key, array $params, string $format): array
    {
        if (! in_array($format, ['pdf', 'xlsx', 'csv'], true)) {
            throw BusinessRuleException::make('unknown_format', 'Use format=pdf, xlsx or csv for a download.');
        }
        $permission = SaccoReports::permission($key);
        if (! SaccoAccess::can($user, $permission) && ! SaccoAccess::can($user, 'sacco_audit_view')) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this report.', ['permission' => $permission]);
        }
        $params = array_filter(array_intersect_key($params, array_flip(['from', 'to', 'date', 'member_id', 'account_type', 'account_id'])), fn ($v) => $v !== null && $v !== '');
        $path = URL::temporarySignedRoute('sacco.report.download', now()->addMinutes(self::MINUTES), ['company_id' => $company->id, 'user_id' => $user->id, 'key' => $key,
            'format' => $format] + $params, false);

        return ['url' => url($path), 'expires_in' => self::MINUTES * 60];
    }
}
