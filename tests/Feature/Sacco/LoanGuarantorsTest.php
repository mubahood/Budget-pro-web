<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\StoredFile;
use App\Services\Sacco\Loans\Collateral;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Savings\SavingsAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.6 (SI 108 reg. 8 and 10, Coop s.55AP(3), AK-48, AK-49): guarantors are told in writing and
 * consent in writing (a signed form, or the link with a fresh code); consent holds their savings (the available
 * balance falls); officers, the borrower, over-committed members and over-capacity amounts are refused; collateral
 * never includes an ID or a passbook and is not sold before 60 days after the demand.
 */
class LoanGuarantorsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_exclusions_capacity_notice_consent_and_the_lien(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $borrower = $this->loanMember($company, $owner);
        $this->loanSavings($company, $borrower, 100_000, $owner);
        $loan = $this->application($company, $borrower, 300_000, 12, $owner);
        $g = new Guarantors();

        $this->assertSame('guarantor_not_allowed', $this->errorCode(fn () => $g->add($company, (int) $loan->id, (int) $borrower->id, 50_000, $owner)), 'not their own');
        $officerMember = $this->loanMember($company, $owner);
        $this->loanSavings($company, $officerMember, 900_000, $owner);
        $this->officerUser($company, 'sacco_committee', 'loans_committee', null, (int) $officerMember->id);
        $this->assertSame('guarantor_not_allowed', $this->errorCode(fn () => $g->add($company, (int) $loan->id, (int) $officerMember->id, 50_000, $owner)), 'officers may not guarantee');

        $ann = $this->loanMember($company, $owner, 400, ['first_name' => 'Ann']);
        $account = $this->loanSavings($company, $ann, 150_000, $owner);
        $this->assertSame('guarantee_capacity', $this->errorCode(fn () => $g->add($company, (int) $loan->id, (int) $ann->id, 150_001, $owner)));
        $ga = $g->add($company, (int) $loan->id, (int) $ann->id, 100_000, $owner);
        $this->assertNotNull($ga->notice_sent_at, 'the written notice');
        $this->assertStringContainsString('/sacco/consent/', $ga->consent_link);
        $this->assertSame('guarantor_exists', $this->errorCode(fn () => $g->add($company, (int) $loan->id, (int) $ann->id, 10_000, $owner)));
        $sms = DB::table('message_log')->where('company_id', $cid)->where('purpose', 'sacco_notice')->where('body', 'like', '%asks you to guarantee%')->latest('id')->first();
        $this->assertNotNull($sms, 'the guarantor is told after the commit');
        $this->assertStringContainsString('asks you to guarantee', (string) $sms->body);
        $this->assertStringNotContainsString((string) $borrower->last_name, (string) $sms->body, 'only the borrower\'s first name');

        [$needed, $consented, $count] = Guarantors::coverage($loan->fresh());
        $this->assertSame([200_000, 0, 0], [$needed, $consented, $count], '300,000 less 100,000 own free savings');

        $token = Str::afterLast($ga->consent_link, '/');
        $notice = Guarantors::notice($token);
        $this->assertSame([100_000, 300_000, 'requested'], [$notice['guaranteed_minor'], $notice['loan_minor'], $notice['status']]);
        $this->assertStringContainsString('may recover up to the amount you guarantee', $notice['liability']);
        $this->assertSame('link_invalid', $this->errorCode(fn () => Guarantors::notice('nonsense-token-that-is-long-enough-to-check-xxx')));
        $g->requestCode($token);
        $this->assertNotSame('none', $this->errorCode(fn () => $g->consentByLink($token, '999999')), 'a wrong code consents to nothing');
        $code = cache()->get('otp_test_sacco_guarantee:'.$ga->id);
        $before = SavingsAccounts::available($cid, (int) $account->id);
        $done = $g->consentByLink($token, (string) $code);
        $this->assertSame(['consented', 'sms_link', 100_000, (int) $account->id], [$done->consent_status, $done->consent_channel, $done->held_minor, (int) $done->hold_savings_account_id]);
        $this->assertSame($before - 100_000, SavingsAccounts::available($cid, (int) $account->id), 'the lien lowers what Ann can withdraw');
        $this->assertSame('link_invalid', $this->errorCode(fn () => Guarantors::notice($token)), 'a used link is spent');

        $bob = $this->loanMember($company, $owner, 400, ['first_name' => 'Bob']);
        $this->loanSavings($company, $bob, 300_000, $owner);
        $gb = $g->add($company, (int) $loan->id, (int) $bob->id, 100_000, $owner);
        $this->assertSame('file_not_found', $this->errorCode(fn () => $g->consentWithForm($company, (int) $gb->id, 999999999, $owner)));
        $file = StoredFile::create(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_guarantee', 'path' => 'x/form.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10]);
        $this->assertSame('consented', $g->consentWithForm($company, (int) $gb->id, (int) $file->id, $owner)->consent_status);
        $this->assertSame([200_000, 200_000, 2], Guarantors::coverage($loan->fresh()), 'fully covered by two guarantors');
        $this->assertSame(['count' => 1, 'guaranteed_minor' => 100_000, 'held_minor' => 100_000], Guarantors::exposure($cid, (int) $bob->id));

        (new LoanApplications())->withdraw($company, (int) $loan->id, $owner, 'Changed plans');
        $this->assertSame(0, (int) DB::table('sacco_savings_accounts')->where('id', $account->id)->value('held_minor'), 'released with the loan');
        $this->assertSame(['released', 'released'], SaccoLoanGuarantor::where('loan_id', $loan->id)->orderBy('id')->pluck('consent_status')->all());
    }

    public function test_three_guarantees_at_most_and_decline(): void
    {
        [$company, $owner] = $this->loanSacco();
        $gm = $this->loanMember($company, $owner);
        $this->loanSavings($company, $gm, 5_000_000, $owner);
        $g = new Guarantors();
        $loans = [];
        foreach (range(1, 4) as $i) {
            $b = $this->loanMember($company, $owner);
            $this->loanSavings($company, $b, 50_000, $owner);
            $loans[] = $this->application($company, $b, 100_000, 6, $owner);
        }
        foreach (array_slice($loans, 0, 3) as $l) {
            $g->add($company, (int) $l->id, (int) $gm->id, 10_000, $owner);
        }
        $this->assertSame('guarantee_limit', $this->errorCode(fn () => $g->add($company, (int) $loans[3]->id, (int) $gm->id, 10_000, $owner)));
        $first = SaccoLoanGuarantor::where('loan_id', $loans[0]->id)->firstOrFail();
        $this->assertSame('declined', $g->decline((int) $company->id, (int) $first->id, $owner, 'Not this time')->consent_status);
        $this->assertSame('requested', $g->add($company, (int) $loans[3]->id, (int) $gm->id, 10_000, $owner)->consent_status, 'a declined request frees the slot');
    }

    public function test_collateral_register(): void
    {
        [$company, $owner] = $this->loanSacco();
        $b = $this->loanMember($company, $owner);
        $this->loanSavings($company, $b, 100_000, $owner);
        $loan = $this->application($company, $b, 200_000, 6, $owner);
        $c = new Collateral();
        foreach (['national_id', 'passport', 'atm_card', 'savings_passbook', 'transfer_deed'] as $type) {
            $this->assertSame('collateral_not_allowed', $this->errorCode(fn () => $c->add($company, (int) $loan->id, ['type' => $type, 'description' => 'x', 'value_minor' => 1], $owner)));
        }
        SaccoLoanProduct::where('id', $loan->product_id)->update(['collateral_types' => json_encode(['logbook'])]);
        $this->assertSame('collateral_not_accepted', $this->errorCode(fn () => $c->add($company, (int) $loan->id, ['type' => 'land_title', 'description' => 'Plot 4', 'value_minor' => 9_000_000], $owner)));
        $item = $c->add($company, (int) $loan->id, ['type' => 'logbook', 'description' => 'Motorcycle UEX 123A', 'value_minor' => 3_500_000], $owner);
        $this->assertSame('demand_notice_period', $this->errorCode(fn () => $c->realise($company, (int) $item->id, '2026-12-01', 2_000_000, $owner)), 'no demand yet');
        $c->demandNotice($company, (int) $item->id, '2026-10-01', $owner);
        $this->assertSame('demand_notice_period', $this->errorCode(fn () => $c->realise($company, (int) $item->id, '2026-11-29', 2_000_000, $owner)), '59 days');
        $sold = $c->realise($company, (int) $item->id, '2026-11-30', 2_000_000, $owner);
        $this->assertSame(['2026-11-30', 2_000_000], [$sold->realised_on->toDateString(), $sold->realised_minor]);
        $this->assertSame('collateral_history', $this->errorCode(fn () => $sold->delete()));
    }
}
