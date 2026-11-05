<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\Withholding;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the office does with a landlord's money and papers after the books are written (the web's month end, Year summaries and payout form): what is held back for them, their year, and the
 * statement that follows a payout. Sending prepares a link for this person's own phone: the server sends nothing, and nothing here says delivered. An agency's business, so never an owner-run
 * account, and never a login tied to some properties, whatever its role was given.
 */
class LandlordMoneyController extends RentalsController
{
    /** GET rentals/month-end/withholding?month=Y-m: the agency's own percents for a month over, per landlord who has one, what is recorded and what is left. Read only. */
    public function withholding(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            if (! RentalAccess::can($by, 'rentals_close') && ! RentalAccess::can($by, 'rentals_landlords')) {
                throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask your property manager.');
            }
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $months = $this->holdingMonths($cid);
            $asked = (string) $request->query('month', '');
            $month = isset($months[$asked]) ? $asked : (MonthClose::nextMonth($cid) ?? (string) array_key_first($months));

            return $this->success(['on' => Withholding::on($cid), 'month' => $month, 'name' => MonthClose::name($month), 'currency' => $this->present($request)->currency(), 'can_record' => RentalAccess::can($by, 'rentals_expenses'),
                'months' => array_map(fn (string $k, string $name) => ['month' => $k, 'name' => $name], array_keys($months), array_values($months)), 'rows' => Withholding::suggestions($cid, $month)]);
        });
    }

    /** POST rentals/month-end/withholding {landlord_id, month, amount_minor, method, paid_on, vendor}: it has been paid over: an expense charged to the landlord, for that month, on the day the money went. */
    public function recordWithholding(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            RentalAccess::authorize($by, 'rentals_expenses');
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find((int) $request->input('landlord_id')) ?? throw $this->notFoundRecord('landlord');
            $month = (string) $request->input('month', '');
            if (! isset($this->holdingMonths($cid)[$month])) {
                throw BusinessRuleException::make('month_invalid', 'Choose the month this is for.');
            }
            if (Withholding::on($cid) && collect(Withholding::suggestions($cid, $month))->firstWhere('landlord_id', (int) $landlord->id) === null) {
                throw $this->notFoundRecord('landlord'); // no percent typed for them: nothing is held back for this landlord
            }
            $this->day($request, 'paid_on');
            $expense = app(Withholding::class)->record($by, $landlord, $month, ['amount_minor' => $this->minor($request, 'amount_minor'), 'method' => $request->input('method', 'bank'), 'paid_on' => $request->input('paid_on'),
                'vendor' => trim((string) $request->input('vendor', '')) ?: null]);

            return $this->created($this->present($request)->prime([$expense])->expense($expense), 'Recorded.');
        });
    }

    /** GET rentals/year-summaries?year=Y: every landlord with their year summary, and a message with its link for this person's own phone. Nothing goes out from here. */
    public function yearSummaries(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            [$cid, $by] = $this->agencyDesk($request);
            RentalAccess::authorize($by, 'rentals_reports');
            $thisYear = (int) LocalDate::today($cid)->format('Y');
            $asked = (int) $request->query('year', '0');
            $year = (string) ($asked >= 2000 && $asked <= $thisYear ? $asked : $thisYear - 1);
            $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('is_self', false)->orderBy('name')->get();
            $balances = DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', $year.'-12-31')->whereNotNull('landlord_id')->groupBy('landlord_id')->selectRaw('landlord_id, SUM(landlord_minor) AS b')->pluck('b', 'landlord_id');
            $logins = DB::table('rental_logins')->where('company_id', $cid)->where('subject_type', 'landlord')->whereNull('disabled_at')->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
            $portal = RentalLogins::portalOnPlan($this->company($request));
            $prepared = DB::table('message_log as m')->leftJoin('admin_users as u', 'u.id', '=', 'm.user_id')->where('m.company_id', $cid)->where('m.purpose', 'rentals_year_'.$year)->where('m.status', 'prepared')
                ->orderBy('m.id')->get(['m.to', 'm.created_at', 'u.name'])->keyBy('to');
            $account = (string) $this->company($request)->name;
            $template = RentalMessages::template($cid, 'year_summary');
            $rows = $landlords->map(function (RentalLandlord $l) use ($cid, $by, $year, $balances, $logins, $portal, $prepared, $account, $template) {
                $link = RentalLinks::yearSummary($l, $year, 'pdf', 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES);
                $text = RentalMessages::forYearSummary($cid, (string) $l->name, $year, $link['url'], $account, $template);
                $phone = trim((string) $l->phone_e164) ?: null;
                $was = $phone === null ? null : $prepared->get($phone);

                return ['id' => (int) $l->id, 'name' => (string) $l->name, 'phone' => $phone, 'balance_minor' => (int) ($balances[$l->id] ?? 0), 'has_login' => $portal && in_array((int) $l->id, $logins, true), 'text' => $text,
                    'whatsapp' => RentalMessages::whatsappLink($phone, $text), 'sms' => RentalMessages::smsLink($phone, $text), 'pdf_url' => $link['url'],
                    'xlsx_url' => RentalLinks::yearSummary($l, $year, 'xlsx', 'staff', (int) $by->id)['url'],
                    'status' => $was === null ? 'Not sent' : 'Link prepared by '.($was->name ?: 'staff').', '.Carbon::parse($was->created_at)->setTimezone(LocalDate::timezone($cid))->format('j M')];
            })->values()->all();
            $first = (int) substr((string) (DB::table('rental_entries')->where('company_id', $cid)->min('occurred_on') ?: $thisYear.'-01-01'), 0, 4);

            return $this->success(['year' => $year, 'years' => range($thisYear, min($first, $thisYear - 1)), 'currency' => $this->present($request)->currency(), 'rows' => $rows]);
        });
    }

    /** POST rentals/year-summaries/{id}/prepared {year, channel: whatsapp|sms}: the link was opened on this person's own phone: written down as prepared, never as sent. */
    public function yearSummaryPrepared(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            [$cid, $by] = $this->agencyDesk($request);
            $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('is_self', false)->find($id) ?? throw $this->notFoundRecord('landlord');
            $year = (string) $request->input('year', '');
            if (preg_match('/^\d{4}$/', $year) !== 1) {
                throw BusinessRuleException::make('invalid_date', 'Choose a year, for example 2026.');
            }
            $link = RentalLinks::yearSummary($l, $year, 'pdf', 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES);
            RentalMessages::recordPrepared($cid, $this->channel($request), $l->phone_e164, RentalMessages::forYearSummary($cid, (string) $l->name, $year, $link['url']), 'rentals_year_'.$year, (int) $by->id);

            return $this->success(['landlord_id' => (int) $l->id, 'year' => $year, 'status' => 'Link prepared by '.((string) $by->name ?: 'staff').', '.LocalDate::today($cid)->format('j M')], 'Written down.');
        });
    }

    /** GET rentals/payouts/{id}/statement-message: "payout saved, send the statement": the month's statement and the advice as week-long links, and the words for this person's own phone. */
    public function payoutStatement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->offer($request, $id)));
    }

    /** POST rentals/payouts/{id}/statement-prepared {channel: whatsapp|sms}: written down as prepared, never as sent. */
    public function payoutStatementPrepared(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $offer = $this->offer($request, $id);
            $channel = $this->channel($request);
            RentalMessages::recordPrepared($this->cid($request), $channel, $offer['phone'], $offer['text'], 'rentals_statement', (int) $this->user($request)->id);

            return $this->success(['payout_id' => $id, 'channel' => $channel], 'Written down.');
        });
    }

    /** @return array<string, mixed> */
    private function offer(Request $request, int $id): array
    {
        [$cid, $by] = $this->agencyDesk($request);
        $p = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->find($id) ?? throw $this->notFoundRecord('payout');
        $l = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($p->landlord_id) ?? throw $this->notFoundRecord('payout');
        $day = Carbon::parse($p->paid_on);
        $pdf = RentalLinks::statementPdf($l, $day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString(), 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES)['url'];
        $text = RentalMessages::forStatement($cid, (string) $l->name, $day->format('F Y'), LandlordAccounts::balance($l), $pdf);
        $phone = trim((string) $l->phone_e164) ?: null;

        return ['payout_id' => (int) $p->id, 'number' => (string) $p->number, 'amount_minor' => (int) $p->amount_minor, 'landlord' => ['id' => (int) $l->id, 'name' => (string) $l->name], 'month' => $day->format('F Y'), 'reversed' => $p->reversed_at !== null,
            'text' => $text, 'pdf' => $pdf, 'advice' => RentalLinks::payoutAdvicePdf($p, 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES)['url'], 'phone' => $phone,
            'whatsapp' => RentalMessages::whatsappLink($phone, $text), 'sms' => RentalMessages::smsLink($phone, $text)];
    }

    /** The agency's own staff for its landlords. @return array{0: int, 1: \App\Models\User} */
    private function agencyDesk(Request $request): array
    {
        $by = $this->user($request);
        RentalAccess::authorize($by, 'rentals_landlords');
        $this->wholeAccount($request);
        $cid = $this->cid($request);
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('agency_only', 'This is for agencies that manage property for landlords.');
        }

        return [$cid, $by];
    }

    private function channel(Request $request): string
    {
        $channel = (string) $request->input('channel', '');
        if (! in_array($channel, ['whatsapp', 'sms'], true)) {
            throw BusinessRuleException::make('via_invalid', 'Say how it went: WhatsApp or SMS.');
        }

        return $channel;
    }

    /** The last twelve months over, newest first (the web's month end). @return array<string, string> Y-m => its name */
    private function holdingMonths(int $cid): array
    {
        $out = [];
        for ($m = LocalDate::today($cid)->startOfMonth()->subMonthNoOverflow(), $i = 0; $i < 12; $m->subMonthNoOverflow(), $i++) {
            $out[$m->format('Y-m')] = $m->format('F Y');
        }

        return $out;
    }
}
