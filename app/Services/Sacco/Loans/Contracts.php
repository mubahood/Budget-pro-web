<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanCollateral;
use App\Models\SaccoLoanDocument;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Support\Sacco\WorkingDays;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The key-facts sheet and the loan contract (SI 108 reg. 4(3), 5, 15; 04 §3.4, P2.8), and signing with a witness.
 *
 * The contract states the 18 things reg. 5 asks for (fields()), with the rate as a % per year and the total cost of
 * credit in money. Both are rendered to PDF (dompdf) from HTML built here, stored under the kernel's own storage
 * (found from this class, so the portal writes to the same place) with their SHA-256: what the member was shown is
 * what they sign, and a stored document never changes. Signing needs the contract of the current schedule, a
 * witness (a third party, reg. 5), and starts the cooling-off (reg. 11, AK-26): the day the borrower may still cancel
 * is that many working days after signing.
 */
final class Contracts
{
    /** reg. 5's list, in the contract's order (the golden test reads it). */
    public const FIELDS = ['lender', 'borrower', 'principal', 'disbursement', 'method', 'rate_per_year', 'total_cost_of_credit', 'fees', 'schedule',
        'repayment_mode', 'penalty', 'collateral', 'guarantorship', 'cooling_off', 'early_repayment', 'recovery', 'borrower_duties', 'regulator'];

    /** Where loan documents live: the kernel's storage, whichever app runs this code. */
    public static function root(): string
    {
        return dirname(__DIR__, 4).'/storage/app/sacco/loans';
    }

    /**
     * The 18 fields, as label => text.
     *
     * @return array<string, array{label: string, text: string}>
     */
    public function fields(SaccoLoan $loan): array
    {
        $cid = (int) $loan->company_id;
        $settings = LoanBooks::settings($cid);
        $member = SaccoMember::withoutGlobalScopes()->findOrFail($loan->member_id);
        $terms = $loan->loanTerms();
        $amount = (int) ($loan->approved_minor ?? $loan->applied_minor);
        $on = $loan->disbursed_on ?? LoanBooks::today($cid);
        $first = $loan->first_due_on ?? Schedule::firstDueAfter($on, $terms->frequency);
        $facts = LoanProducts::keyFacts($amount, $terms, Carbon::parse($on), Carbon::parse($first), (string) $loan->currency);
        $m = fn (int $minor) => LoanBooks::money($cid, $minor)->toMajorString().' '.$loan->currency;
        $pct = fn (int $bp) => rtrim(rtrim(bcdiv((string) $bp, '100', 2), '0'), '.').' %';
        $method = ['reducing_annuity' => 'reducing balance, equal instalments: each instalment\'s interest is the principal still owed at the start of the period times the periodic rate',
            'reducing_equal_principal' => 'reducing balance, equal principal: each instalment repays the same principal plus interest on the principal still owed',
            'flat' => 'flat: each instalment\'s interest is the original principal times the periodic rate (savings groups only)'][$terms->method];
        $collateral = SaccoLoanCollateral::withoutGlobalScopes()->where('loan_id', $loan->id)->whereNull('released_on')->get()
            ->map(fn ($c) => str_replace('_', ' ', $c->type).': '.$c->description.' (valued '.$m((int) $c->value_minor).')')->implode('; ');
        $guarantors = SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->whereIn('consent_status', ['requested', 'consented'])->get()
            ->map(function ($g) use ($m) {
                $name = SaccoMember::withoutGlobalScopes()->find($g->guarantor_member_id)?->full_name ?? '';

                return $name.' guarantees up to '.$m((int) $g->guaranteed_minor);
            })->implode('; ');
        $reg = $settings->regulatedLine();

        return [
            'lender' => ['label' => 'Lender', 'text' => $settings->legal_name.($settings->registration_no ? ', '.$settings->registration_no : '').($settings->licence_no ? ', licence '.$settings->licence_no : '')],
            'borrower' => ['label' => 'Borrower', 'text' => $member->full_name.', member '.$member->member_no],
            'principal' => ['label' => 'Principal', 'text' => $m($amount)],
            'disbursement' => ['label' => 'Disbursement', 'text' => 'On or after '.Carbon::parse($on)->toDateString().', '.$m($facts['net_disbursed_minor']).' after the charges below, by '.($loan->disbursement_channel ?? 'the channel agreed')],
            'method' => ['label' => 'How interest is calculated', 'text' => ucfirst($method).'. No interest is charged on interest.'],
            'rate_per_year' => ['label' => 'Interest rate', 'text' => $pct($terms->annualRateBp()).' a year ('.$pct($terms->monthlyRateBp()).' a month)'],
            'total_cost_of_credit' => ['label' => 'Total cost of credit', 'text' => $m($facts['total_cost_of_credit_minor']).': interest '.$m($facts['total_interest_minor']).', fees and insurance '.$m($facts['processing_fee_minor'] + $facts['insurance_minor']).' ('.$pct($facts['cost_per_year_bp']).' of the principal a year)'],
            'fees' => ['label' => 'Fees and charges', 'text' => 'Processing fee '.$m($facts['processing_fee_minor']).', insurance '.$m($facts['insurance_minor']).', taken from the amount paid out. No other fee.'],
            'schedule' => ['label' => 'Payment schedule', 'text' => $terms->term.' '.$terms->frequency.' instalments of about '.$m($facts['instalment_minor']).', the first on '.$facts['first_due_on'].', the last on '.$facts['maturity_on'].' (schedule attached); total repayable '.$m($facts['total_repayable_minor'])],
            'repayment_mode' => ['label' => 'How to repay', 'text' => 'In cash or by bank at the SACCO, or from your savings with the SACCO; a receipt is given for every payment. Payments go to penalty, then fees, then interest, then principal.'],
            'penalty' => ['label' => 'Late payment', 'text' => $terms->penaltyRateBp > 0 ? $pct($terms->penaltyRateBp).' a month on overdue principal only, from '.($terms->graceDays > 0 ? $terms->graceDays.' days after' : 'the day after').' the due date, never compounded' : 'No penalty interest.'],
            'collateral' => ['label' => 'Security', 'text' => $collateral !== '' ? $collateral.'. It is never sold before 60 days after a written demand.' : 'None besides the guarantees below.'],
            'guarantorship' => ['label' => 'Guarantors', 'text' => $guarantors !== '' ? $guarantors.'. Each guarantor was told the amount and nature of the liability in writing and consented; their guaranteed amount is held from their savings until the loan is repaid.' : 'No guarantors.'],
            'cooling_off' => ['label' => 'Cooling-off', 'text' => $terms->coolingOffWorkingDays > 0 ? 'You may cancel within '.$terms->coolingOffWorkingDays.' working days after signing by returning what you received'.($terms->cancellationFeeBp > 0 ? ' and a fee of '.$pct($terms->cancellationFeeBp).' of the loan' : ', with no fee').'; no interest is charged.' : 'No cooling-off period applies (a savings group).'],
            'early_repayment' => ['label' => 'Early repayment', 'text' => 'You may repay early, in part or in full, at any time. Interest is charged only to the day you repay, and there is no early-repayment fee.'],
            'recovery' => ['label' => 'If you do not pay', 'text' => 'The SACCO will remind you and talk to you; it contacts only you and your guarantors. It may then recover from your savings and shares, then your guarantors\' held savings, then the security after a written demand and 60 days.'],
            'borrower_duties' => ['label' => 'Your duties', 'text' => 'Use the loan for '.($loan->purpose ?: 'the purpose stated').', repay on the dates above, keep the security safe and insured, and tell the SACCO if your income or contact details change.'],
            'regulator' => ['label' => 'Regulation and complaints', 'text' => ($reg ?? $settings->legal_name.' is a registered cooperative society').'. Complaints go first to the SACCO\'s manager, then the supervisory committee, then '.$settings->regulator()['name'].'.'],
        ];
    }

    /** Make the key-facts sheet and the contract for an approved loan (again after a reschedule: a new version). @return array{key_facts: SaccoLoanDocument, contract: SaccoLoanDocument} */
    public function generate(Company $company, int $loanId, User $by): array
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $loanId, $by) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, ['approved', 'signed', 'active'], 'given a contract');
            $fields = $this->fields($loan);
            $out = [];
            foreach (['key_facts', 'contract'] as $type) {
                $html = $type === 'contract' ? $this->contractHtml($loan, $fields) : $this->keyFactsHtml($loan, $fields);
                $out[$type] = $this->store($loan, $type, $html, (int) $by->id);
            }
            SaccoAudit::record('sacco.loan.contract_generated', $loan, $cid, ['key_facts' => $out['key_facts']->sha256, 'contract' => $out['contract']->sha256], (int) $by->id);

            return $out;
        });
    }

    /**
     * The borrower signs, before a witness (reg. 5). Optionally the scanned signed copy (a file id). Starts the
     * cooling-off.
     *
     * @param  array<string, mixed>  $data  witness_name, witness_phone, signed_on, signed_file_id
     */
    public function sign(Company $company, int $loanId, User $by, array $data): SaccoLoan
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;
        $witness = trim((string) ($data['witness_name'] ?? ''));
        if ($witness === '') {
            throw BusinessRuleException::make('witness_required', 'A third party witnesses the signing (Lending Conditions Regulations 2024, reg. 5).');
        }

        return DB::transaction(function () use ($cid, $loanId, $by, $data, $witness) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, ['approved'], 'signed');
            $contract = SaccoLoanDocument::withoutGlobalScopes()->where('loan_id', $loan->id)->where('type', 'contract')->orderByDesc('id')->first()
                ?? throw BusinessRuleException::make('contract_required', 'Print the contract and key facts first: the member signs what they were shown.');
            $member = SaccoMember::withoutGlobalScopes()->findOrFail($loan->member_id);
            if (mb_strtolower($witness) === mb_strtolower((string) $member->full_name)) {
                throw BusinessRuleException::make('witness_required', 'The witness is someone other than the borrower.');
            }
            $signedOn = Carbon::parse($data['signed_on'] ?? LoanBooks::today($cid))->startOfDay();
            if ($signedOn->gt(LoanBooks::today($cid))) {
                throw BusinessRuleException::make('invalid_date', 'A contract is not signed in the future.');
            }
            $days = $loan->loanTerms()->coolingOffWorkingDays;
            SaccoLoanDocument::create(['company_id' => $cid, 'loan_id' => $loan->id, 'type' => 'signed_contract', 'file_id' => $data['signed_file_id'] ?? null,
                'path' => $contract->path, 'sha256' => $contract->sha256, 'schedule_version' => $contract->schedule_version, 'generated_at' => $contract->generated_at,
                'signed_by_member_at' => $signedOn, 'witness_name' => mb_substr($witness, 0, 150), 'witness_phone_e164' => $data['witness_phone'] ?? null, 'created_by_id' => $by->id]);
            $loan->update(['status' => 'signed', 'signed_at' => now(), 'signed_on' => $signedOn->toDateString(), 'witness_name' => mb_substr($witness, 0, 150),
                'witness_phone_e164' => $data['witness_phone'] ?? null, 'cooling_off_ends_on' => WorkingDays::add($signedOn, $days, 'UG')->toDateString(), 'updated_by_id' => $by->id]);
            SaccoAudit::record('sacco.loan.signed', $loan, $cid, ['witness' => $witness, 'contract_sha256' => $contract->sha256], (int) $by->id);

            return $loan;
        });
    }

    /** The stored PDF's bytes (for a download link), checked against its hash. */
    public function bytes(SaccoLoanDocument $doc): string
    {
        $bytes = (string) @file_get_contents(self::root().'/'.$doc->path);
        if ($bytes === '' || ! hash_equals((string) $doc->sha256, hash('sha256', $bytes))) {
            throw BusinessRuleException::make('document_damaged', 'This document is missing or was changed on disk.');
        }

        return $bytes;
    }

    /** @param  array<string, array{label: string, text: string}>  $fields */
    public function contractHtml(SaccoLoan $loan, array $fields): string
    {
        $rows = '';
        $n = 0;
        foreach (self::FIELDS as $key) {
            $n++;
            $rows .= '<tr><td class="n">'.$n.'</td><th>'.e($fields[$key]['label']).'</th><td>'.e($fields[$key]['text']).'</td></tr>';
        }

        return $this->page('Loan agreement '.e((string) $loan->loan_no), '<table class="f">'.$rows.'</table>'.$this->scheduleTable($loan)
            .'<p class="sig">Borrower: ____________________ &nbsp; Date: __________</p><p class="sig">For the SACCO: ____________________ &nbsp; Date: __________</p>'
            .'<p class="sig">Witness (name, phone): ____________________________________</p>');
    }

    /** @param  array<string, array{label: string, text: string}>  $fields */
    public function keyFactsHtml(SaccoLoan $loan, array $fields): string
    {
        $rows = '';
        foreach (['principal', 'rate_per_year', 'total_cost_of_credit', 'fees', 'schedule', 'penalty', 'cooling_off', 'early_repayment'] as $key) {
            $rows .= '<tr><th>'.e($fields[$key]['label']).'</th><td>'.e($fields[$key]['text']).'</td></tr>';
        }

        return $this->page('Key facts: loan '.e((string) $loan->loan_no), '<table class="f">'.$rows.'</table><p>'.e($fields['regulator']['text']).'</p>');
    }

    private function scheduleTable(SaccoLoan $loan): string
    {
        $cid = (int) $loan->company_id;
        $terms = $loan->loanTerms();
        $amount = (int) ($loan->approved_minor ?? $loan->applied_minor);
        $on = $loan->disbursed_on ?? LoanBooks::today($cid);
        $lines = Schedule::build($amount, $terms, $loan->first_due_on ?? Schedule::firstDueAfter($on, $terms->frequency), (string) $loan->currency);
        $m = fn (int $minor) => LoanBooks::money($cid, $minor)->toMajorString();
        $rows = '';
        foreach ($lines as $l) {
            $rows .= '<tr><td>'.$l->no.'</td><td>'.$l->dueOn.'</td><td>'.$m($l->principal).'</td><td>'.$m($l->interest).'</td><td>'.$m($l->instalment()).'</td></tr>';
        }

        return '<h2>Schedule ('.e((string) $loan->currency).')</h2><table class="s"><tr><th>#</th><th>Due</th><th>Principal</th><th>Interest</th><th>Instalment</th></tr>'.$rows.'</table>';
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'.$title.'</title><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt;color:#111}'
            .'h1{font-size:15pt}h2{font-size:12pt;margin-top:14pt}table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:4pt;text-align:left;vertical-align:top}'
            .'.f th{width:28%}.n{width:4%}.s td,.s th{font-size:10pt}.sig{margin-top:18pt}</style></head><body><h1>'.$title.'</h1>'.$body.'</body></html>';
    }

    private function store(SaccoLoan $loan, string $type, string $html, int $byUserId): SaccoLoanDocument
    {
        $bytes = app('dompdf.wrapper')->loadHTML($html)->setPaper('a4')->output();
        $n = SaccoLoanDocument::withoutGlobalScopes()->where('loan_id', $loan->id)->where('type', $type)->count() + 1;
        $relative = $loan->company_id.'/'.$loan->id.'-'.$type.'-v'.$loan->schedule_version.'-'.$n.'.pdf';
        $path = self::root().'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);

        return SaccoLoanDocument::create(['company_id' => $loan->company_id, 'loan_id' => $loan->id, 'type' => $type, 'path' => $relative, 'sha256' => hash('sha256', $bytes),
            'schedule_version' => (int) $loan->schedule_version, 'generated_at' => now(), 'created_by_id' => $byUserId]);
    }
}
