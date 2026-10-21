<?php

namespace Tests\Feature\Sacco\Simple;

use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Simple\LoanFlow;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/** Flow 5: interest from the SACCO's settings, one instalment a month, repayments to penalty → interest → principal, closing at zero. */
class RepaymentFlowTest extends ApiTestCase
{
    use MakesSimpleSacco;

    private function paidOut(\App\Models\Company $company, \App\Models\User $owner, int $amount, int $months): array
    {
        $a = $this->saver($company, $owner);
        $g = $this->saver($company, $owner);
        $this->saver($company, $owner, 10_000_000);
        $flow = new LoanFlow();
        $loan = $flow->request($company, $a, ['amount_minor' => $amount, 'months' => $months, 'guarantor_member_id' => $g->id]);
        $flow->guarantorDecide($company, $g, (int) $loan->id, 'approve');
        $flow->chairDecide($company, $owner, (int) $loan->id, 'approve');

        return [$flow->payOut($company, $owner, (int) $loan->id, ['method' => 'cash']), $a];
    }

    public function test_interest_settings_instalments_allocation_and_closing(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $cid = (int) $company->id;
        $h = $this->staffHeaders($owner);
        $this->getJson('/api/v1/sacco/loan-settings', $h)->assertOk()->assertJsonPath('data.rate_bp', 100)->assertJsonPath('data.method', 'reducing');
        $this->putJson('/api/v1/sacco/loan-settings', ['rate_bp' => 200, 'method' => 'flat'], $h)->assertOk()->assertJsonPath('data.method', 'flat');
        $flat = (new LoanFlow())->quote($cid, 1_000_000, 4);
        $this->assertSame([20_000, 20_000, 20_000, 20_000], array_column($flat['schedule'], 'interest_minor'), 'flat: on the amount borrowed');
        $this->putJson('/api/v1/sacco/loan-settings', ['rate_bp' => 100, 'method' => 'reducing'], $h)->assertOk();

        [$loan] = $this->paidOut($company, $owner, 1_000_000, 4);
        $id = (int) $loan->id;
        $first = (new LoanFlow())->present($loan->fresh())['schedule'][0];
        $this->assertSame(10_000, $first['interest_minor']);

        $this->travelTo(now()->addMonthNoOverflow()->addDays(5));
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $r = $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/repayments', ['amount_minor' => 50_000, 'method' => 'cash'], $this->staffHeaders($teller))->assertCreated();
        $this->assertSame([10_000, 40_000], [$r->json('data.repayment.interest_minor'), $r->json('data.repayment.principal_minor')], 'interest first, then principal');
        $pos = $r->json('data.loan.position');
        $this->assertSame(960_000, $pos['principal_minor']);
        $this->assertGreaterThan(0, $pos['due_now_minor']);
        $this->assertGreaterThan(0, $pos['days_overdue']);
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/repayments', ['amount_minor' => 1.5], $this->staffHeaders($teller))->assertStatus(422);

        $owed = (int) $pos['outstanding_minor'];
        $quote = (new \App\Services\Sacco\Loans\Repayments())->quote($cid, $id);
        $end = $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/repayments', ['amount_minor' => (int) $quote['total_minor'], 'method' => 'cash'], $this->staffHeaders($teller))->assertCreated();
        $this->assertGreaterThanOrEqual($owed, (int) $quote['total_minor']);
        $this->assertSame('closed', $end->json('data.loan.status'));
        $this->assertSame(0, $end->json('data.loan.position.outstanding_minor'));
        $this->assertSame('closed', array_column($end->json('data.loan.steps'), 'step')[count($end->json('data.loan.steps')) - 1]);
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/repayments', ['amount_minor' => 1000], $this->staffHeaders($teller))->assertStatus(422);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame('closed', LoanBooks::find($cid, $id)->status);
    }
}
