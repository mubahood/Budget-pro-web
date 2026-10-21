<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoanDocument;
use App\Services\Sacco\Loans\Contracts;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.8 (SI 108 reg. 4(3), 5, 11): the contract states the 18 things the law asks, with the rate a year
 * and the total cost of credit; the PDFs are stored with their hash and never change; signing needs the contract
 * and a witness, and the cooling-off counts working days past the holidays.
 */
class LoanContractsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_the_eighteen_fields_the_pdfs_and_signing(): void
    {
        [$company, $owner] = $this->loanSacco();
        $member = $this->loanMember($company, $owner, 400, ['first_name' => 'Nakato', 'last_name' => 'Aisha']);
        $this->loanSavings($company, $member, 2_000_000, $owner);
        $loan = $this->approvedLoan($company, $member, 1_000_000, 12);
        $svc = new Contracts();
        $officer = $this->loanStaffOf($company)['officer'];
        $this->assertSame('contract_required', $this->errorCode(fn () => $svc->sign($company, (int) $loan->id, $officer, ['witness_name' => 'Okello Peter'])));

        $fields = $svc->fields($loan->fresh());
        $this->assertSame(Contracts::FIELDS, array_keys($fields), 'all 18, in order');
        $this->assertCount(18, $fields);
        $this->assertSame('1000000 UGX', $fields['principal']['text']);
        $this->assertSame('24 % a year (2 % a month)', $fields['rate_per_year']['text']);
        $this->assertStringStartsWith('144715 UGX: interest 134715 UGX, fees and insurance 10000 UGX (14.48 % of the principal a year)', $fields['total_cost_of_credit']['text']);
        $this->assertStringContainsString('1 % a month on overdue principal only', $fields['penalty']['text']);
        $this->assertStringContainsString('no early-repayment fee', $fields['early_repayment']['text']);
        $this->assertStringContainsString('5 working days', $fields['cooling_off']['text']);
        $this->assertStringContainsString('Nakato Aisha', $fields['borrower']['text']);

        $docs = $svc->generate($company, (int) $loan->id, $officer);
        foreach ($docs as $doc) {
            $bytes = $svc->bytes($doc);
            $this->assertStringStartsWith('%PDF', $bytes);
            $this->assertSame($doc->sha256, hash('sha256', $bytes));
        }
        $html = $svc->contractHtml($loan->fresh(), $fields);
        foreach ($fields as $f) {
            $this->assertStringContainsString(e($f['label']), $html);
        }
        $this->assertSame('document_final', $this->errorCode(fn () => $docs['contract']->fill(['sha256' => str_repeat('0', 64)])->save()));

        $this->assertSame('witness_required', $this->errorCode(fn () => $svc->sign($company, (int) $loan->id, $officer, ['witness_name' => ' '])));
        $this->assertSame('witness_required', $this->errorCode(fn () => $svc->sign($company, (int) $loan->id, $officer, ['witness_name' => 'Nakato Aisha'])), 'not the borrower');
        $signed = $svc->sign($company, (int) $loan->id, $officer, ['witness_name' => 'Okello Peter', 'witness_phone' => '+256772000111', 'signed_on' => '2026-04-02']);
        $this->assertSame(['signed', '2026-04-02', '2026-04-13'], [$signed->status, $signed->signed_on->toDateString(), $signed->cooling_off_ends_on->toDateString()],
            'five working days past Good Friday and Easter Monday');
        $copy = SaccoLoanDocument::where('loan_id', $loan->id)->where('type', 'signed_contract')->firstOrFail();
        $this->assertSame($docs['contract']->fresh()->sha256, $copy->sha256, 'what was signed is what was shown');
        $this->assertSame('document_final', $this->errorCode(fn () => $copy->fill(['witness_name' => 'Someone else'])->save()));
        $this->assertSame('invalid_loan_status', $this->errorCode(fn () => $svc->sign($company, (int) $loan->id, $officer, ['witness_name' => 'Okello Peter'])));
    }
}
