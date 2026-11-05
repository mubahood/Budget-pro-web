<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Portal\PaidReports;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\Search\RentalFinder;
use App\Services\Rentals\Today\RentBook;
use App\Services\Rentals\Today\TodayWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the phone reads in the field (plan §7.1): the offline rent book, Today's work, and live search. All of it narrowed to the person's properties. */
class FieldController extends RentalsController
{
    /** GET rentals/rent-book: the whole book as one document, with an ETag: 304 and no body when nothing has changed. */
    public function rentBook(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_view');
            $book = (new RentBook($this->user($request)))->read();
            $etag = RentBook::etag($book);
            $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];
            if (RentBook::matches($request->header('If-None-Match'), $etag)) {
                return (new JsonResponse(null, 304, $headers))->setContent('');
            }

            return $this->success($book)->withHeaders($headers);
        });
    }

    /** GET rentals/today: the web's Today, as data. */
    public function today(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            RentalAccess::authorize($by, 'rentals_view');
            $work = new TodayWork($by);
            $first = $work->firstSteps();
            $figures = Figures::overview($by);
            $present = $this->present($request)->prime($figures['today']);
            $figures['today'] = array_map(fn ($p) => $present->paymentBrief($p), $figures['today']);
            if (RentalAccess::propertyIds($by) !== null) {
                $figures['landlords_minor'] = null; // what is owed to landlords is the whole account's, so a login tied to some properties never gets it (EL-26)
            }

            return $this->success(['set_up' => TodayWork::isSetUp($this->cid($request)), 'first_steps' => $first, 'groups' => $first === null ? $work->groups() : [], 'figures' => $figures, 'board' => $work->board(), 'money_position' => $first === null ? $work->moneyPosition() : null,
                'my_cash' => $work->myCash(), 'paid_reports_waiting' => RentalAccess::can($by, 'rentals_collect') ? PaidReports::pendingCount($this->cid($request), $by) : 0, 'month_end_next' => $first === null ? $work->monthEndNext() : null]);
        });
    }

    /** GET rentals/search/marks: pinned and recent records, for when nothing is typed (the same marks as the web's search). */
    public function marks(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_view');

            return $this->success((new RentalFinder($this->user($request)))->home());
        });
    }

    /** POST rentals/search/opened {kind, id}: this person opened this record. A note: one they may not see is ignored, never an error. */
    public function opened(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_view');
            [$kind, $id] = $this->mark($request);
            (new RentalFinder($this->user($request)))->opened($kind, $id);

            return $this->success(['ok' => true]);
        });
    }

    /** POST rentals/search/pin {kind, id}: pin or unpin. {pinned}; 422 pin_limit with too many; 404 for what they may not see. */
    public function pin(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_view');
            [$kind, $id] = $this->mark($request);
            $pinned = (new RentalFinder($this->user($request)))->togglePin($kind, $id);
            if ($pinned === null) {
                throw BusinessRuleException::make('pin_limit', 'You can pin up to '.RentalFinder::PIN_LIMIT.' records. Unpin one first.');
            }

            return $this->success(['pinned' => $pinned]);
        });
    }

    /** The record a mark is about: a kind as the search answers name it, and its id. @return array{0: string, 1: int} */
    private function mark(Request $request): array
    {
        $kind = $request->input('kind');
        $id = $request->input('id');
        $index = is_string($kind) ? RentalFinder::indexKind($kind) : null;
        if ($index === null || ! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
            throw BusinessRuleException::make('mark_invalid', 'Say which record, by its kind and id.');
        }

        return [$index, (int) $id];
    }

    /** GET rentals/search?q=: over the search index, by kind, each result with a link. */
    public function find(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_view');

            // Bytes that are not text cannot go back in the answer's `q`: as they stood, the JSON could not be written.
            return $this->success((new RentalFinder($this->user($request)))->find(trim(mb_scrub((string) $request->query('q', ''), 'UTF-8'))));
        });
    }
}
