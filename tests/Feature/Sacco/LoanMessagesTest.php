<?php

namespace Tests\Feature\Sacco;

use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\LoanNotices;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.20 (04 §10; SI 108 reg. 13, 16): a due reminder three days before, an overdue notice on day 1 to the
 * borrower alone, reminders only with consent, nothing to a member who opted out, every text in the SACCO's own name
 * and never the platform's.
 */
class LoanMessagesTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_reminders_overdue_notices_consent_and_the_name(): void
    {
        [$company, $owner] = $this->loanSacco('office', 'Kyamuhunga Teachers');
        $cid = (int) $company->id;
        $soon = $this->loanMember($company, $owner, 900, ['first_name' => 'Soon']);
        $this->loanSavings($company, $soon, 600_000, $owner, 'ORD', now()->subDays(100)->toDateString());
        $this->activeLoan($company, $soon, 300_000, 6, ['channel' => 'savings'], now()->subMonthNoOverflow()->addDays(3)->toDateString());
        $late = $this->loanMember($company, $owner, 900, ['first_name' => 'Late']);
        $this->loanSavings($company, $late, 600_000, $owner, 'ORD', now()->subDays(100)->toDateString());
        $this->activeLoan($company, $late, 300_000, 6, ['channel' => 'savings'], now()->subMonthNoOverflow()->subDay()->toDateString());
        $quiet = $this->loanMember($company, $owner, 900, ['first_name' => 'Quiet']);
        $this->loanSavings($company, $quiet, 600_000, $owner, 'ORD', now()->subDays(100)->toDateString());
        $this->activeLoan($company, $quiet, 300_000, 6, ['channel' => 'savings'], now()->subMonthNoOverflow()->subDay()->toDateString());
        $quiet->forceFill(['consent_messages_at' => null])->save();

        DB::table('message_log')->where('company_id', $cid)->delete();
        (new LoanJobs())->run($cid);
        $log = DB::table('message_log')->where('company_id', $cid)->get();
        $this->assertSame(1, $log->where('to', $soon->phone_e164)->where('purpose', 'sacco_reminder')->filter(fn ($m) => str_contains($m->body, 'is due'))->count());
        $this->assertSame(1, $log->where('to', $late->phone_e164)->filter(fn ($m) => str_contains($m->body, '1 days overdue'))->count());
        $this->assertSame(0, $log->where('to', $quiet->phone_e164)->count(), 'reminders need consent');
        foreach ($log as $m) {
            $this->assertStringStartsWith('Kyamuhunga', $m->body, 'signed with the SACCO\'s name');
            foreach (['Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge', 'budget-dynamics'] as $family) {
                $this->assertStringNotContainsString($family, $m->body);
            }
        }
        (new LoanJobs())->run($cid);
        $this->assertSame($log->count(), DB::table('message_log')->where('company_id', $cid)->count(), 'once a day');

        $late->forceFill(['messages_opt_out_at' => now()])->save();
        $before = DB::table('message_log')->where('to', $late->phone_e164)->count();
        LoanNotices::toMember($cid, (int) $late->id, 'loan_closed', ['loan' => 'LN-X']);
        $this->assertSame($before, DB::table('message_log')->where('to', $late->phone_e164)->count(), 'nothing to a member who opted out');
        foreach (LoanNotices::TEMPLATES as $key => $t) {
            $this->assertStringStartsWith('{sacco}:', $t, $key);
            $this->assertLessThanOrEqual(160, mb_strlen(LoanNotices::text($key, ['sacco' => 'Kyamuhunga Teachers', 'amount' => 'UGX 1,500,000', 'loan' => 'LN-2026-000123',
                'loan_amount' => 'UGX 3,000,000', 'borrower' => 'Nakato', 'link' => 'https://akiba-ledger.solaviatech.com/sacco/consent/'.str_repeat('a', 32), 'days' => 30, 'cost' => 'UGX 360,000',
                'channel' => 'your savings', 'due' => 'UGX 280,000', 'date' => '2026-11-30', 'cooling' => 5, 'outstanding' => 'UGX 1,200,000', 'receipt' => 'RCT-2026-000123',
                'arrears' => 'UGX 560,000'])), $key.' fits one SMS');
        }
    }
}
