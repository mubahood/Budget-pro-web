<?php

namespace App\Services\Sacco\Reports;

use App\Models\SaccoLedgerAccount;

/**
 * Where each account lands on the statements (P4.1, 04 §12): by its `umra_line`, the Schedule 4 line the chart gives
 * it (database/data/sacco_chart.php; a SACCO may change it on an account). The statement of financial position follows
 * Form 5's order and the income statement Form 6's (02 §1.3); the other forms are built from these lines.
 *
 * Older charts used a bare `F5` on a few accounts (intangibles, other assets, payables, tax, dividends, deferred grants,
 * revaluation, proposed dividends): those are placed by their standard code. An account with no known line goes to
 * its type's "other" line, so nothing ever drops off a statement.
 */
final class UmraLines
{
    /** Form 5, in order: key => [group, label, form line]. */
    public const POSITION = [
        'cash_hand' => ['assets', 'Cash in hand', '1.1'],
        'cash_bank' => ['assets', 'Cash at bank and mobile money', '1.2'],
        'other_fi' => ['assets', 'Balances with other SACCOs and financial institutions', '1.3'],
        'prepayments' => ['assets', 'Prepayments', '2'],
        'gov_securities' => ['assets', 'Government securities', '3.1'],
        'other_securities' => ['assets', 'Other securities', '3.2'],
        'other_investments' => ['assets', 'Other investments', '3.3'],
        'loans_gross' => ['assets', 'Gross loan portfolio', '4.1'],
        'loan_allowance' => ['assets', 'Allowance for loan losses', '4.2'],
        'receivables' => ['assets', 'Receivables (interest, penalties, fees, check-off, other)', '5'],
        'ppe' => ['assets', 'Property and equipment (net)', '6'],
        'intangibles' => ['assets', 'Intangible assets', '7'],
        'other_assets' => ['assets', 'Other assets', '8'],
        'savings' => ['liabilities', 'Savings deposits', '9.1'],
        'fixed_deposits' => ['liabilities', 'Short-term (fixed) deposits', '9.2'],
        'non_withdrawable' => ['liabilities', 'Non-withdrawable deposits', '9.3'],
        'interest_payable' => ['liabilities', 'Interest payable on savings', '9.4'],
        'payables' => ['liabilities', 'Accounts payable and accruals', '10'],
        'tax_payable' => ['liabilities', 'Tax payable', '11'],
        'dividends_payable' => ['liabilities', 'Dividends payable', '12'],
        'deferred_grants' => ['liabilities', 'Deferred grants', '13'],
        'external_borrowings' => ['liabilities', 'External borrowings', '14'],
        'other_liabilities' => ['liabilities', 'Other liabilities (suspense, payouts in transit, funds, education fund)', '15'],
        'share_capital' => ['equity', 'Share capital', '16.1'],
        'grants' => ['equity', 'Grants and donations', '16.2'],
        'retained_prior' => ['equity', 'Retained earnings: prior years', '16.3'],
        'current_surplus' => ['equity', 'Retained earnings: current year', '16.4'],
        'statutory_reserve' => ['equity', 'Statutory reserves', '16.5'],
        'general_reserves' => ['equity', 'Other reserves', '16.6'],
        'other_reserves' => ['equity', 'Share transfer fund', '16.7'],
        'revaluation' => ['equity', 'Revaluation reserve', '16.8'],
        'proposed_dividends' => ['equity', 'Proposed dividends', '16.9'],
        'other_equity' => ['equity', 'Adjustments (opening balance equity)', '16.10'],
    ];

    /** Form 6, in order: key => [group (income | expense), label, form line]. */
    public const INCOME = [
        'int_portfolio' => ['income', 'Interest and penalties on the loan portfolio', '2.1'],
        'fees_portfolio' => ['income', 'Fees and commission on the loan portfolio', '2.2'],
        'inv_income' => ['income', 'Income from government securities, bank deposits and investments', '3'],
        'other_op_income' => ['income', 'Other operating income (membership fees, fines, other fees)', '3.4'],
        'int_funding' => ['expense', 'Expense on funding liabilities', '4.1'],
        'int_deposits' => ['expense', 'Interest on deposits', '4.2'],
        'int_borrowing' => ['expense', 'Cost of external borrowing', '4.3'],
        'dividend_exp' => ['expense', 'Dividend expense', '4.4'],
        'provision' => ['expense', 'Provision for loan losses', '6.1'],
        'recoveries' => ['income', 'Value of loans recovered', '6.2'],
        'personnel' => ['expense', 'Personnel', '7.1'],
        'governance' => ['expense', 'Governance', '7.2'],
        'marketing' => ['expense', 'Marketing', '7.3'],
        'depreciation' => ['expense', 'Depreciation and amortisation', '7.4'],
        'admin' => ['expense', 'Administration', '7.5'],
        'other_income' => ['income', 'Non-operating income', '9'],
        'tax' => ['expense', 'Tax', '11'],
        'donations_capital' => ['income', 'Donations for loan capital', '13.1'],
        'donations_ops' => ['income', 'Donations for operations', '13.2'],
    ];

    /** umra_line => position key. */
    public const POSITION_MAP = [
        'F5.1.1' => 'cash_hand', 'F4.1.1' => 'cash_hand', 'F5.1.2' => 'cash_bank', 'F4.2.1' => 'cash_bank', 'F5.1.3' => 'other_fi', 'F4.3.1' => 'other_fi', 'F4.3.2' => 'other_fi',
        'F5.2' => 'prepayments', 'F5.3.1' => 'gov_securities', 'F4.4.1' => 'gov_securities', 'F4.4.2' => 'gov_securities', 'F5.3.2' => 'other_securities', 'F5.3.3' => 'other_investments',
        'F5.4.1' => 'loans_gross', 'F5.4.2' => 'loan_allowance', 'F5.5' => 'receivables', 'F5.PPE' => 'ppe', 'F5.INT' => 'intangibles', 'F5.OA' => 'other_assets',
        'F5.SAV' => 'savings', 'F4.6.1' => 'interest_payable', 'F5.STD' => 'fixed_deposits', 'F5.NWD' => 'non_withdrawable', 'F5.AP' => 'payables', 'F5.TAX' => 'tax_payable',
        'F5.DIV' => 'dividends_payable', 'F5.DG' => 'deferred_grants', 'F5.EB' => 'external_borrowings', 'F5.OL' => 'other_liabilities',
        'F3.1.1.1' => 'share_capital', 'F3.1.1.2' => 'statutory_reserve', 'F3.1.1.3' => 'retained_prior', 'F3.1.1.4' => 'retained_prior', 'F3.1.1.5' => 'grants',
        'F3.1.1.6' => 'general_reserves', 'F3.1.1.7' => 'other_reserves', 'F5.REV' => 'revaluation', 'F5.PD' => 'proposed_dividends',
    ];

    /** umra_line => income key. */
    public const INCOME_MAP = [
        'F6.2.1' => 'int_portfolio', 'F6.2.2' => 'fees_portfolio', 'F6.3' => 'inv_income', 'F6.OOI' => 'other_op_income', 'F6.4.1' => 'int_funding', 'F6.4.2' => 'int_deposits',
        'F6.4.3' => 'int_borrowing', 'F6.4.4' => 'dividend_exp', 'F6.6.1' => 'provision', 'F6.6.2' => 'recoveries', 'F6.7.1' => 'personnel', 'F6.7.2' => 'governance',
        'F6.7.3' => 'marketing', 'F6.7.4' => 'depreciation', 'F6.7.5' => 'admin', 'F6.9' => 'other_income', 'F6.11' => 'tax', 'F6.13.1' => 'donations_capital',
        'F6.13.2' => 'donations_ops',
    ];

    /** The standard codes that carried a bare `F5` before the chart named their lines. */
    public const LEGACY_CODES = ['1800' => 'intangibles', '1900' => 'other_assets', '2400' => 'payables', '2410' => 'tax_payable', '2420' => 'dividends_payable',
        '2440' => 'other_liabilities', '2600' => 'deferred_grants', '3700' => 'revaluation', '3800' => 'proposed_dividends'];

    public static function positionLine(SaccoLedgerAccount $a): string
    {
        if ($a->system_key === 're.current') {
            return 'retained_prior'; // what the close moved here waits for the AGM; this year's surplus is computed (Figures)
        }
        if ($a->system_key === 'opening') {
            return 'other_equity';
        }
        $line = (string) $a->umra_line;
        if (isset(self::POSITION_MAP[$line]) && self::POSITION[self::POSITION_MAP[$line]][0] === self::group($a)) {
            return self::POSITION_MAP[$line];
        }
        if (isset(self::LEGACY_CODES[(string) $a->code]) && in_array($line, ['', 'F5'], true)) {
            return self::LEGACY_CODES[(string) $a->code];
        }

        return match ($a->type) {
            'asset' => 'other_assets',
            'liability' => 'other_liabilities',
            default => 'other_equity',
        };
    }

    public static function incomeLine(SaccoLedgerAccount $a): string
    {
        $key = self::INCOME_MAP[(string) $a->umra_line] ?? null;
        if ($key !== null && self::INCOME[$key][0] === $a->type) {
            return $key;
        }

        return $a->type === 'income' ? 'other_income' : 'admin';
    }

    private static function group(SaccoLedgerAccount $a): string
    {
        return match ($a->type) {
            'asset' => 'assets',
            'liability' => 'liabilities',
            default => 'equity',
        };
    }
}
