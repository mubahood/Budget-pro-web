<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalStatement;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\MonthEndFigures;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\CommissionReport;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The month before it is final, closing it, and sending its statements (plan §7.3). Closing and every money rule are MonthClose's and StatementBook's. Sending prepares links for this
 * person's own phone: the server sends nothing and nothing here says delivered. Never a login tied to some properties, whatever its role was given.
 */
class MonthEndController extends RentalsController
{
    /** The close runs in the request, in one transaction: this long is allowed it. If it dies, nothing happened. */
    private const CLOSE_SECONDS = 120;

    /** GET rentals/month-end?month=&check=1 */
    public function show(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            if (! RentalAccess::can($by, 'rentals_close') && ! RentalAccess::can($by, 'rentals_landlords')) {
                throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask your property manager.');
            }
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $next = MonthClose::nextMonth($cid);
            $latest = MonthClose::latest($cid)?->month->format('Y-m');
            $month = $this->monthFrom($request, $cid, $next ?? $latest ?? LocalDate::today($cid)->format('Y-m'));
            $through = MonthClose::closedThrough($cid);
            $closed = MonthClose::isClosedMonth($cid, $month);
            $owner = RentalsModule::isOwnerMode($cid);
            $figures = MonthEndFigures::month($cid, $month);
            $problems = $request->boolean('check') ? app(RentalCheck::class)->run($cid) : [];

            return $this->success([
                'month' => $month, 'name' => MonthClose::name($month), 'from' => $figures['from'], 'to' => $figures['to'], 'currency' => $this->present($request)->currency(), 'owner' => $owner,
                'status' => $closed ? 'closed' : 'open', 'closed_through' => $through, 'next_month' => $next, 'latest_closed' => $latest,
                'can_close' => RentalAccess::can($by, 'rentals_close') && ! $closed && $next !== null && ($through !== null ? $month === $next : $month <= $next),
                'can_reopen' => RentalAccess::can($by, 'rentals_reopen') && $closed && MonthClose::lastDay($month) === $through,
                'first_choices' => $through === null && $next !== null ? $this->firstChoices($cid, $next) : null,
                'look_at' => MonthEndFigures::lookAt($cid, $month) + ['commission_exceptions' => $owner ? 0 : count(CommissionReport::exceptions($cid, $figures['from'], $figures['to']))],
                'books' => ['checked' => $request->boolean('check'), 'problems' => array_map(fn ($p) => ['check' => $p['check'], 'detail' => $p['detail']], $problems)],
                'figures' => ['rows' => $figures['rows'], 'totals' => $figures['totals'], 'last' => $figures['last']],
                'send' => $closed ? $this->sendList($request, $month) : [],
            ]);
        });
    }

    /** POST rentals/month-end/close {month}: closes it and issues its statements, in one step. */
    public function close(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $previous = (int) ini_get('max_execution_time');
            @set_time_limit(self::CLOSE_SECONDS);
            try {
                $close = app(MonthClose::class)->close($this->user($request), (string) $request->input('month', ''));
            } finally {
                @set_time_limit($previous);
            }
            $cid = $this->cid($request);

            return $this->success(['month' => $close->month->format('Y-m'), 'status' => (string) $close->status, 'version' => (int) $close->version, 'closed_at' => $close->closed_at?->toIso8601String(),
                'closed_through' => MonthClose::closedThrough($cid), 'next_month' => MonthClose::nextMonth($cid), 'statements' => array_map('intval', (array) ($close->totals['statements'] ?? []))], 'Closed.');
        });
    }

    /** POST rentals/month-end/reopen {month, reason}: the latest closed month, by the account owner. */
    public function reopen(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $close = app(MonthClose::class)->reopen($this->user($request), (string) $request->input('month', ''), (string) $request->input('reason', ''));

            return $this->success(['month' => $close->month->format('Y-m'), 'status' => (string) $close->status, 'reopened_at' => $close->reopened_at?->toIso8601String(), 'reopen_reason' => $close->reopen_reason,
                'closed_through' => MonthClose::closedThrough($this->cid($request))], 'Reopened.');
        });
    }

    /** GET rentals/statements/{id}/message: a week's signed link to the PDF, and the words for this person's own phone. */
    public function message(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            return $this->success($this->messageOf($request, $this->statement($request, $id)));
        });
    }

    /** POST rentals/statements/{id}/sent {via: whatsapp|sms|link}: written down as prepared, never as delivered. */
    public function sent(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $s = $this->statement($request, $id);
            $via = (string) $request->input('via', '');
            if (! in_array($via, ['whatsapp', 'sms', 'link'], true)) {
                throw BusinessRuleException::make('via_invalid', 'Say how it went: WhatsApp, SMS or a link.');
            }
            $by = $this->user($request);
            if ($via !== 'link') {
                $row = $this->messageOf($request, $s);
                RentalMessages::recordPrepared($this->cid($request), $via, $row['phone'], $row['text'], 'rentals_statement', (int) $by->id);
            }
            StatementBook::markPrepared($s, $via, (int) $by->id);
            $s = $s->fresh();

            return $this->success(['statement_id' => (int) $s->id, 'via' => $via, 'prepared_at' => $s->prepared_at?->toIso8601String(), 'words' => StatementBook::sentWords($s, [(int) $by->id => (string) $by->name])], 'Written down.');
        });
    }

    private function statement(Request $request, int $id): RentalStatement
    {
        RentalAccess::authorize($this->user($request), 'rentals_landlords');
        $this->wholeAccount($request);

        return RentalStatement::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('status', 'issued')->find($id) ?? throw $this->notFoundRecord('statement');
    }

    /** @return array<string, mixed> */
    private function messageOf(Request $request, RentalStatement $s): array
    {
        $cid = $this->cid($request);
        $by = $this->user($request);
        $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($s->landlord_id) ?? throw $this->notFoundRecord('statement');
        $month = Carbon::parse($s->month)->format('Y-m');
        $link = RentalLinks::statementPdf($l, $month.'-01', MonthClose::lastDay($month), 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES, (int) $s->id);
        $text = RentalMessages::forStatement($cid, (string) $l->name, MonthClose::name($month), (int) $s->closing_minor, $link['url']);
        $phone = trim((string) $l->phone_e164) ?: null;
        $hasSignIn = RentalLogins::portalOnPlan($this->company($request)) && DB::table('rental_logins')->where('company_id', $cid)->where('subject_type', 'landlord')->where('subject_id', $l->id)->whereNull('disabled_at')->exists();

        return ['statement_id' => (int) $s->id, 'landlord_id' => (int) $l->id, 'name' => (string) $l->name, 'number' => (string) $s->number, 'month' => $month, 'closing_minor' => (int) $s->closing_minor, 'phone' => $phone, 'email' => $l->email,
            'has_sign_in' => $hasSignIn, 'words' => StatementBook::sentWords($s, $this->preparers([$s])), 'link' => $link['url'], 'expires_in' => $link['expires_in'], 'text' => $text,
            'whatsapp' => RentalMessages::whatsappLink($phone, $text), 'sms' => RentalMessages::smsLink($phone, $text)];
    }

    /** Who gets a closed month's statements, by name. @return list<array<string, mixed>> */
    private function sendList(Request $request, string $month): array
    {
        $cid = $this->cid($request);
        $statements = StatementBook::forMonth($cid, $month);
        if ($statements === []) {
            return [];
        }
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', array_keys($statements))->get()->keyBy('id');
        $logins = DB::table('rental_logins')->where('company_id', $cid)->where('subject_type', 'landlord')->whereNull('disabled_at')->whereIn('subject_id', array_keys($statements))->pluck('subject_id')->map(fn ($v) => (int) $v)->flip();
        $staff = $this->preparers(array_values($statements));
        $portalOn = RentalLogins::portalOnPlan($this->company($request));
        $out = [];
        foreach ($statements as $landlordId => $s) {
            $l = $landlords->get($landlordId);
            if ($l !== null) {
                $out[] = ['statement_id' => (int) $s->id, 'landlord_id' => (int) $l->id, 'name' => (string) $l->name, 'number' => (string) $s->number, 'closing_minor' => (int) $s->closing_minor, 'phone' => trim((string) $l->phone_e164) ?: null,
                    'email' => $l->email, 'has_sign_in' => $portalOn && $logins->has((int) $l->id), 'words' => StatementBook::sentWords($s, $staff)];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @param list<RentalStatement> $statements @return array<int, string> user id => name */
    private function preparers(array $statements): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (RentalStatement $s) => (int) $s->prepared_by_id, $statements))));

        return $ids === [] ? [] : array_map('strval', DB::table('admin_users')->whereIn('id', $ids)->pluck('name', 'id')->all());
    }

    /** With nothing closed yet, any month back to the first money or the first lease, newest first. @return list<array{month: string, name: string}> */
    private function firstChoices(int $cid, string $latest): array
    {
        $start = DB::table('rental_entries')->where('company_id', $cid)->min('occurred_on') ?? DB::table('rental_leases')->where('company_id', $cid)->min('start_date');
        $from = $start !== null ? Carbon::parse(substr((string) $start, 0, 7).'-01') : Carbon::parse($latest.'-01');
        $out = [];
        for ($m = Carbon::parse($latest.'-01'), $i = 0; $m->gte($from) && $i < 120; $m->subMonthNoOverflow(), $i++) {
            $out[] = ['month' => $m->format('Y-m'), 'name' => $m->format('F Y')];
        }

        return $out;
    }
}
