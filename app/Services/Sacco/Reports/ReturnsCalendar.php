<?php

namespace App\Services\Sacco\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoPeriod;
use App\Models\SaccoReturnFiling;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\YearEnd\FiscalCalendar;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\WorkingDays;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The returns calendar (P4.5, 02 §5): what the SACCO owes and when, kept in sacco_return_filings.
 *
 * - A licensed SACCO owes the regulator Forms 3 and 4 and the statement of savings every month, and Forms 1, 2, 5, 6
 *   and 7 every quarter, each by the 15th of the following month (SI 51 reg. 28–53).
 * - A registered society owes the Registrar the quarterly report (by the 15th after the quarter, Coop s.55AS) and the
 *   annual return within four months of the year's end (Regs 1992).
 * - A due date that is not a working day moves to the next working day. Reminders go to the officers on the 10th and
 *   the 14th of the month a return is due (moved back to the working day before when either is not one), through the
 *   SACCO's in-app notices, once a day each.
 * - generate() builds the return from the books and keeps its figures and its PDF, XLSX and CSV; markFiled() records
 *   the day it was sent and its reference. A filed return keeps its figures.
 *
 * Any SACCO can generate any return (SaccoReports); the calendar lists only what this SACCO owes.
 */
final class ReturnsCalendar
{
    /** form => [report key, title, frequency, regulator (umra | registrar), due] */
    public const FORMS = [
        'F3' => ['umra_f3', 'Form 3: Capital adequacy', 'monthly', 'umra', 15],
        'F4' => ['umra_f4', 'Form 4: Liquidity statement', 'monthly', 'umra', 15],
        'SAV' => ['umra_savings_statement', 'Statement of savings', 'monthly', 'umra', 15],
        'F1' => ['umra_f1', 'Form 1: Risk classification and provisioning', 'quarterly', 'umra', 15],
        'F2' => ['umra_f2', 'Form 2: Investment return', 'quarterly', 'umra', 15],
        'F5' => ['umra_f5', 'Form 5: Statement of financial position', 'quarterly', 'umra', 15],
        'F6' => ['umra_f6', 'Form 6: Statement of comprehensive income', 'quarterly', 'umra', 15],
        'F7' => ['umra_f7', 'Form 7: Other disclosures', 'quarterly', 'umra', 15],
        'S55AS' => ['s55as_quarterly', 'Quarterly report to the Registrar', 'quarterly', 'registrar', 15],
        'REG_ANNUAL' => ['registrar_annual_return', 'Annual return to the Registrar', 'annual', 'registrar', 'month4'],
    ];

    public const REMINDER_DAYS = [10, 14];

    /** The day a period's return is due: the 15th of the next month (or the 4th month's end), moved to a working day. */
    public static function dueOn(string $form, CarbonInterface $periodEnd): Carbon
    {
        $rule = self::FORMS[$form][4];
        $day = $rule === 'month4' ? Carbon::parse($periodEnd->toDateString())->addMonthsNoOverflow(4)->endOfMonth()->startOfDay()
            : Carbon::parse($periodEnd->toDateString())->addDay()->startOfMonth()->day((int) $rule)->startOfDay();
        while (! WorkingDays::isWorkingDay($day)) {
            $day->addDay();
        }

        return $day;
    }

    /** The reminder days for a due date: the 10th and the 14th of its month, each moved back to a working day. @return list<string> */
    public static function reminderDays(CarbonInterface $due): array
    {
        $out = [];
        foreach (self::REMINDER_DAYS as $d) {
            $day = Carbon::parse($due->toDateString())->startOfMonth()->day($d)->startOfDay();
            while (! WorkingDays::isWorkingDay($day)) {
                $day->subDay();
            }
            $out[] = $day->toDateString();
        }

        return array_values(array_unique($out));
    }

    /** Which forms this SACCO owes. @return list<string> */
    public static function owed(int $companyId): array
    {
        $s = LoanBooks::settings($companyId);
        $out = [];
        foreach (self::FORMS as $form => [, , , $who]) {
            if (($who === 'umra' && SaccoRules::strict($companyId)) || ($who === 'registrar' && filled($s->registration_no))) {
                $out[] = $form;
            }
        }

        return $out;
    }

    /** Make the rows of every return owed for each period ended since the books began. @return int made */
    public function ensure(int $companyId, ?CarbonInterface $today = null): int
    {
        $today = $today !== null ? Carbon::parse($today->toDateString()) : LoanBooks::today($companyId);
        $first = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->min('starts_on');
        if ($first === null) {
            return 0;
        }
        $n = 0;
        foreach (self::owed($companyId) as $form) {
            foreach ($this->periods($companyId, $form, Carbon::parse($first), $today) as [$key, $from, $to]) {
                $made = SaccoReturnFiling::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'form' => $form, 'period_key' => $key],
                    ['period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'due_on' => self::dueOn($form, $to)->toDateString(), 'status' => 'due']);
                $n += $made->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * The calendar: every return owed, oldest first, with how late it is.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $companyId, ?string $status = null): array
    {
        $this->ensure($companyId);
        $today = LoanBooks::today($companyId);

        return SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $companyId)->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderBy('due_on')->orderBy('form')->get()->map(fn (SaccoReturnFiling $f) => self::present($f, $today))->all();
    }

    /** @return array<string, mixed> */
    public static function present(SaccoReturnFiling $f, CarbonInterface $today): array
    {
        $late = $f->status !== 'filed' && $f->due_on->lt($today) ? WorkingDays::between($f->due_on, $today) : 0;

        return ['id' => (int) $f->id, 'form' => $f->form, 'title' => self::FORMS[$f->form][1] ?? $f->form, 'report' => self::FORMS[$f->form][0] ?? null,
            'regulator' => self::FORMS[$f->form][3] ?? null, 'period_key' => $f->period_key, 'period_from' => $f->period_from->toDateString(), 'period_to' => $f->period_to->toDateString(),
            'due_on' => $f->due_on->toDateString(), 'status' => $f->status, 'working_days_late' => $late, 'generated_at' => $f->generated_at?->toIso8601String(),
            'files' => array_keys((array) $f->file_ids), 'filed_on' => $f->filed_on?->toDateString(), 'filed_reference' => $f->filed_reference];
    }

    /** Build the return from the books and keep its figures and files (sacco_reports). */
    public function generate(Company $company, int $filingId, User $by): SaccoReturnFiling
    {
        if (! SaccoAccess::can($by, 'sacco_reports') && ! SaccoAccess::can($by, 'sacco_accounts')) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this.', ['permission' => 'sacco_reports']);
        }
        $cid = (int) $company->id;

        return DB::transaction(function () use ($company, $cid, $filingId, $by) {
            $f = SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($filingId)
                ?? throw BusinessRuleException::make('not_found', 'That return is not in this SACCO.');
            if ($f->status === 'filed') {
                throw BusinessRuleException::make('return_filed', 'This return is filed: its figures stay as they were sent.');
            }
            if ($f->period_to->gte(LoanBooks::today($cid))) {
                throw BusinessRuleException::make('period_not_ended', 'The return\'s period has not ended yet.');
            }
            $report = (new SaccoReports())->build($company, self::FORMS[$f->form][0], ['from' => $f->period_from->toDateString(), 'to' => $f->period_to->toDateString()], $by);
            $files = [];
            foreach (['pdf', 'xlsx', 'csv'] as $format) {
                [$bytes, $mime, $name] = SaccoReportFormat::render($report, $format);
                $path = 'sacco/returns/'.$cid.'/'.$f->form.'-'.$f->period_key.'-'.Str::random(8).'.'.$format;
                Storage::disk('local')->put($path, $bytes);
                $files[$format] = (int) StoredFile::create(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_return', 'path' => $path, 'mime' => mb_substr($mime, 0, 60), // the column is short; the download sets the full type
                    'size_bytes' => strlen($bytes), 'uploaded_by_id' => $by->id])->id;
            }
            $f->fill(['status' => 'generated', 'figures' => ['rows' => $report['rows'], 'lines' => $report['meta']['lines'] ?? null], 'generated_at' => now(),
                'generated_by_id' => $by->id, 'file_ids' => $files])->save();
            SaccoAudit::record('sacco.return.generated', $f, $cid, ['form' => $f->form, 'period' => $f->period_key], (int) $by->id);

            return $f->fresh();
        });
    }

    /**
     * Record that a generated return was sent (sacco_accounts).
     *
     * @param  array<string, mixed>  $data  filed_on, reference, authorised_by_id, countersigned_by_id
     */
    public function markFiled(Company $company, int $filingId, array $data, User $by): SaccoReturnFiling
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
        $cid = (int) $company->id;
        $on = Carbon::parse((string) ($data['filed_on'] ?? LoanBooks::today($cid)->toDateString()))->startOfDay();
        if ($on->gt(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'A return cannot be filed in the future.');
        }
        foreach (['authorised_by_id', 'countersigned_by_id'] as $k) {
            if (! empty($data[$k]) && ! OfficerService::active($cid)->contains(fn ($o) => (int) $o->user_id === (int) $data[$k])) {
                throw BusinessRuleException::make('invalid_signatory', 'Who signs a return holds an office in this SACCO.');
            }
        }

        return DB::transaction(function () use ($cid, $filingId, $data, $on, $by) {
            $f = SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($filingId)
                ?? throw BusinessRuleException::make('not_found', 'That return is not in this SACCO.');
            if ($f->status === 'filed') {
                throw BusinessRuleException::make('return_filed', 'This return is already filed.');
            }
            if ($f->status !== 'generated') {
                throw BusinessRuleException::make('return_not_generated', 'Generate the return before you mark it filed.');
            }
            $f->fill(['status' => 'filed', 'filed_on' => $on->toDateString(), 'filed_reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
                'filed_by_id' => $by->id, 'authorised_by_id' => $data['authorised_by_id'] ?? null, 'countersigned_by_id' => $data['countersigned_by_id'] ?? null])->save();
            SaccoAudit::record('sacco.return.filed', $f, $cid, ['form' => $f->form, 'period' => $f->period_key, 'reference' => $f->filed_reference], (int) $by->id);

            return $f->fresh();
        });
    }

    /** The officers hear of returns due this month on the reminder days, once a day each. @return int reminders sent */
    public function remind(int $companyId, ?CarbonInterface $today = null): int
    {
        $today = $today !== null ? Carbon::parse($today->toDateString()) : LoanBooks::today($companyId);
        $this->ensure($companyId, $today);
        $due = SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'filed')
            ->whereBetween('due_on', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])->orderBy('due_on')->get()
            ->filter(fn (SaccoReturnFiling $f) => in_array($today->toDateString(), self::reminderDays($f->due_on), true) && ! in_array($today->toDateString(), (array) $f->reminded, true));
        if ($due->isEmpty()) {
            return 0;
        }
        $ids = OfficerService::active($companyId)->pluck('user_id')->filter()->push(Company::withoutGlobalScopes()->whereKey($companyId)->value('owner_id'))->filter()->unique()->all();
        $users = User::withoutGlobalScopes()->whereIn('id', $ids)->get()->all();
        $lines = $due->map(fn ($f) => (self::FORMS[$f->form][1] ?? $f->form).' ('.$f->period_key.'), due '.$f->due_on->format('j M'))->implode("\n");
        (new Notifier())->notify($companyId, 'sacco_returns', 'Returns due this month', $lines, ['filing_ids' => $due->pluck('id')->values()->all()], $users);
        foreach ($due as $f) {
            $f->fill(['reminded' => array_values(array_unique(array_merge((array) $f->reminded, [$today->toDateString()])))])->save();
        }

        return $due->count();
    }

    /**
     * The periods of a form that have ended between the books' first month and today.
     *
     * @return list<array{0: string, 1: Carbon, 2: Carbon}> [period key, from, to]
     */
    private function periods(int $cid, string $form, Carbon $first, Carbon $today): array
    {
        $frequency = self::FORMS[$form][2];
        $out = [];
        $cursor = $first->copy()->startOfMonth();
        while (true) {
            [$from, $to] = \App\Services\Sacco\Reports\UmraReturns::period($cid, $frequency, $cursor->toDateString());
            if ($to->gte($today)) {
                break;
            }
            $key = match ($frequency) {
                'monthly' => $from->format('Y-m'),
                'quarterly' => FiscalCalendar::yearOf($cid, $from).'-Q'.(intdiv((($from->year - FiscalCalendar::startOf($cid, $from)->year) * 12 + $from->month - FiscalCalendar::startOf($cid, $from)->month), 3) + 1),
                default => (string) FiscalCalendar::yearOf($cid, $from),
            };
            $out[] = [$key, $from, $to];
            $cursor = $to->copy()->addDay();
        }

        return $out;
    }
}
