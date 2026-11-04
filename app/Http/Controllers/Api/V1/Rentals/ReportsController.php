<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
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

            return $this->success(array_map(fn ($r) => $r + ['formats' => self::FORMATS], RentalReports::registry($this->cid($request))));
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
                return $this->success((new RentalReports())->run($key, $cid, $by, $params));
            }
            RentalAccess::authorize($by, 'rentals_reports');
            $def = RentalReports::REPORTS[$key] ?? throw BusinessRuleException::make('unknown_report', "There is no report called \"{$key}\".");
            if ($def[3] === 'account' && RentalAccess::propertyIds($by) !== null) {
                throw BusinessRuleException::make('forbidden', 'This report covers the whole account\'s money. Ask your property manager for it.');
            }
            if ($def[4] && RentalsModule::isOwnerMode($cid)) {
                throw BusinessRuleException::make('agency_only', 'This report is for agencies that manage property for landlords.');
            }

            return $this->success(RentalLinks::report($cid, (int) $by->id, $key, $format, $params), 'Link ready.');
        });
    }
}
