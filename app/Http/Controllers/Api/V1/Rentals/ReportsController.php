<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reports (rentals_reports, docs/estate-ledger/04-architecture.md §17): the registry, a report as json, or a signed link to its CSV, XLSX or PDF. */
class ReportsController extends RentalsController
{
    public const FORMATS = ['json', 'csv', 'xlsx', 'pdf'];

    /** GET rentals/reports: the reports this account has, with their filters and formats. */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_reports');

            $owner = RentalsModule::isOwnerMode($this->cid($request));
            $scoped = RentalAccess::propertyIds($this->user($request)) !== null; // a login tied to some properties is never offered the whole account's money (as on the web)

            return $this->success(array_values(array_map(fn ($r) => $r + ['formats' => self::FORMATS, 'needs' => RentalReports::needs($r['key'], $owner)],
                array_filter(RentalReports::registry($this->cid($request)), fn ($r) => ! $scoped || RentalReports::REPORTS[$r['key']][3] !== 'account'))));
        });
    }

    /** GET rentals/reports/{key}?format=json|csv|xlsx|pdf&from&to&property_id&landlord_id&as_of&days&lease_id&method */
    public function show(Request $request, string $key): JsonResponse
    {
        return $this->attempt(function () use ($request, $key) {
            $by = $this->user($request);
            $cid = $this->cid($request);
            $format = (string) $request->query('format', 'json');
            if (! in_array($format, self::FORMATS, true)) {
                throw BusinessRuleException::make('unknown_format', 'Ask for a report as json, csv, xlsx or pdf.');
            }
            $params = $request->only(RentalLinks::REPORT_PARAMS);
            if ($format === 'json') {
                return $this->success(self::onScreen((new RentalReports())->run($key, $cid, $by, $params)));
            }
            RentalAccess::authorize($by, 'rentals_reports');
            $def = RentalReports::open($key) ? RentalReports::REPORTS[$key] : throw BusinessRuleException::make('unknown_report', "There is no report called \"{$key}\".");
            if ($def[3] === 'account' && RentalAccess::propertyIds($by) !== null) {
                throw BusinessRuleException::make('forbidden', 'This report covers the whole account\'s money. Ask your property manager for it.');
            }
            if ($def[4] && RentalsModule::isOwnerMode($cid)) {
                throw BusinessRuleException::make('agency_only', 'This report is for agencies that manage property for landlords.');
            }

            // No link to a file that could only say "choose one": the refusal belongs in the app, not in a browser.
            RentalReports::refuseWithout($key, RentalsModule::isOwnerMode($cid), $params);

            return $this->success(RentalLinks::report($cid, (int) $by->id, $key, $format, $params), 'Link ready.');
        });
    }

    /**
     * GET rentals/leases/{id}/statement?format=json|csv|xlsx|pdf&from&to: a tenancy's statement for anyone who may see the lease (rentals_view, their properties). It belongs with the lease, not
     * with the reports permission, as on the web.
     */
    public function leaseStatement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);
            RentalAccess::authorize($this->user($request), 'rentals_view');
            $format = (string) $request->query('format', 'json');
            if (! in_array($format, self::FORMATS, true)) {
                throw BusinessRuleException::make('unknown_format', 'Ask for a report as json, csv, xlsx or pdf.');
            }
            $period = $request->only(['from', 'to']);
            if ($format === 'json') {
                return $this->success(self::onScreen((new RentalReports())->run('tenant_statement', $this->cid($request), null, ['lease_id' => (int) $lease->id] + $period)));
            }

            return $this->success(RentalLinks::leaseStatement($this->cid($request), (int) $lease->id, (int) $this->user($request)->id, $format, $period), 'Link ready.');
        });
    }

    /**
     * A report as a screen reads it: its own table, and the totals that have no column (RentalExport::extraTotals), with the words the file prints.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function onScreen(array $report): array
    {
        return $report + ['extra_totals' => array_map(fn (array $t) => ['label' => $t[0], 'value' => $t[1], 'type' => $t[2]], RentalExport::extraTotals($report))];
    }
}
