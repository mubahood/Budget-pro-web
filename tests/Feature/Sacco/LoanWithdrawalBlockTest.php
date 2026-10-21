<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\StoredFile;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.14 (Coop s.55AK, AK-30): while a member's loan is in arrears, or a loan they guarantee is, their
 * withdrawals wait for a second officer, who sees why; approving is the override. Up to date, they pay out at once.
 */
class LoanWithdrawalBlockTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_arrears_send_withdrawals_to_approval_for_borrower_and_guarantor(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $b = $this->loanMember($company, $owner);
        $bAcct = $this->loanSavings($company, $b, 500_000, $owner);
        $gm = $this->loanMember($company, $owner);
        $gAcct = $this->loanSavings($company, $gm, 500_000, $owner);
        $loan = $this->signedLoan($company, $b, 600_000, 6);
        $g = (new Guarantors())->add($company, (int) $loan->id, (int) $gm->id, 100_000, $owner);
        $file = StoredFile::create(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_guarantee', 'path' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1]);
        (new Guarantors())->consentWithForm($company, (int) $g->id, (int) $file->id, $owner);
        $stx = app(SaccoTransactions::class);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('posted', $stx->record($company, ['kind' => 'withdrawal', 'savings_account_id' => $bAcct->id, 'amount_minor' => 10_000, 'channel' => 'cash'], $teller)['transaction']->status);

        DB::table('sacco_loans')->where('id', $loan->id)->update(['status' => 'active', 'days_past_due' => 12]);
        $tx = $stx->record($company, ['kind' => 'withdrawal', 'savings_account_id' => $bAcct->id, 'amount_minor' => 10_000, 'channel' => 'cash'], $teller)['transaction'];
        $this->assertSame('pending_approval', $tx->status, 'the borrower is in arrears');
        $approval = SaccoApproval::find($tx->approval_id);
        $this->assertStringContainsString('in arrears', json_encode($approval->payload));
        $gx = $stx->record($company, ['kind' => 'withdrawal', 'savings_account_id' => $gAcct->id, 'amount_minor' => 10_000, 'channel' => 'cash'], $teller)['transaction'];
        $this->assertSame('pending_approval', $gx->status, 'so is the loan Gertrude guarantees');
        (new SaccoApprovals())->approve($company, (int) $tx->approval_id, $owner);
        $this->assertSame('posted', $tx->fresh()->status, 'a second officer overrides it');
    }
}
