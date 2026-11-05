<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalPaidReport;
use App\Services\Rentals\Portal\PaidReports;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** "I have paid": what tenants say they paid, waiting for this person (plan §7.2). A report is a claim; confirming makes the real receipt, once, through PaidReports. */
class PaidReportsController extends RentalsController
{
    /** The queue is short by nature: this many at most is read. */
    private const MOST = 500;

    /** GET rentals/paid-reports: what waits for this person, oldest first. */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            RentalAccess::authorize($by, 'rentals_collect');
            $p = $this->paginateArray(PaidReports::pending($this->cid($request), $by, self::MOST), $request);
            $present = $this->present($request)->primeFiles(array_column($p->items(), 'photo_uuid'));

            return $this->page($p, array_map(fn (array $r) => ['photo_url' => $present->fileUrl($r['photo_uuid']), 'reported_at' => Carbon::parse($r['reported_at'])->toIso8601String()] + $r, $p->items()), ['currency' => $present->currency()]);
        });
    }

    /** POST rentals/paid-reports/{id}/confirm: the real receipt; answers with the Payment. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $by = $this->user($request);
            $p = app(PaidReports::class)->confirm($by, $this->report($request, $id));

            return $this->created($this->present($request)->prime([$p])->payment($p, PaymentsController::receiptOf($p, 'staff', (int) $by->id)), 'Payment received.');
        });
    }

    /** POST rentals/paid-reports/{id}/decline {reason}: nothing moves, and the tenant reads why. */
    public function decline(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $r = app(PaidReports::class)->decline($this->user($request), $this->report($request, $id), (string) $request->input('reason', ''));

            return $this->success(['id' => (int) $r->id, 'status' => (string) $r->status, 'decline_reason' => $r->decline_reason, 'decided_at' => $r->decided_at?->toIso8601String()], 'Declined.');
        });
    }

    private function report(Request $request, int $id): RentalPaidReport
    {
        return PaidReports::find($this->cid($request), $this->user($request), $id) ?? throw $this->notFoundRecord('report');
    }
}
