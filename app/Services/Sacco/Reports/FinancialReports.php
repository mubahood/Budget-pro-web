<?php

namespace App\Services\Sacco\Reports;

use App\Models\SaccoAppropriation;
use App\Models\SaccoDividendLine;
use App\Models\SaccoDividendRun;
use App\Models\SaccoFiscalYear;
use App\Models\SaccoMember;
use App\Models\SaccoOfficer;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\LoanReports;
use App\Services\Sacco\YearEnd\Dormancy;
use App\Services\Sacco\YearEnd\FiscalCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The P4 reports (04 §12), registered into SaccoReports, computed from the ledger and the sub-ledgers as of the
 * report's `to` day (a period report: between `from` and `to`). A year report (the appropriation, the dividend, the AGM
 * pack, the Registrar's annual return) is for the last financial year that ended on or before `to`.
 *
 * | Key | What |
 * |---|---|
 * | balance_sheet | statement of financial position in Form 5's order, this period and the year before; ties to the trial balance |
 * | income_statement | statement of comprehensive income in Form 6's order, the period and the same period a year earlier |
 * | ratios | every prudential ratio against its limit, the weekly liquidity and the central bank threshold |
 * | membership_stats | members by gender, age, kind and status; joined and left in the period |
 * | insider_loans | open loans to officers and staff, by category |
 * | dormant_register | dormant accounts, their stage and what is due |
 * | registrar_annual_return | the Registrar's annual return fields |
 * | s55as_quarterly | the quarterly report to the Registrar: portfolio, self-sufficiency, risk, sustainability |
 * | appropriation, dividend_run | the year's waterfall and dividend, with any warnings |
 * | agm_pack | everything the AGM receives in one pack |
 * | journal_listing | every journal line in the period (the auditor's journal) |
 */
final class FinancialReports
{
    public static function register(): void
    {
        $r = new self();
        $add = fn (string $key, string $title, string $method) => SaccoReports::register($key, $title, 'sacco_reports', fn (int $cid, array $p) => $r->{$method}($cid, $p));
        $add('balance_sheet', 'Statement of financial position', 'balanceSheet');
        $add('income_statement', 'Statement of comprehensive income', 'incomeStatement');
        $add('ratios', 'Prudential ratios', 'ratios');
        $add('membership_stats', 'Membership statistics', 'membershipStats');
        $add('insider_loans', 'Insider loans', 'insiderLoans');
        $add('dormant_register', 'Register of dormant accounts', 'dormantRegister');
        $add('registrar_annual_return', 'Annual return to the Registrar', 'registrarAnnualReturn');
        $add('s55as_quarterly', 'Quarterly report to the Registrar', 's55asQuarterly');
        $add('appropriation', 'Appropriation of the surplus', 'appropriation');
        $add('dividend_run', 'Dividend on shares', 'dividendRun');
        $add('agm_pack', 'AGM pack', 'agmPack');
        $add('journal_listing', 'Journal listing', 'journalListing');
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function balanceSheet(int $cid, array $p): array
    {
        $to = Carbon::parse($p['to']);
        $prior = FiscalCalendar::startOf($cid, $to)->subDay();
        $now = Figures::position($cid, $to);
        $before = Figures::position($cid, $prior);
        $rows = [];
        $group = null;
        $titles = ['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'];
        foreach (UmraLines::POSITION as $key => [$g, $label, $line]) {
            if ($g !== $group) {
                if ($group !== null) {
                    $rows[] = ['line' => '', 'item' => 'Total '.strtolower($titles[$group]), 'current_minor' => $now['totals'][$group], 'prior_minor' => $before['totals'][$group]];
                }
                $rows[] = ['line' => '', 'item' => $titles[$g]];
                $group = $g;
            }
            if ($now['lines'][$key] !== 0 || $before['lines'][$key] !== 0) {
                $rows[] = ['line' => $line, 'item' => $label, 'current_minor' => $now['lines'][$key], 'prior_minor' => $before['lines'][$key]];
            }
        }
        $rows[] = ['line' => '', 'item' => 'Total equity', 'current_minor' => $now['totals']['equity'], 'prior_minor' => $before['totals']['equity']];
        $rows[] = ['line' => '', 'item' => 'Total liabilities and equity', 'current_minor' => $now['totals']['liabilities_and_equity'], 'prior_minor' => $before['totals']['liabilities_and_equity']];
        $tb = (new \App\Services\Sacco\Ledger\TrialBalance())->between($cid, null, $to);

        return ['columns' => [self::c('line', 'Line'), self::c('item', 'Item'), self::c('current_minor', $to->format('j M Y'), 'money'), self::c('prior_minor', $prior->format('j M Y'), 'money')],
            'rows' => $rows, 'from' => $prior->toDateString(), 'to' => $to->toDateString(),
            'meta' => ['lines' => $now['lines'], 'totals' => $now['totals'], 'prior_totals' => $before['totals'], 'balanced' => $now['totals']['assets'] === $now['totals']['liabilities_and_equity'],
                'trial_balance_balanced' => $tb['balanced'], 'note' => 'Computed from the ledger; it ties to the trial balance of the same day.']];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function incomeStatement(int $cid, array $p): array
    {
        $from = Carbon::parse($p['from']);
        $to = Carbon::parse($p['to']);
        $now = Figures::income($cid, $from, $to);
        $before = Figures::income($cid, $from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow());
        $rows = [];
        foreach (['income' => 'Income', 'expense' => 'Expenses'] as $g => $title) {
            $rows[] = ['line' => '', 'item' => $title];
            foreach (UmraLines::INCOME as $key => [$kg, $label, $line]) {
                if ($kg === $g && ($now['lines'][$key] !== 0 || $before['lines'][$key] !== 0)) {
                    $rows[] = ['line' => $line, 'item' => $label, 'current_minor' => $now['lines'][$key], 'prior_minor' => $before['lines'][$key]];
                }
            }
            $t = $g === 'income' ? 'income' : 'expenses';
            $rows[] = ['line' => '', 'item' => 'Total '.strtolower($title), 'current_minor' => $now['totals'][$t], 'prior_minor' => $before['totals'][$t]];
        }
        $rows[] = ['line' => '14', 'item' => 'Net surplus / (deficit)', 'current_minor' => $now['totals']['net_surplus'], 'prior_minor' => $before['totals']['net_surplus']];

        return ['columns' => [self::c('line', 'Line'), self::c('item', 'Item'), self::c('current_minor', 'This period', 'money'), self::c('prior_minor', 'A year earlier', 'money')],
            'rows' => $rows, 'meta' => ['lines' => $now['lines'], 'totals' => $now['totals'], 'prior_totals' => $before['totals']]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function ratios(int $cid, array $p): array
    {
        $r = (new Ratios())->compute($cid, (string) $p['to']);
        $rows = array_map(fn ($x) => ['ratio' => $x['label'], 'value_bp' => $x['value_bp'], 'limit' => ($x['direction'] === 'min' ? 'at least ' : 'at most ').SaccoReportFormat::bp($x['limit_bp']),
            'status' => $x['value_bp'] === null ? 'not measurable' : ($x['ok'] ? 'within' : ($x['direction'] === 'min' ? 'below' : 'above'))], $r['ratios']);
        $weekly = (new Ratios())->weekly($cid, (string) $p['to']);
        $sections = [['title' => 'Liquidity on the last working day of each week', 'columns' => [self::c('on', 'Day', 'date'), self::c('value_bp', 'Liquidity', 'bp'), self::c('status', 'Status')],
            'rows' => array_map(fn ($w) => ['on' => $w['on'], 'value_bp' => $w['value_bp'], 'status' => $w['ok'] ? 'within' : 'below'], $weekly)]];
        if ($r['bou']['applies']) {
            $sections[] = ['title' => 'Central bank threshold', 'columns' => [self::c('measure', 'Measure'), self::c('value_minor', 'Now', 'money'), self::c('threshold_minor', 'Threshold', 'money'),
                self::c('status', 'Status')], 'rows' => [
                    ['measure' => 'Voluntary savings', 'value_minor' => $r['bou']['voluntary_savings_minor'], 'threshold_minor' => $r['bou']['savings_threshold_minor'], 'status' => $r['bou']['savings']],
                    ['measure' => 'Institutional capital', 'value_minor' => $r['bou']['institutional_capital_minor'], 'threshold_minor' => $r['bou']['capital_threshold_minor'], 'status' => $r['bou']['capital']],
                ]];
        }

        return ['columns' => [self::c('ratio', 'Ratio'), self::c('value_bp', 'Value', 'bp'), self::c('limit', 'Limit'), self::c('status', 'Status')], 'rows' => $rows,
            'sections' => $sections, 'meta' => ['ratios' => $r['ratios'], 'bou' => $r['bou'], 'liquidity_on' => $r['liquidity_on'], 'weekly' => $weekly,
                'note' => $r['bou']['warning'] ?? null]];
    }

    /**
     * Members on a day by gender, age band, kind and status, and who joined and left in the period.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function membershipStats(int $cid, array $p): array
    {
        $s = self::memberStats($cid, Carbon::parse($p['from']), Carbon::parse($p['to']));
        $rows = [];
        foreach ($s as $group => $counts) {
            foreach ($counts as $label => $n) {
                $rows[] = ['group' => $group, 'measure' => $label, 'count' => $n];
            }
        }

        return ['columns' => [self::c('group', 'Group'), self::c('measure', 'Measure'), self::c('count', 'Members', 'number')], 'rows' => $rows, 'meta' => ['stats' => $s]];
    }

    /** @return array<string, array<string, int>> */
    public static function memberStats(int $cid, Carbon $from, Carbon $to): array
    {
        $left = ['exited', 'deceased', 'rejected'];
        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->get(['id', 'gender', 'date_of_birth', 'status', 'kind', 'joined_on', 'status_changed_on']);
        $on = $members->filter(fn ($m) => $m->status !== 'applicant' && $m->joined_on !== null && $m->joined_on->lte($to)
            && ! (in_array($m->status, $left, true) && ($m->status_changed_on === null || $m->status_changed_on->lte($to))));
        $stats = ['Total' => ['Members' => $on->count()], 'Gender' => ['Female' => 0, 'Male' => 0, 'Other' => 0, 'Not given' => 0],
            'Age' => ['Under 18' => 0, 'Youth (18 to 30)' => 0, '31 to 59' => 0, '60 and over' => 0, 'Not given' => 0],
            'Kind' => ['Individuals' => 0, 'Groups' => 0, 'Institutions' => 0], 'Status' => ['Active' => 0, 'Dormant' => 0, 'Suspended' => 0],
            'In the period' => ['Joined' => 0, 'Left' => 0]];
        foreach ($on as $m) {
            $stats['Gender'][match ($m->gender) {
                'female' => 'Female', 'male' => 'Male', 'other' => 'Other', default => 'Not given'
            }]++;
            $age = $m->date_of_birth === null ? null : (int) $m->date_of_birth->diffInYears($to);
            $stats['Age'][match (true) {
                $age === null => 'Not given', $age < 18 => 'Under 18', $age <= 30 => 'Youth (18 to 30)', $age < 60 => '31 to 59', default => '60 and over'
            }]++;
            $stats['Kind'][match ($m->kind) {
                'group' => 'Groups', 'institution' => 'Institutions', default => 'Individuals'
            }]++;
            $status = $m->status === 'dormant' ? 'Dormant' : ($m->status === 'suspended' ? 'Suspended' : 'Active');
            $stats['Status'][$status]++;
        }
        $stats['In the period']['Joined'] = $members->filter(fn ($m) => $m->status !== 'applicant' && $m->status !== 'rejected' && $m->joined_on !== null && $m->joined_on->between($from, $to))->count();
        $stats['In the period']['Left'] = $members->filter(fn ($m) => in_array($m->status, ['exited', 'deceased'], true) && $m->status_changed_on !== null && $m->status_changed_on->between($from, $to))->count();

        return $stats;
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function insiderLoans(int $cid, array $p): array
    {
        $to = Carbon::parse($p['to']);
        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->get(['id', 'member_no', 'first_name', 'last_name'])->keyBy('id');
        $labels = SaccoOfficer::LABELS;
        $rows = array_map(fn ($i) => ['loan_no' => $i['loan_no'], 'member_no' => (string) ($members[$i['member_id']]->member_no ?? ''),
            'name' => trim(($members[$i['member_id']]->first_name ?? '').' '.($members[$i['member_id']]->last_name ?? '')),
            'offices' => implode(', ', array_map(fn ($x) => $labels[$x] ?? $x, $i['positions'])) ?: 'Insider at application', 'category' => $i['category'] === 'employee' ? 'Employee' : 'Director',
            'principal_minor' => $i['principal_minor'], 'class' => $i['class']], UmraReturns::insiders($cid, $to));
        $by = ['Director' => 0, 'Employee' => 0];
        foreach ($rows as $r) {
            $by[$r['category']] += $r['principal_minor'];
        }

        return ['columns' => [self::c('loan_no', 'Loan'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('offices', 'Office'), self::c('category', 'Category'),
            self::c('principal_minor', 'Principal', 'money'), self::c('class', 'Class')], 'rows' => $rows,
            'totals' => ['principal_minor' => array_sum(array_column($rows, 'principal_minor'))], 'meta' => ['by_category' => $by]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function dormantRegister(int $cid, array $p): array
    {
        $rows = array_map(fn ($r) => ['account_no' => $r['account_no'], 'member_no' => $r['member_no'], 'name' => $r['member_name'], 'balance_minor' => $r['balance_minor'],
            'last_activity_on' => $r['last_activity_on'], 'months_quiet' => $r['months_quiet'], 'stage' => $r['stage'], 'advertised_on' => $r['advertised_on'],
            'due' => $r['due'] === null ? '' : ($r['due'] === 'transfer' ? 'Transfer to the regulator' : 'Advertise'), 'warning' => $r['warning'] ?? ''],
            (new Dormancy())->register($cid, (string) $p['to']));

        return ['columns' => [self::c('account_no', 'Account'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('balance_minor', 'Balance', 'money'),
            self::c('last_activity_on', 'Last activity', 'date'), self::c('months_quiet', 'Months quiet', 'number'), self::c('stage', 'Stage'), self::c('advertised_on', 'Advertised', 'date'),
            self::c('due', 'Due'), self::c('warning', 'Note')], 'rows' => $rows, 'totals' => ['balance_minor' => array_sum(array_column($rows, 'balance_minor'))]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function registrarAnnualReturn(int $cid, array $p): array
    {
        $year = self::lastYear($cid, (string) $p['to']);
        [$from, $to] = FiscalCalendar::bounds($cid, $year);
        $s = LoanBooks::settings($cid);
        $company = \App\Models\Company::withoutGlobalScopes()->find($cid);
        $position = Figures::position($cid, $to);
        $stats = self::memberStats($cid, $from, $to);
        $par = max(1, (int) $s->share_par_minor);
        $shares = intdiv(max(0, $position['lines']['share_capital']), $par);
        $agm = SaccoAppropriation::withoutGlobalScopes()->from('sacco_appropriations as a')->join('sacco_fiscal_years as y', 'y.id', '=', 'a.fiscal_year_id')
            ->where('a.company_id', $cid)->where('y.year', $year)->whereIn('a.status', ['posted', 'requested'])->orderByDesc('a.id')->value('a.agm_date');
        $fields = [
            ['field' => 'Name of the society', 'value' => (string) $s->legal_name],
            ['field' => 'Registration number', 'value' => (string) ($s->registration_no ?? '')],
            ['field' => 'Address', 'value' => (string) ($company?->address ?? '')],
            ['field' => 'Type of society', 'value' => 'Savings and credit'],
            ['field' => 'Liability', 'value' => 'Limited by shares'],
            ['field' => 'Number of members', 'value' => (string) $stats['Total']['Members']],
            ['field' => 'Number of shares issued', 'value' => (string) $shares],
            ['field' => 'Par value of one share', 'value' => SaccoReportFormat::money($par, (string) $s->currency)],
            ['field' => 'Total indebtedness', 'value' => SaccoReportFormat::money($position['totals']['liabilities'], (string) $s->currency)],
            ['field' => 'Date of the annual general meeting', 'value' => $agm === null ? '' : Carbon::parse($agm)->toDateString()],
            ['field' => 'Maximum liability set by the general meeting', 'value' => ''],
            ['field' => 'Financial year', 'value' => $from->toDateString().' to '.$to->toDateString()],
        ];
        $officials = [];
        foreach (SaccoOfficer::withoutGlobalScopes()->where('company_id', $cid)->where('starts_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $to->toDateString()))
            ->whereIn('position', ['chairperson', 'vice_chair', 'secretary', 'treasurer', 'board_member', 'supervisory', 'loans_committee', 'manager'])->orderBy('position')->get() as $o) {
            $officials[] = ['office' => $o->label(), 'name' => UmraReturns::officerName($o)];
        }
        $bs = $this->balanceSheet($cid, ['to' => $to->toDateString()]);

        return ['columns' => [self::c('field', 'Field'), self::c('value', 'Value')], 'rows' => $fields, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sections' => [['title' => 'Officials', 'columns' => [self::c('office', 'Office'), self::c('name', 'Name')], 'rows' => $officials],
                ['title' => 'Statement of financial position at '.$to->format('j M Y'), 'columns' => $bs['columns'], 'rows' => $bs['rows']],
                UmraReturns::signatures($cid, LoanBooks::today($cid))],
            'meta' => ['year' => $year, 'members' => $stats['Total']['Members'], 'shares' => $shares, 'indebtedness_minor' => $position['totals']['liabilities']]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function s55asQuarterly(int $cid, array $p): array
    {
        [$from, $to] = UmraReturns::quarter($cid, Carbon::parse($p['to']));
        $book = LoanReports::book($cid, $to->toDateString());
        $gross = (int) array_sum(array_column($book, 'principal'));
        $par30 = (int) array_sum(array_map(fn ($b) => $b['dpd'] > 30 ? $b['principal'] : 0, $book));
        $npl = (int) array_sum(array_map(fn ($b) => in_array($b['class'], ['substandard', 'doubtful', 'loss'], true) ? $b['principal'] : 0, $book));
        $disbursed = DB::table('sacco_loans')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween('disbursed_on', [$from->toDateString(), $to->toDateString()]);
        $income = Figures::income($cid, $from, $to)['lines'];
        $operatingIncome = $income['int_portfolio'] + $income['fees_portfolio'] + $income['inv_income'] + $income['other_op_income'];
        $costs = $income['int_funding'] + $income['int_deposits'] + $income['int_borrowing'] + $income['dividend_exp'] + $income['provision']
            + $income['personnel'] + $income['governance'] + $income['marketing'] + $income['depreciation'] + $income['admin'];
        $position = Figures::position($cid, $to);
        $f = Ratios::figures($cid, $position);
        $allowance = -$position['lines']['loan_allowance'];
        $net = Figures::income($cid, $from, $to)['totals']['net_surplus'];
        $row = fn (string $area, string $measure, ?int $minor = null, ?int $bp = null, ?int $n = null) => ['area' => $area, 'measure' => $measure, 'amount_minor' => $minor, 'ratio_bp' => $bp, 'count' => $n];
        $rows = [
            $row('Portfolio', 'Gross loan portfolio', $gross, null, count($book)),
            $row('Portfolio', 'Loans paid out in the quarter', (int) (clone $disbursed)->sum('approved_minor'), null, (int) (clone $disbursed)->count()),
            $row('Operational self-sufficiency', 'Operating income', $operatingIncome),
            $row('Operational self-sufficiency', 'Financial, provision and operating costs', $costs),
            $row('Operational self-sufficiency', 'Operational self-sufficiency (income / costs)', null, Figures::bp($operatingIncome, $costs)),
            $row('Risk analysis', 'Portfolio at risk over 30 days', $par30, Figures::bp($par30, $gross)),
            $row('Risk analysis', 'Non-performing loans', $npl, Figures::bp($npl, $gross)),
            $row('Risk analysis', 'Allowance for loan losses to non-performing loans', $allowance, Figures::bp($allowance, $npl)),
            $row('Sustainability', 'Core capital to total assets', $f['core_capital'], Figures::bp($f['core_capital'], $f['total_assets'])),
            $row('Sustainability', 'Liquidity', $f['net_liquid_assets'], Figures::bp($f['net_liquid_assets'], $f['deposits'] + $f['short_term_liabilities'])),
            $row('Sustainability', 'Net surplus for the quarter', $net, Figures::bp($net, $f['total_assets'])),
        ];

        return ['columns' => [self::c('area', 'Area'), self::c('measure', 'Measure'), self::c('count', 'Number', 'number'), self::c('amount_minor', 'Amount', 'money'), self::c('ratio_bp', 'Ratio', 'bp')],
            'rows' => $rows, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sections' => [UmraReturns::details($cid, 'S55AS', $from, $to, ...FiscalCalendar::bounds($cid, FiscalCalendar::yearOf($cid, $to))), UmraReturns::signatures($cid, LoanBooks::today($cid))]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function appropriation(int $cid, array $p): array
    {
        $year = self::lastYear($cid, (string) $p['to']);
        $fy = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $cid)->where('year', $year)->first();
        $a = $fy === null ? null : SaccoAppropriation::withoutGlobalScopes()->where('company_id', $cid)->where('fiscal_year_id', $fy->id)->whereIn('status', ['posted', 'requested'])
            ->orderByRaw("status = 'posted' DESC")->orderByDesc('id')->first();
        [$from, $to] = FiscalCalendar::bounds($cid, $year);
        $rows = [['item' => 'Net surplus for the year', 'bp' => null, 'amount_minor' => (int) ($fy->net_surplus_minor ?? 0)]];
        foreach ((array) ($a->lines ?? []) as $l) {
            $rows[] = ['item' => $l['label'], 'bp' => $l['bp'] ?? null, 'amount_minor' => (int) $l['amount_minor']];
        }

        return ['columns' => [self::c('item', 'Item'), self::c('bp', 'Share of the surplus', 'bp'), self::c('amount_minor', 'Amount', 'money')], 'rows' => $rows,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sections' => self::warnings((array) ($a->warnings ?? [])),
            'meta' => ['year' => $year, 'status' => $a?->status ?? ($fy?->status === 'closed' ? 'not asked' : 'year not closed'), 'agm_date' => $a?->agm_date?->toDateString(),
                'dividend_rate_bp' => $a?->dividend_rate_bp, 'warnings' => (array) ($a->warnings ?? []),
                'note' => $a === null ? null : ($a->status === 'posted' ? 'Posted '.$a->posted_on?->toDateString().'.' : 'Waiting for approval.')]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function dividendRun(int $cid, array $p): array
    {
        $year = self::lastYear($cid, (string) $p['to']);
        [$from, $to] = FiscalCalendar::bounds($cid, $year);
        $run = SaccoDividendRun::withoutGlobalScopes()->from('sacco_dividend_runs as r')->join('sacco_fiscal_years as y', 'y.id', '=', 'r.fiscal_year_id')->where('r.company_id', $cid)
            ->where('y.year', $year)->whereIn('r.status', ['paid', 'requested'])->select('r.*')->orderByDesc('r.id')->first();
        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->get(['id', 'member_no', 'first_name', 'last_name'])->keyBy('id');
        $rows = [];
        if ($run !== null) {
            foreach (SaccoDividendLine::withoutGlobalScopes()->where('run_id', $run->id)->orderBy('member_id')->get() as $l) {
                $m = $members[$l->member_id] ?? null;
                $rows[] = ['member_no' => (string) $m?->member_no, 'name' => trim(($m->first_name ?? '').' '.($m->last_name ?? '')), 'basis_minor' => (int) $l->basis_minor,
                    'amount_minor' => (int) $l->amount_minor, 'paid_to' => $l->paid_to, 'status' => $l->status];
            }
        }

        return ['columns' => [self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('basis_minor', $run?->basis === 'weighted_monthly' ? 'Share-months' : 'Shares held', 'money'),
            self::c('amount_minor', 'Dividend', 'money'), self::c('paid_to', 'Paid to'), self::c('status', 'Status')], 'rows' => $rows,
            'totals' => ['amount_minor' => array_sum(array_column($rows, 'amount_minor'))], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sections' => self::warnings((array) ($run->warnings ?? [])),
            'meta' => ['year' => $year, 'status' => $run?->status ?? 'none', 'rate_bp' => $run?->rate_bp, 'pool_minor' => $run?->pool_minor,
                'registrar_consent_on' => $run?->registrar_consent_on?->toDateString(), 'warnings' => (array) ($run->warnings ?? [])]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function agmPack(int $cid, array $p): array
    {
        $year = self::lastYear($cid, (string) $p['to']);
        [$from, $to] = FiscalCalendar::bounds($cid, $year);
        $range = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $income = $this->incomeStatement($cid, $range);
        $position = $this->balanceSheet($cid, ['to' => $to->toDateString()]);
        $approp = $this->appropriation($cid, ['to' => $to->toDateString()]);
        $dividend = $this->dividendRun($cid, ['to' => $to->toDateString()]);
        $members = $this->membershipStats($cid, $range);
        $aging = LoanReports::aging($cid, ['to' => $to->toDateString()]);
        $savings = (new UmraReturns())->build($cid, 'umra_savings_statement', ['to' => $to->toDateString()]);
        $agenda = ['Confirm the minutes of the last general meeting', 'Receive the committee\'s report and the supervisory board\'s report', 'Receive and adopt the audited accounts',
            'Decide how the surplus is shared (the appropriation below)', 'Set the maximum borrowing for the coming year', 'Elect the committee', 'Appoint the auditor', 'Any other business'];
        $sections = [
            ['title' => 'Statement of comprehensive income', 'columns' => $income['columns'], 'rows' => $income['rows']],
            ['title' => 'Statement of financial position at '.$to->format('j M Y'), 'columns' => $position['columns'], 'rows' => $position['rows']],
            ['title' => 'Proposed appropriation of the surplus ('.$approp['meta']['status'].')', 'columns' => $approp['columns'], 'rows' => $approp['rows']],
            ...$approp['sections'],
            ['title' => 'Dividend on shares ('.$dividend['meta']['status'].')', 'columns' => $dividend['columns'], 'rows' => $dividend['rows'], 'totals' => $dividend['totals']],
            ['title' => 'Membership', 'columns' => $members['columns'], 'rows' => $members['rows']],
            ['title' => 'Loan portfolio at the year\'s end', 'columns' => $aging['columns'], 'rows' => $aging['rows'], 'totals' => $aging['totals']],
            ['title' => 'Savings at the year\'s end', 'columns' => $savings['columns'], 'rows' => $savings['rows']],
            ['title' => 'Borrowing limit for the coming year (set by the meeting)', 'columns' => [self::c('item', 'Item'), self::c('value', 'Amount')],
                'rows' => [['item' => 'Maximum the SACCO may borrow', 'value' => '']]],
        ];

        return ['columns' => [self::c('n', 'No.', 'number'), self::c('item', 'Agenda')], 'rows' => array_map(fn ($a, $i) => ['n' => $i + 1, 'item' => $a], $agenda, array_keys($agenda)),
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'sections' => $sections, 'meta' => ['year' => $year]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function journalListing(int $cid, array $p): array
    {
        $rows = DB::table('sacco_journal_lines as l')->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('sacco_ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('sacco_members as m', 'm.id', '=', 'l.member_id')->where('l.company_id', $cid)->whereBetween('l.entry_date', [$p['from'], $p['to']])
            ->orderBy('e.id')->orderBy('l.line_no')
            ->get(['e.entry_no', 'e.entry_date', 'e.value_date', 'e.kind', 'a.code', 'a.name', 'l.debit_minor', 'l.credit_minor', 'm.member_no', 'l.memo', 'e.memo as entry_memo'])
            ->map(fn ($r) => ['entry_no' => $r->entry_no, 'entry_date' => $r->entry_date, 'value_date' => $r->value_date, 'kind' => $r->kind, 'code' => $r->code, 'account' => $r->name,
                'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'member_no' => (string) $r->member_no, 'memo' => (string) ($r->memo ?: $r->entry_memo)])->all();

        return ['columns' => [self::c('entry_no', 'Entry'), self::c('entry_date', 'Posted', 'date'), self::c('value_date', 'Value date', 'date'), self::c('kind', 'Kind'), self::c('code', 'Code'),
            self::c('account', 'Account'), self::c('debit_minor', 'Debit', 'money'), self::c('credit_minor', 'Credit', 'money'), self::c('member_no', 'Member'), self::c('memo', 'Memo')],
            'rows' => $rows, 'totals' => ['debit_minor' => array_sum(array_column($rows, 'debit_minor')), 'credit_minor' => array_sum(array_column($rows, 'credit_minor'))]];
    }

    /** The last financial year that ended on or before a day. */
    public static function lastYear(int $cid, string $day): int
    {
        $year = FiscalCalendar::yearOf($cid, $day);

        return FiscalCalendar::bounds($cid, $year)[1]->lte(Carbon::parse($day)) ? $year : $year - 1;
    }

    /** @param list<array{code?: string, message?: string}> $warnings @return list<array<string, mixed>> */
    private static function warnings(array $warnings): array
    {
        return $warnings === [] ? [] : [['title' => 'Warnings', 'columns' => [self::c('message', 'Warning')],
            'rows' => array_map(fn ($w) => ['message' => (string) ($w['message'] ?? $w['code'] ?? '')], $warnings)]];
    }

    /** @return array{key: string, label: string, type: string} */
    private static function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
