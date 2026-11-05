<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Whose money the cash is, and what the agency takes out of its own (plan §7.3): the whole account's money, so never a login tied to some properties. */
class MoneyController extends RentalsController
{
    /** GET rentals/money-position?as_of= */
    public function position(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_landlords');
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $today = LocalDate::today($cid)->toDateString();
            $asOf = $this->day($request, 'as_of') ?? $today;
            if ($asOf > $today) {
                throw BusinessRuleException::make('date_in_future', 'That day has not come yet.');
            }

            return $this->success(['currency' => $this->present($request)->currency()] + MoneyPosition::asOf($cid, $asOf));
        });
    }

    /** POST rentals/cash/drawings {method, amount_minor, on, note, below_zero_agreed}: cash −, equity −; no landlord's statement moves. */
    public function drawing(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $row = app(CashBook::class)->agencyDrawing($this->user($request), (string) $request->input('method', ''), $this->minor($request, 'amount_minor'), $this->day($request, 'on'),
                $request->filled('note') ? (string) $request->input('note') : null, $request->boolean('below_zero_agreed'));

            return $this->created($this->row($request, $row) + ['method' => (string) $row->method], 'Saved.');
        });
    }

    /** POST rentals/cash/drawings/{id}/reverse {reason}: a reversal dated today. */
    public function reverseDrawing(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $row = app(CashBook::class)->reverseDrawing($this->user($request), $id, (string) $request->input('reason', ''));

            return $this->success($this->row($request, $row) + ['reverses_id' => (int) $row->reverses_id], 'Reversed.');
        });
    }

    /** @return array<string, mixed> */
    private function row(Request $request, RentalEntry $e): array
    {
        return ['id' => (int) $e->id, 'type' => (string) $e->type, 'amount_minor' => abs((int) $e->cash_minor), 'on' => $e->occurred_on->toDateString(), 'description' => (string) $e->description, 'balances' => CashBook::balances($this->cid($request))];
    }
}
