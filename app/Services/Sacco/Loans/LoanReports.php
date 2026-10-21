<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Services\Sacco\Reports\SaccoReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The loan reports (04 §12, P2.19), registered into SaccoReports: computed from the schedule, the repayments by value
 * date and the journal, never from stored totals, as of the report's `to` date. Money as `*_minor` integers.
 *
 * | Key | What |
 * |---|---|
 * | portfolio | every loan open on the day: outstanding principal, class, days past due, by product and class |
 * | par | PAR1, PAR30, PAR60, PAR90: principal of loans more than N days late ÷ the gross portfolio |
 * | aging | Form 1's bands (current, 1–60, 61–90, 91–180, over 180) with the provision each requires |
 * | arrears_list | the collections work list: who is late, by how much, since when, with guarantors |
 * | disbursements, repayments | what went out and came in between two dates |
 * | loan_statement | one loan's lines on 1300/1320/1330/1340 with what was owed after each |
 * | guarantor_exposure | per guarantor: live guarantees, amounts held, the borrowers' state |
 */
final class LoanReports
{
    public const PAR_DAYS = [1, 30, 60, 90];

    public static function register(): void
    {
        SaccoReports::register('portfolio', 'Loan portfolio', 'sacco_reports', fn (int $cid, array $p) => self::portfolio($cid, $p));
        SaccoReports::register('par', 'Portfolio at risk', 'sacco_reports', fn (int $cid, array $p) => self::par($cid, $p));
        SaccoReports::register('aging', 'Loan ageing (Form 1 bands)', 'sacco_reports', fn (int $cid, array $p) => self::aging($cid, $p));
        SaccoReports::register('arrears_list', 'Loans in arrears', 'sacco_reports', fn (int $cid, array $p) => self::arrears($cid, $p));
        SaccoReports::register('disbursements', 'Loans paid out', 'sacco_reports', fn (int $cid, array $p) => self::disbursements($cid, $p));
        SaccoReports::register('repayments', 'Loan repayments', 'sacco_reports', fn (int $cid, array $p) => self::repayments($cid, $p));
        SaccoReports::register('loan_statement', 'Loan statement', 'sacco_reports', fn (int $cid, array $p) => self::statement($cid, $p));
        SaccoReports::register('guarantor_exposure', 'Guarantor exposure', 'sacco_reports', fn (int $cid, array $p) => self::guarantors($cid, $p));
    }

    /**
     * Each loan open on a day, with its principal, arrears and days past due as of that day.
     *
     * @return list<array{loan: SaccoLoan, principal: int, dpd: int, arrears_principal: int, arrears_interest: int, class: string}>
     */
    public static function book(int $companyId, string $on): array
    {
        $day = Carbon::parse($on)->startOfDay();
        $out = [];
        $loans = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNotNull('disbursed_on')->where('disbursed_on', '<=', $day->toDateString())
            ->whereIn('status', ['active', 'closed', 'written_off', 'disbursing'])->orderBy('loan_no')->get();
        $accruals = new Accruals();
        foreach ($loans as $loan) {
            if (($loan->closed_on !== null && $loan->closed_on->lte($day)) || ($loan->written_off_on !== null && $loan->written_off_on->lte($day))) {
                continue;
            }
            $principal = $accruals->principalAt($loan, $day);
            if ($principal <= 0) {
                continue;
            }
            $pos = Arrears::position($loan, $day);
            // The class the daily run gave it (it keeps a non-performing loan's class until it has performed); for an
            // earlier day than the run's, the class its days late give.
            $class = $loan->arrears_on !== null && $day->gte($loan->arrears_on) ? (string) $loan->classification : $pos['class'];
            $out[] = ['loan' => $loan, 'principal' => $principal, 'dpd' => $pos['dpd'], 'arrears_principal' => $pos['arrears_principal'],
                'arrears_interest' => $pos['arrears_interest'], 'class' => $class];
        }

        return $out;
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function portfolio(int $cid, array $p): array
    {
        $book = self::book($cid, (string) $p['to']);
        $members = self::members($cid);
        $products = DB::table('sacco_loan_products')->where('company_id', $cid)->pluck('name', 'id');
        $rows = [];
        $byClass = [];
        $byProduct = [];
        foreach ($book as $b) {
            $l = $b['loan'];
            $rows[] = ['loan_no' => $l->loan_no, 'member_no' => $members[$l->member_id]['no'] ?? '', 'name' => $members[$l->member_id]['name'] ?? '',
                'product' => $products[$l->product_id] ?? '', 'disbursed_on' => $l->disbursed_on?->toDateString(), 'principal_minor' => $b['principal'],
                'days_past_due' => $b['dpd'], 'classification' => $b['class'], 'rescheduled' => (int) $l->reschedule_count > 0 ? 'yes' : ''];
            $byClass[$b['class']] = ($byClass[$b['class']] ?? 0) + $b['principal'];
            $product = $products[$l->product_id] ?? '';
            $byProduct[$product] = ($byProduct[$product] ?? 0) + $b['principal'];
        }

        return ['columns' => [self::c('loan_no', 'Loan'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('product', 'Product'), self::c('disbursed_on', 'Paid out', 'date'),
            self::c('principal_minor', 'Principal', 'money'), self::c('days_past_due', 'Days late', 'number'), self::c('classification', 'Class'), self::c('rescheduled', 'Rescheduled')],
            'rows' => $rows, 'totals' => ['principal_minor' => array_sum(array_column($rows, 'principal_minor')), 'loans' => count($rows)],
            'meta' => ['by_class' => $byClass, 'by_product' => $byProduct]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function par(int $cid, array $p): array
    {
        $book = self::book($cid, (string) $p['to']);
        $gross = array_sum(array_column($book, 'principal'));
        $rows = [];
        foreach (self::PAR_DAYS as $n) {
            $at = array_filter($book, fn ($b) => $b['dpd'] >= $n);
            $risk = array_sum(array_column($at, 'principal'));
            $rows[] = ['measure' => 'PAR'.$n, 'loans' => count($at), 'principal_minor' => $risk, 'gross_minor' => $gross, 'ratio_bp' => $gross > 0 ? intdiv($risk * 10000 + intdiv($gross, 2), $gross) : 0];
        }

        return ['columns' => [self::c('measure', 'Measure'), self::c('loans', 'Loans', 'number'), self::c('principal_minor', 'Principal at risk', 'money'),
            self::c('gross_minor', 'Gross portfolio', 'money'), self::c('ratio_bp', 'Ratio', 'bp')], 'rows' => $rows, 'totals' => ['gross_minor' => $gross]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function aging(int $cid, array $p): array
    {
        $book = self::book($cid, (string) $p['to']);
        $currency = (string) LoanBooks::settings($cid)->currency;
        $rows = [];
        foreach ((array) config('sacco.provisioning') as [$class, $from, $to, $bp]) {
            $in = array_filter($book, fn ($b) => $b['class'] === $class);
            $principal = array_sum(array_column($in, 'principal'));
            $required = 0;
            foreach ($in as $b) {
                $required += \App\Support\Sacco\Amount::of($b['principal'], $currency)->times(bcdiv((string) $bp, '10000', 4))->minor;
            }
            $rows[] = ['band' => $class, 'days' => $to === null ? "over {$from}" : ($from === $to ? (string) $from : "{$from}–{$to}"), 'loans' => count($in),
                'principal_minor' => $principal, 'rescheduled_minor' => array_sum(array_column(array_filter($in, fn ($b) => (int) $b['loan']->reschedule_count > 0), 'principal')),
                'provision_bp' => (int) $bp, 'required_minor' => $required];
        }

        return ['columns' => [self::c('band', 'Class'), self::c('days', 'Days late'), self::c('loans', 'Loans', 'number'), self::c('principal_minor', 'Principal', 'money'),
            self::c('rescheduled_minor', 'Of which rescheduled', 'money'), self::c('provision_bp', 'Provision', 'bp'), self::c('required_minor', 'Required provision', 'money')],
            'rows' => $rows, 'totals' => ['principal_minor' => array_sum(array_column($rows, 'principal_minor')), 'required_minor' => array_sum(array_column($rows, 'required_minor'))]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function arrears(int $cid, array $p): array
    {
        $members = self::members($cid);
        $rows = [];
        foreach (array_filter(self::book($cid, (string) $p['to']), fn ($b) => $b['dpd'] > 0) as $b) {
            $l = $b['loan'];
            $rows[] = ['loan_no' => $l->loan_no, 'member_no' => $members[$l->member_id]['no'] ?? '', 'name' => $members[$l->member_id]['name'] ?? '',
                'phone' => $members[$l->member_id]['phone'] ?? '', 'days_past_due' => $b['dpd'], 'arrears_principal_minor' => $b['arrears_principal'],
                'arrears_interest_minor' => $b['arrears_interest'], 'penalty_minor' => (int) $l->penalty_due_minor, 'principal_minor' => $b['principal'], 'classification' => $b['class'],
                'guarantors' => (int) DB::table('sacco_loan_guarantors')->where('loan_id', $l->id)->where('consent_status', 'consented')->count()];
        }
        usort($rows, fn ($a, $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return ['columns' => [self::c('loan_no', 'Loan'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('phone', 'Phone'), self::c('days_past_due', 'Days late', 'number'),
            self::c('arrears_principal_minor', 'Principal late', 'money'), self::c('arrears_interest_minor', 'Interest late', 'money'), self::c('penalty_minor', 'Penalty', 'money'),
            self::c('principal_minor', 'Outstanding', 'money'), self::c('classification', 'Class'), self::c('guarantors', 'Guarantors', 'number')],
            'rows' => $rows, 'totals' => ['arrears_principal_minor' => array_sum(array_column($rows, 'arrears_principal_minor')), 'loans' => count($rows)]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function disbursements(int $cid, array $p): array
    {
        $members = self::members($cid);
        $rows = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('disbursed_on', [$p['from'], $p['to']])->whereNotIn('status', ['signed'])->orderBy('disbursed_on')->get()
            ->map(function (SaccoLoan $l) use ($members) {
                $c = Schedule::upfrontCharges((int) $l->approved_minor, $l->loanTerms(), (string) $l->currency);

                return ['disbursed_on' => $l->disbursed_on?->toDateString(), 'loan_no' => $l->loan_no, 'member_no' => $members[$l->member_id]['no'] ?? '', 'name' => $members[$l->member_id]['name'] ?? '',
                    'principal_minor' => (int) $l->approved_minor, 'charges_minor' => $c['fee'] + $c['insurance'], 'net_minor' => (int) $l->approved_minor - $c['fee'] - $c['insurance'],
                    'channel' => (string) $l->disbursement_channel, 'status' => $l->status];
            })->all();

        return ['columns' => [self::c('disbursed_on', 'Date', 'date'), self::c('loan_no', 'Loan'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('principal_minor', 'Principal', 'money'),
            self::c('charges_minor', 'Fees and insurance', 'money'), self::c('net_minor', 'Paid out', 'money'), self::c('channel', 'Channel'), self::c('status', 'Now')],
            'rows' => $rows, 'totals' => ['principal_minor' => array_sum(array_column($rows, 'principal_minor')), 'net_minor' => array_sum(array_column($rows, 'net_minor'))]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function repayments(int $cid, array $p): array
    {
        $members = self::members($cid);
        $rows = DB::table('sacco_loan_repayments as r')->join('sacco_loans as l', 'l.id', '=', 'r.loan_id')->where('r.company_id', $cid)->where('r.status', 'posted')
            ->whereBetween('r.value_date', [$p['from'], $p['to']])->orderBy('r.value_date')->orderBy('r.id')
            ->get(['r.value_date', 'l.loan_no', 'l.member_id', 'r.kind', 'r.channel', 'r.amount_minor', 'r.penalty_minor', 'r.penalty_suspense_minor', 'r.fees_minor', 'r.interest_minor',
                'r.interest_suspense_minor', 'r.interest_to_day_minor', 'r.principal_minor', 'r.overpayment_minor'])
            ->map(fn ($r) => ['value_date' => substr((string) $r->value_date, 0, 10), 'loan_no' => $r->loan_no, 'name' => $members[$r->member_id]['name'] ?? '', 'kind' => $r->kind,
                'channel' => $r->channel, 'amount_minor' => (int) $r->amount_minor, 'penalty_minor' => (int) $r->penalty_minor + (int) $r->penalty_suspense_minor, 'fees_minor' => (int) $r->fees_minor,
                'interest_minor' => (int) $r->interest_minor + (int) $r->interest_suspense_minor + (int) $r->interest_to_day_minor, 'principal_minor' => (int) $r->principal_minor,
                'overpayment_minor' => (int) $r->overpayment_minor])->all();
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return ['columns' => [self::c('value_date', 'Date', 'date'), self::c('loan_no', 'Loan'), self::c('name', 'Name'), self::c('kind', 'Kind'), self::c('channel', 'Channel'),
            self::c('amount_minor', 'Received', 'money'), self::c('penalty_minor', 'Penalty', 'money'), self::c('fees_minor', 'Fees', 'money'), self::c('interest_minor', 'Interest', 'money'),
            self::c('principal_minor', 'Principal', 'money'), self::c('overpayment_minor', 'To savings', 'money')], 'rows' => $rows,
            'totals' => ['amount_minor' => $sum('amount_minor'), 'penalty_minor' => $sum('penalty_minor'), 'fees_minor' => $sum('fees_minor'), 'interest_minor' => $sum('interest_minor'),
                'principal_minor' => $sum('principal_minor'), 'overpayment_minor' => $sum('overpayment_minor')]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function statement(int $cid, array $p): array
    {
        $loan = LoanBooks::find($cid, (int) ($p['loan_id'] ?? 0));
        $rows = DB::table('sacco_journal_lines as l')->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('sacco_ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('l.company_id', $cid)->where('l.sub_type', 'loan')->where('l.sub_id', $loan->id)->where('l.value_date', '<=', $p['to'])->orderBy('l.value_date')->orderBy('l.id')
            ->get(['e.entry_no', 'e.kind', 'e.memo', 'l.value_date', 'a.system_key', 'l.debit_minor', 'l.credit_minor']);
        $owed = 0;
        $out = [];
        foreach ($rows as $r) {
            $owed += (int) $r->debit_minor - (int) $r->credit_minor;
            $out[] = ['value_date' => substr((string) $r->value_date, 0, 10), 'entry_no' => $r->entry_no, 'what' => ucfirst(str_replace('_', ' ', (string) $r->kind)),
                'part' => ['loans.interest' => 'interest', 'loans.penalty' => 'penalty', 'loans.fees' => 'fees'][$r->system_key] ?? 'principal',
                'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'owed_minor' => $owed];
        }

        return ['columns' => [self::c('value_date', 'Date', 'date'), self::c('entry_no', 'Entry'), self::c('what', 'What'), self::c('part', 'Part'), self::c('debit_minor', 'Charged', 'money'),
            self::c('credit_minor', 'Paid', 'money'), self::c('owed_minor', 'Owed', 'money')], 'rows' => $out,
            'totals' => ['owed_minor' => $owed, 'in_suspense_minor' => (int) $loan->interest_in_suspense_minor + (int) $loan->penalty_in_suspense_minor],
            'meta' => ['loan_no' => $loan->loan_no, 'status' => $loan->status]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    public static function guarantors(int $cid, array $p): array
    {
        $members = self::members($cid);
        $rows = [];
        foreach (DB::table('sacco_loan_guarantors as g')->join('sacco_loans as l', 'l.id', '=', 'g.loan_id')->where('g.company_id', $cid)->whereIn('g.consent_status', ['requested', 'consented'])
            ->orderBy('g.guarantor_member_id')->get(['g.guarantor_member_id', 'g.guaranteed_minor', 'g.held_minor', 'g.recovered_minor', 'g.consent_status', 'l.loan_no', 'l.member_id', 'l.status', 'l.days_past_due']) as $g) {
            $rows[] = ['guarantor_no' => $members[$g->guarantor_member_id]['no'] ?? '', 'guarantor' => $members[$g->guarantor_member_id]['name'] ?? '', 'loan_no' => $g->loan_no,
                'borrower' => $members[$g->member_id]['name'] ?? '', 'consent' => $g->consent_status, 'guaranteed_minor' => (int) $g->guaranteed_minor, 'held_minor' => (int) $g->held_minor,
                'recovered_minor' => (int) $g->recovered_minor, 'loan_status' => $g->status, 'days_past_due' => (int) $g->days_past_due];
        }

        return ['columns' => [self::c('guarantor_no', 'Guarantor'), self::c('guarantor', 'Name'), self::c('loan_no', 'Loan'), self::c('borrower', 'Borrower'), self::c('consent', 'Consent'),
            self::c('guaranteed_minor', 'Guaranteed', 'money'), self::c('held_minor', 'Held', 'money'), self::c('recovered_minor', 'Recovered', 'money'), self::c('loan_status', 'Loan'),
            self::c('days_past_due', 'Days late', 'number')], 'rows' => $rows,
            'totals' => ['guaranteed_minor' => array_sum(array_column($rows, 'guaranteed_minor')), 'held_minor' => array_sum(array_column($rows, 'held_minor'))]];
    }

    /** @return array<int, array{no: string, name: string, phone: string}> */
    private static function members(int $cid): array
    {
        return DB::table('sacco_members')->where('company_id', $cid)->get(['id', 'member_no', 'first_name', 'other_names', 'last_name', 'phone_e164'])
            ->mapWithKeys(fn ($m) => [(int) $m->id => ['no' => (string) $m->member_no, 'name' => trim(implode(' ', array_filter([$m->first_name, $m->other_names, $m->last_name]))),
                'phone' => (string) $m->phone_e164]])->all();
    }

    /** @return array{key: string, label: string, type: string} */
    private static function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
