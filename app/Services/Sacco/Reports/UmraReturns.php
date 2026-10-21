<?php

namespace App\Services\Sacco\Reports;

use App\Models\SaccoLoan;
use App\Models\SaccoOfficer;
use App\Models\SaccoSavingsProduct;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\LoanReports;
use App\Services\Sacco\YearEnd\FiscalCalendar;
use App\Support\Sacco\Amount;
use App\Support\Sacco\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The regulator's returns (P4.2): Forms 1 to 7 and the monthly statement of savings of the Tier 4 (SACCO) Regulations
 * 2020, Schedule 4 (02 §1.3), each line in the form's own order with the form's own labels, from the books (Figures,
 * Ratios, the loan book). Every return carries the SACCO's name, CS No., the financial year's start and end, and the
 * authorising and countersigning officers' signature block. Forms 3 and 4 are in thousands on the form; the exact
 * amount is beside it. Any SACCO can generate them; ReturnsCalendar knows when a licensed one owes them.
 *
 * Where the gazetted text leaves a line's content unclear (02 §1.3 "UNVERIFIED"), the choice is written beside it here:
 * Form 2's non-earning assets are cash in hand, prepayments, property, intangibles and other assets; Form 4's
 * matured liabilities are the payables, tax, dividends and other short-term liabilities; Form 4's deductions 2.2–2.3,
 * 3.3–3.5 and 6.4–6.6 have no account in the standard chart and are nil.
 */
final class UmraReturns
{
    /** report key => [form, title, frequency] */
    public const FORMS = [
        'umra_f1' => ['F1', 'Form 1: Risk classification of assets and provisioning', 'quarterly'],
        'umra_f2' => ['F2', 'Form 2: Investment return', 'quarterly'],
        'umra_f3' => ['F3', 'Form 3: Capital adequacy', 'monthly'],
        'umra_f4' => ['F4', 'Form 4: Liquidity statement', 'monthly'],
        'umra_f5' => ['F5', 'Form 5: Statement of financial position', 'quarterly'],
        'umra_f6' => ['F6', 'Form 6: Statement of comprehensive income', 'quarterly'],
        'umra_f7' => ['F7', 'Form 7: Other disclosures', 'quarterly'],
        'umra_savings_statement' => ['SAV', 'Statement of savings (deposit return)', 'monthly'],
    ];

    /** Officers who may authorise a return, then countersign it, in order of preference. */
    public const AUTHORISE = ['chairperson', 'manager', 'vice_chair'];

    public const COUNTERSIGN = ['treasurer', 'secretary', 'board_member'];

    /** Positions that are staff (employees) rather than elected officers (directors), for the insider lines. */
    public const STAFF_POSITIONS = ['manager', 'teller'];

    public static function register(): void
    {
        foreach (self::FORMS as $key => [, $title]) {
            SaccoReports::register($key, $title, 'sacco_reports', fn (int $cid, array $p) => (new self())->build($cid, $key, $p));
        }
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public function build(int $cid, string $key, array $p): array
    {
        [$form, $title, $frequency] = self::FORMS[$key];
        [$from, $to] = self::period($cid, $frequency, (string) $p['to']);
        $rows = match ($form) {
            'F1' => $this->f1($cid, $to),
            'F2' => $this->f2($cid, $to),
            'F3' => $this->f3($cid, $to),
            'F4' => $this->f4($cid, $to),
            'F5' => $this->f5($cid, $to),
            'F6' => $this->f6($cid, $from, $to),
            'F7' => $this->f7($cid, $to),
            default => $this->savings($cid, $to),
        };
        $thousands = in_array($form, ['F3', 'F4'], true);
        $currency = (string) LoanBooks::settings($cid)->currency;
        if ($thousands) {
            foreach ($rows as &$r) {
                $r['thousands'] = isset($r['amount_minor']) ? self::thousands((int) $r['amount_minor'], $currency) : null;
            }
            unset($r);
        }
        $columns = match ($form) {
            'SAV' => [self::c('line', 'Line'), self::c('item', 'Product'), self::c('kind', 'Kind'), self::c('accounts', 'Accounts', 'number'), self::c('amount_minor', 'Balance', 'money')],
            'F5' => [self::c('line', 'Line'), self::c('item', 'Item'), self::c('amount_minor', 'Current period', 'money'), self::c('prior_minor', 'Prior period', 'money')],
            'F1' => [self::c('line', 'Line'), self::c('item', 'Item'), self::c('amount_minor', 'Amount outstanding', 'money'), self::c('rate_bp', 'Provision rate', 'bp'),
                self::c('provision_minor', 'Required provision', 'money')],
            default => array_merge([self::c('line', 'Line'), self::c('item', 'Item'), self::c('amount_minor', 'Amount', 'money')],
                $thousands ? [self::c('thousands', "'000", 'number')] : [], [self::c('ratio_bp', 'Ratio', 'bp')]),
        };
        [$fyFrom, $fyTo] = FiscalCalendar::bounds($cid, FiscalCalendar::yearOf($cid, $to));
        $s = LoanBooks::settings($cid);

        return ['columns' => $columns, 'rows' => $rows, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sections' => [self::details($cid, $form, $from, $to, $fyFrom, $fyTo), self::signatures($cid, LoanBooks::today($cid))],
            'meta' => ['form' => $form, 'frequency' => $frequency, 'regulator' => $s->regulator()['short'] ?? null, 'unit' => $thousands ? "{$currency} '000" : $currency,
                'lines' => self::lineValues($rows), 'financial_year' => ['start' => $fyFrom->toDateString(), 'end' => $fyTo->toDateString()]]];
    }

    /**
     * The period a return covers for a day: its month, its quarter of the financial year, or the financial year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(int $cid, string $frequency, string $to): array
    {
        $day = Carbon::parse($to)->startOfDay();

        return match ($frequency) {
            'monthly' => [$day->copy()->startOfMonth(), $day->copy()->endOfMonth()->startOfDay()],
            'quarterly' => self::quarter($cid, $day),
            default => FiscalCalendar::bounds($cid, FiscalCalendar::yearOf($cid, $day)),
        };
    }

    /** @return array{0: Carbon, 1: Carbon} the quarter of the financial year a day falls in */
    public static function quarter(int $cid, Carbon $day): array
    {
        $start = FiscalCalendar::startOf($cid, $day);
        $months = ($day->year - $start->year) * 12 + $day->month - $start->month;
        $from = $start->copy()->addMonthsNoOverflow(intdiv($months, 3) * 3);

        return [$from, $from->copy()->addMonthsNoOverflow(2)->endOfMonth()->startOfDay()];
    }

    // ---------------------------------------------------------------- the forms

    /** @return list<array<string, mixed>> */
    private function f1(int $cid, Carbon $to): array
    {
        $currency = (string) LoanBooks::settings($cid)->currency;
        $book = LoanReports::book($cid, $to->toDateString());
        $labels = ['performing' => 'Performing', 'watch' => 'Watch', 'substandard' => 'Substandard', 'doubtful' => 'Doubtful', 'loss' => 'Loss'];
        $rows = [];
        $grand = [0, 0];
        foreach (['A' => ['Normal loans', false], 'B' => ['Rescheduled loans', true]] as $block => [$title, $rescheduled]) {
            $rows[] = ['line' => $block, 'item' => $title];
            $sub = [0, 0];
            $n = 1;
            foreach ((array) config('sacco.provisioning') as [$class, , , $bp]) {
                $in = array_filter($book, fn ($b) => $b['class'] === $class && ((int) $b['loan']->reschedule_count > 0) === $rescheduled);
                $amount = (int) array_sum(array_column($in, 'principal'));
                $need = 0;
                foreach ($in as $b) {
                    $need += Amount::of((int) $b['principal'], $currency)->times(bcdiv((string) $bp, '10000', 4))->minor;
                }
                $rows[] = ['line' => $block.'.'.$n++, 'item' => $labels[$class] ?? ucfirst((string) $class), 'amount_minor' => $amount, 'rate_bp' => (int) $bp, 'provision_minor' => $need];
                $sub = [$sub[0] + $amount, $sub[1] + $need];
            }
            $rows[] = ['line' => $block.'.'.$n, 'item' => 'Subtotal: '.strtolower($title), 'amount_minor' => $sub[0], 'provision_minor' => $sub[1]];
            $grand = [$grand[0] + $sub[0], $grand[1] + $sub[1]];
        }
        $rows[] = ['line' => 'C', 'item' => 'Grand total', 'amount_minor' => $grand[0], 'provision_minor' => $grand[1]];

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function f2(int $cid, Carbon $to): array
    {
        $f = Ratios::figures($cid, Figures::position($cid, $to));
        $land = Figures::bp($f['land_and_buildings'], $f['total_assets']);
        $inv = Figures::bp($f['non_gov_investments'], $f['core_capital']);
        $allowed = intdiv(max(0, $f['core_capital']) * 4000 + 5000, 10000);

        return [
            ['line' => '1.1', 'item' => 'Core capital', 'amount_minor' => $f['core_capital']],
            ['line' => '1.2', 'item' => 'Total assets', 'amount_minor' => $f['total_assets']],
            ['line' => '1.3', 'item' => 'Total deposits', 'amount_minor' => $f['deposits']],
            ['line' => '1.4', 'item' => 'Non-earning assets', 'amount_minor' => $f['non_earning']],
            ['line' => '1.5', 'item' => 'Financial assets (non-government investments)', 'amount_minor' => $f['non_gov_investments']],
            ['line' => '1.6', 'item' => 'Land and buildings', 'amount_minor' => $f['land_and_buildings']],
            ['line' => '2.1', 'item' => 'Land and buildings to total assets (1.6 / 1.2)', 'ratio_bp' => $land],
            ['line' => '2.2', 'item' => 'Maximum', 'ratio_bp' => 1000],
            ['line' => '2.3', 'item' => 'Excess or (deficiency)', 'ratio_bp' => $land === null ? null : 1000 - $land],
            ['line' => '3.0', 'item' => 'Financial investment to core capital (1.5 / 1.1)', 'ratio_bp' => $inv],
            ['line' => '3.1', 'item' => 'Maximum', 'ratio_bp' => 4000],
            ['line' => '3.2', 'item' => 'Excess or (deficiency)', 'amount_minor' => $allowed - $f['non_gov_investments']],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function f3(int $cid, Carbon $to): array
    {
        $position = Figures::position($cid, $to);
        $l = $position['lines'];
        $k = Figures::capital($position);
        $assets = $position['totals']['assets'];
        $cash = $l['cash_hand'] + $l['cash_bank'];
        $loans = $l['loans_gross'] + $l['loan_allowance'];
        $inv = $l['other_securities'] + $l['other_investments'];
        $other = $l['prepayments'] + $l['receivables'] + $l['intangibles'] + $l['other_assets'];
        $onBs = $cash + $l['gov_securities'] + $l['other_fi'] + $loans + $inv + $l['ppe'] + $other;
        $deposits = $l['savings'] + $l['fixed_deposits'] + $l['non_withdrawable'] + $l['interest_payable'];

        return [
            ['line' => '1.1', 'item' => 'Core capital'],
            ['line' => '1.1.1', 'item' => 'Paid-up share capital', 'amount_minor' => $k['share_capital']],
            ['line' => '1.1.2', 'item' => 'Statutory reserves', 'amount_minor' => $k['statutory_reserve']],
            ['line' => '1.1.3', 'item' => 'Retained earnings / (accumulated losses)', 'amount_minor' => $k['retained']],
            ['line' => '1.1.4', 'item' => 'Current year net surplus after tax (50 %)', 'amount_minor' => $k['current_half']],
            ['line' => '1.1.5', 'item' => 'Capital grants', 'amount_minor' => $k['grants']],
            ['line' => '1.1.6', 'item' => 'General reserves', 'amount_minor' => $k['general_reserves']],
            ['line' => '1.1.7', 'item' => 'Other reserves', 'amount_minor' => $k['other_reserves']],
            ['line' => '1.1.8', 'item' => 'Subtotal', 'amount_minor' => $k['subtotal']],
            ['line' => '1.1.9', 'item' => 'Less: investments in subsidiaries and equity of other institutions', 'amount_minor' => 0],
            ['line' => '1.1.10', 'item' => 'Less: other deductions', 'amount_minor' => 0],
            ['line' => '1.1.11', 'item' => 'Total deductions', 'amount_minor' => $k['deductions']],
            ['line' => '1.1.12', 'item' => 'Core capital', 'amount_minor' => $k['core']],
            ['line' => '1.1.13', 'item' => 'Institutional capital (core capital less share capital)', 'amount_minor' => $k['institutional']],
            ['line' => '2', 'item' => 'On-balance-sheet assets'],
            ['line' => '2.1', 'item' => 'Cash', 'amount_minor' => $cash],
            ['line' => '2.2', 'item' => 'Government securities', 'amount_minor' => $l['gov_securities']],
            ['line' => '2.3', 'item' => 'Deposits and balances at other institutions', 'amount_minor' => $l['other_fi']],
            ['line' => '2.4', 'item' => 'Loans and advances (net)', 'amount_minor' => $loans],
            ['line' => '2.5', 'item' => 'Investments', 'amount_minor' => $inv],
            ['line' => '2.6', 'item' => 'Property and equipment (net)', 'amount_minor' => $l['ppe']],
            ['line' => '2.7', 'item' => 'Other assets', 'amount_minor' => $other],
            ['line' => '2.8', 'item' => 'Total on-balance-sheet assets', 'amount_minor' => $onBs],
            ['line' => '2.9', 'item' => 'Difference from the statement of financial position', 'amount_minor' => $assets - $onBs],
            ['line' => '3', 'item' => 'Off-balance-sheet items', 'amount_minor' => 0],
            ['line' => '4.1', 'item' => 'Total on-balance-sheet assets', 'amount_minor' => $onBs],
            ['line' => '4.2', 'item' => 'Total off-balance-sheet items', 'amount_minor' => 0],
            ['line' => '4.3', 'item' => 'Total assets', 'amount_minor' => $onBs],
            ['line' => '4.4', 'item' => 'Minimum core capital (10 % of total assets)', 'amount_minor' => intdiv(max(0, $onBs) * 1000 + 5000, 10000)],
            ['line' => '4.5', 'item' => 'Total deposit liabilities', 'amount_minor' => $deposits],
            ['line' => '4.6', 'item' => 'Core capital to total assets', 'ratio_bp' => Figures::bp($k['core'], $onBs)],
            ['line' => '4.7', 'item' => 'Minimum', 'ratio_bp' => 1000],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function f4(int $cid, Carbon $to): array
    {
        $position = Figures::position($cid, $to);
        $l = $position['lines'];
        $f = Ratios::figures($cid, $position);
        $deposits = $l['savings'] + $l['fixed_deposits'] + $l['non_withdrawable'] + $l['interest_payable'];
        $matured = $f['short_term_liabilities'];
        $denominator = $deposits + $matured;
        $required = intdiv(max(0, $denominator) * 1500 + 5000, 10000);

        return [
            ['line' => '1.1', 'item' => 'Local notes and coins', 'amount_minor' => $l['cash_hand']],
            ['line' => '1.2', 'item' => 'Foreign notes and coins', 'amount_minor' => 0],
            ['line' => '2.1', 'item' => 'Balances with banks (and mobile-money float)', 'amount_minor' => $l['cash_bank']],
            ['line' => '2.2', 'item' => 'Less: time deposits over 90 days', 'amount_minor' => 0],
            ['line' => '2.3', 'item' => 'Less: overdrafts and matured bank loans', 'amount_minor' => 0],
            ['line' => '3.1', 'item' => 'Balances with other SACCOs', 'amount_minor' => $l['other_fi']],
            ['line' => '3.2', 'item' => 'Balances with other financial institutions', 'amount_minor' => 0],
            ['line' => '3.3', 'item' => 'Less: due to other societies', 'amount_minor' => 0],
            ['line' => '3.4', 'item' => 'Less: due to financial institutions', 'amount_minor' => 0],
            ['line' => '3.5', 'item' => 'Less: matured loans', 'amount_minor' => 0],
            ['line' => '4.1', 'item' => 'Treasury bills', 'amount_minor' => $f['tbills']],
            ['line' => '4.2', 'item' => 'Treasury bonds', 'amount_minor' => $f['tbonds']],
            ['line' => '5', 'item' => 'Net liquid assets', 'amount_minor' => $f['net_liquid_assets']],
            ['line' => '6.1', 'item' => 'Deposits from members, including interest', 'amount_minor' => $deposits],
            ['line' => '6.2', 'item' => 'Deposits from other sources', 'amount_minor' => 0],
            ['line' => '6.3', 'item' => 'Total deposits', 'amount_minor' => $deposits],
            ['line' => '6.4', 'item' => 'Less: deductions', 'amount_minor' => 0],
            ['line' => '6.8', 'item' => 'Net deposit liabilities', 'amount_minor' => $deposits],
            ['line' => '7.1', 'item' => 'Other liabilities: matured', 'amount_minor' => $matured],
            ['line' => '7.2', 'item' => 'Other liabilities: maturing within 91 days', 'amount_minor' => 0],
            ['line' => '7.3', 'item' => 'Total other liabilities', 'amount_minor' => $matured],
            ['line' => '8.3', 'item' => 'Liquidity ratio (5 / (6.3 + 7.3))', 'ratio_bp' => Figures::bp($f['net_liquid_assets'], $denominator)],
            ['line' => '8.4', 'item' => 'Minimum', 'ratio_bp' => 1500],
            ['line' => '8.5', 'item' => 'Excess or (deficit)', 'amount_minor' => $f['net_liquid_assets'] - $required],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function f5(int $cid, Carbon $to): array
    {
        $now = Figures::position($cid, $to);
        $priorDay = FiscalCalendar::startOf($cid, $to)->subDay();
        $prior = Figures::position($cid, $priorDay);
        $rows = [];
        $titles = ['assets' => ['A', 'Assets', 'Total assets'], 'liabilities' => ['B', 'Liabilities', 'Total liabilities'], 'equity' => ['C', 'Equity', 'Total equity']];
        $group = null;
        foreach (UmraLines::POSITION as $key => [$g, $label, $line]) {
            if ($g !== $group) {
                if ($group !== null) {
                    $rows[] = ['line' => $titles[$group][0], 'item' => $titles[$group][2], 'amount_minor' => $now['totals'][$group], 'prior_minor' => $prior['totals'][$group]];
                }
                $rows[] = ['line' => '', 'item' => $titles[$g][1]];
                $group = $g;
            }
            $rows[] = ['line' => $line, 'item' => $label, 'amount_minor' => $now['lines'][$key], 'prior_minor' => $prior['lines'][$key]];
        }
        $rows[] = ['line' => 'C', 'item' => 'Total equity', 'amount_minor' => $now['totals']['equity'], 'prior_minor' => $prior['totals']['equity']];
        $rows[] = ['line' => 'D', 'item' => 'Total liabilities and equity', 'amount_minor' => $now['totals']['liabilities_and_equity'], 'prior_minor' => $prior['totals']['liabilities_and_equity']];

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function f6(int $cid, Carbon $from, Carbon $to): array
    {
        $l = Figures::income($cid, $from, $to)['lines'];
        $income = $l['int_portfolio'] + $l['fees_portfolio'] + $l['inv_income'] + $l['other_op_income'];
        $financial = $l['int_funding'] + $l['int_deposits'] + $l['int_borrowing'] + $l['dividend_exp'];
        $net = $income - $financial;
        $operating = $l['personnel'] + $l['governance'] + $l['marketing'] + $l['depreciation'] + $l['admin'];
        $netOperating = $net - $l['provision'] + $l['recoveries'] - $operating;
        $beforeTax = $netOperating + $l['other_income'];
        $afterTax = $beforeTax - $l['tax'];
        $row = fn (string $line, string $item, int $v) => ['line' => $line, 'item' => $item, 'amount_minor' => $v];

        return [
            $row('2.1', 'Interest on the loan portfolio', $l['int_portfolio']),
            $row('2.2', 'Fees and commission on the loan portfolio', $l['fees_portfolio']),
            $row('3.1', 'Income from government securities, bank deposits and other investments', $l['inv_income']),
            $row('3.4', 'Other operating income', $l['other_op_income']),
            $row('3', 'Total financial and operating income', $income),
            $row('4.1', 'Expense on funding liabilities', $l['int_funding']),
            $row('4.2', 'Interest on deposits', $l['int_deposits']),
            $row('4.3', 'Cost of external borrowing', $l['int_borrowing']),
            $row('4.4', 'Dividend expense', $l['dividend_exp']),
            $row('4', 'Financial expense', $financial),
            $row('5', 'Net financial income', $net),
            $row('6.1', 'Provision for loan losses', $l['provision']),
            $row('6.2', 'Value of loans recovered', $l['recoveries']),
            $row('7.1', 'Personnel', $l['personnel']),
            $row('7.2', 'Governance', $l['governance']),
            $row('7.3', 'Marketing', $l['marketing']),
            $row('7.4', 'Depreciation and amortisation', $l['depreciation']),
            $row('7.5', 'Administration', $l['admin']),
            $row('7', 'Operating expenses', $operating),
            $row('8', 'Net operating income', $netOperating),
            $row('9', 'Non-operating income and expenses', $l['other_income']),
            $row('10', 'Net income before tax and donations', $beforeTax),
            $row('11', 'Tax', $l['tax']),
            $row('12', 'Net income after tax, before donations', $afterTax),
            $row('13.1', 'Donations for loan capital', $l['donations_capital']),
            $row('13.2', 'Donations for operations', $l['donations_ops']),
            $row('14', 'Net income', $afterTax + $l['donations_capital'] + $l['donations_ops']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function f7(int $cid, Carbon $to): array
    {
        $position = Figures::position($cid, $to);
        $f = Ratios::figures($cid, $position);
        $book = LoanReports::book($cid, $to->toDateString());
        $npl = array_filter($book, fn ($b) => in_array($b['class'], ['substandard', 'doubtful', 'loss'], true));
        $gross = (int) array_sum(array_column($npl, 'principal'));
        $suspense = (int) array_sum(array_map(fn ($b) => (int) $b['loan']->interest_in_suspense_minor + (int) $b['loan']->penalty_in_suspense_minor, $npl));
        $allowance = -$position['lines']['loan_allowance'];
        $insiders = self::insiders($cid, $to, $book);
        $directors = (int) array_sum(array_map(fn ($i) => $i['category'] === 'director' ? $i['principal_minor'] : 0, $insiders));
        $employees = (int) array_sum(array_map(fn ($i) => $i['category'] === 'employee' ? $i['principal_minor'] : 0, $insiders));
        $liquidity = Figures::bp($f['net_liquid_assets'], $f['deposits'] + $f['short_term_liabilities']);

        return [
            ['line' => '1.1', 'item' => 'Gross non-performing loans', 'amount_minor' => $gross],
            ['line' => '1.2', 'item' => 'Interest in suspense', 'amount_minor' => $suspense],
            ['line' => '1.3', 'item' => 'Total non-performing loans and interest in suspense', 'amount_minor' => $gross + $suspense],
            ['line' => '1.4', 'item' => 'Allowance for loan losses', 'amount_minor' => $allowance],
            ['line' => '1.5', 'item' => 'Net non-performing loans', 'amount_minor' => $gross + $suspense - $allowance],
            ['line' => '2.1', 'item' => 'Insider loans to directors (committee members)', 'amount_minor' => $directors],
            ['line' => '2.2', 'item' => 'Insider loans to employees', 'amount_minor' => $employees],
            ['line' => '2.3', 'item' => 'Total insider loans', 'amount_minor' => $directors + $employees],
            ['line' => '3', 'item' => 'Guarantees and commitments', 'amount_minor' => 0],
            ['line' => '4.1', 'item' => 'Core capital', 'amount_minor' => $f['core_capital']],
            ['line' => '4.2', 'item' => 'Institutional capital', 'amount_minor' => $f['institutional_capital']],
            ['line' => '4.3', 'item' => 'Total assets', 'amount_minor' => $f['total_assets']],
            ['line' => '4.4', 'item' => 'Total deposits', 'amount_minor' => $f['deposits']],
            ['line' => '4.5', 'item' => 'Core capital to total assets', 'ratio_bp' => Figures::bp($f['core_capital'], $f['total_assets'])],
            ['line' => '4.6', 'item' => 'Minimum', 'ratio_bp' => 1000],
            ['line' => '4.7', 'item' => 'Institutional capital to total assets', 'ratio_bp' => Figures::bp($f['institutional_capital'], $f['total_assets'])],
            ['line' => '4.8', 'item' => 'Minimum', 'ratio_bp' => 800],
            ['line' => '4.10', 'item' => 'Core capital to deposits', 'ratio_bp' => Figures::bp($f['core_capital'], $f['deposits'])],
            ['line' => '4.11', 'item' => 'Minimum', 'ratio_bp' => 800],
            ['line' => '5.1', 'item' => 'Liquidity ratio', 'ratio_bp' => $liquidity],
            ['line' => '5.2', 'item' => 'Minimum', 'ratio_bp' => 1500],
            ['line' => '6.1', 'item' => 'Land and buildings to total assets', 'ratio_bp' => Figures::bp($f['land_and_buildings'], $f['total_assets'])],
            ['line' => '6.2', 'item' => 'Maximum (as printed on the form)', 'ratio_bp' => 500],
            ['line' => '6.3', 'item' => 'Financial investments to total assets', 'ratio_bp' => Figures::bp($f['financial_investments'], $f['total_assets'])],
        ];
    }

    /** The monthly statement of savings: each product's balance at the month's end, withdrawable and not. @return list<array<string, mixed>> */
    private function savings(int $cid, Carbon $to): array
    {
        $balances = DB::table('sacco_journal_lines as l')->join('sacco_savings_accounts as a', 'a.id', '=', 'l.sub_id')->where('l.company_id', $cid)->where('l.sub_type', 'savings')
            ->where('l.entry_date', '<=', $to->toDateString())->groupBy('a.product_id')
            ->selectRaw('a.product_id, SUM(l.credit_minor) - SUM(l.debit_minor) AS b, COUNT(DISTINCT a.id) AS n')->get()->keyBy('product_id');
        $rows = [];
        $sums = ['w' => 0, 'n' => 0];
        $i = 1;
        foreach (SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->orderBy('withdrawable', 'desc')->orderBy('id')->get() as $p) {
            $b = (int) ($balances[$p->id]->b ?? 0);
            $rows[] = ['line' => (string) $i++, 'item' => (string) $p->name, 'kind' => $p->withdrawable ? 'Withdrawable' : 'Non-withdrawable',
                'accounts' => (int) ($balances[$p->id]->n ?? 0), 'amount_minor' => $b];
            $sums[$p->withdrawable ? 'w' : 'n'] += $b;
        }
        $payable = (int) (Figures::position($cid, $to)['lines']['interest_payable']);
        $rows[] = ['line' => 'A', 'item' => 'Total withdrawable savings', 'amount_minor' => $sums['w']];
        $rows[] = ['line' => 'B', 'item' => 'Total non-withdrawable savings', 'amount_minor' => $sums['n']];
        $rows[] = ['line' => 'C', 'item' => 'Interest payable on savings', 'amount_minor' => $payable];
        $rows[] = ['line' => 'D', 'item' => 'Total savings and deposits', 'amount_minor' => $sums['w'] + $sums['n'] + $payable];

        return $rows;
    }

    // ---------------------------------------------------------------- shared

    /**
     * Loans open on a day to members who hold an office then (or were insiders when they applied), with their category.
     *
     * @param  list<array<string, mixed>>|null  $book
     * @return list<array{loan_id: int, loan_no: string, member_id: int, positions: list<string>, category: string, principal_minor: int, class: string}>
     */
    public static function insiders(int $cid, Carbon $on, ?array $book = null): array
    {
        $book ??= LoanReports::book($cid, $on->toDateString());
        $positions = SaccoOfficer::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('member_id')->where('starts_on', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on->toDateString()))->get(['member_id', 'position'])->groupBy('member_id');
        $out = [];
        foreach ($book as $b) {
            /** @var SaccoLoan $loan */
            $loan = $b['loan'];
            $held = ($positions[$loan->member_id] ?? collect())->pluck('position')->unique()->values()->all();
            if ($held === [] && ! $loan->is_insider) {
                continue;
            }
            $category = $held !== [] && array_diff($held, self::STAFF_POSITIONS) === [] ? 'employee' : 'director';
            $out[] = ['loan_id' => (int) $loan->id, 'loan_no' => (string) $loan->loan_no, 'member_id' => (int) $loan->member_id, 'positions' => $held, 'category' => $category,
                'principal_minor' => (int) $b['principal'], 'class' => (string) $b['class']];
        }

        return $out;
    }

    /** @return array<string, mixed> the details block every return carries */
    public static function details(int $cid, string $form, Carbon $from, Carbon $to, Carbon $fyFrom, Carbon $fyTo): array
    {
        $s = LoanBooks::settings($cid);

        return ['title' => 'Return details', 'columns' => [self::c('field', 'Field'), self::c('value', 'Value')], 'rows' => [
            ['field' => 'Name of SACCO', 'value' => (string) $s->legal_name],
            ['field' => 'CS No.', 'value' => (string) ($s->registration_no ?? '')],
            ['field' => 'Licence No.', 'value' => (string) ($s->licence_no ?? '')],
            ['field' => 'Financial year start', 'value' => $fyFrom->toDateString()],
            ['field' => 'Financial year end', 'value' => $fyTo->toDateString()],
            ['field' => 'Period', 'value' => $from->toDateString().' to '.$to->toDateString()],
            ['field' => 'Form', 'value' => $form],
        ]];
    }

    /** @return array<string, mixed> the authorising and countersigning officers: who holds those offices on the day the return is signed */
    public static function signatures(int $cid, Carbon $on): array
    {
        $officers = SaccoOfficer::withoutGlobalScopes()->where('company_id', $cid)->where('starts_on', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on->toDateString()))->orderBy('id')->get();
        $pick = function (array $positions) use ($officers): ?SaccoOfficer {
            foreach ($positions as $p) {
                if (($o = $officers->firstWhere('position', $p)) !== null) {
                    return $o;
                }
            }

            return null;
        };
        $rows = [];
        foreach (['Authorised by' => self::AUTHORISE, 'Countersigned by' => self::COUNTERSIGN] as $role => $positions) {
            $o = $pick($positions);
            $rows[] = ['role' => $role, 'name' => $o === null ? '' : self::officerName($o), 'position' => $o === null ? '' : $o->label(), 'signature' => '', 'date' => ''];
        }

        return ['title' => 'Signatures', 'columns' => [self::c('role', ''), self::c('name', 'Name'), self::c('position', 'Office'), self::c('signature', 'Signature'),
            self::c('date', 'Date')], 'rows' => $rows];
    }

    public static function officerName(SaccoOfficer $o): string
    {
        return (string) ($o->user_id ? DB::table('admin_users')->where('id', $o->user_id)->value('name') : null)
            ?: ($o->member_id ? (string) DB::table('sacco_members')->where('id', $o->member_id)->selectRaw("TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS n")->value('n') : '');
    }

    /** A form amount in thousands of the major unit, rounded half-up (Forms 3 and 4). */
    public static function thousands(int $minor, string $currency): int
    {
        $div = (string) (1000 * 10 ** Currency::exponent($currency));
        $neg = $minor < 0;
        $x = (int) bcadd(bcdiv((string) abs($minor), $div, 6), '0.5', 0);

        return $neg ? -$x : $x;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int|null> line => amount (or ratio) for tests and the filing */
    private static function lineValues(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (($r['line'] ?? '') === '' || (! array_key_exists('amount_minor', $r) && ! array_key_exists('ratio_bp', $r))) {
                continue;
            }
            $out[(string) $r['line']] = $r['amount_minor'] ?? $r['ratio_bp'] ?? null;
        }

        return $out;
    }

    /** @return array{key: string, label: string, type: string} */
    public static function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
