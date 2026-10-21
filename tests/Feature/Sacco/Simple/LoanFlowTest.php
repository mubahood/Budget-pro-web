<?php

namespace Tests\Feature\Sacco\Simple;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoLoanStep;
use App\Models\SaccoMemberNotice;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Simple\LoanFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/**
 * Flow 4: a member asks (cost and schedule first, one guarantor), the guarantor approves, the chairperson approves, the
 * treasurer pays out once; and every decline. Each step is logged and tells the next person.
 */
class LoanFlowTest extends ApiTestCase
{
    use MakesSimpleSacco;

    public function test_request_guarantor_chair_and_payout_end_to_end(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $cid = (int) $company->id;
        $borrower = $this->saver($company, $owner, 0, 'Bena');
        $guarantor = $this->saver($company, $owner, 0, 'Grace');
        $this->saver($company, $owner, 5_000_000, 'Depositor'); // cash in the SACCO to lend
        $chair = $this->saccoStaff($company, 'sacco_chair');
        $treasurer = $this->saccoStaff($company, 'sacco_treasurer');
        $mb = $this->memberHeaders($company, $borrower, $owner);
        $mg = $this->memberHeaders($company, $guarantor, $owner);

        $q = $this->postJson('/api/v1/sacco/me/loan-quote', ['amount_minor' => 1_200_000, 'months' => 12], $mb)->assertOk()->json('data');
        $this->assertSame([100, 'month', 'reducing'], [$q['rate_bp'], $q['rate_period'], $q['method']]);
        $this->assertCount(12, $q['schedule']);
        $this->assertSame(1_200_000, array_sum(array_column($q['schedule'], 'principal_minor')));
        $this->assertSame(0, end($q['schedule'])['balance_minor']);
        $this->assertSame($q['total_payable_minor'], 1_200_000 + $q['total_interest_minor']);
        $this->assertSame(12_000, $q['schedule'][0]['interest_minor'], '1 % of the balance');

        $opts = collect($this->getJson('/api/v1/sacco/me/guarantor-options', $mb)->assertOk()->json('data'))->pluck('member_id');
        $this->assertFalse($opts->contains($borrower->id));
        $ask = ['amount_minor' => 1_200_000, 'months' => 12, 'purpose' => 'School fees', 'guarantor_member_id' => $borrower->id];
        $this->postJson('/api/v1/sacco/me/loan-requests', $ask, $mb)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_guarantor');
        $uuid = (string) Str::uuid();
        $r = $this->postJson('/api/v1/sacco/me/loan-requests', ['uuid' => $uuid, 'guarantor_member_id' => $guarantor->id] + $ask, $mb)->assertCreated()
            ->assertJsonPath('data.status', 'pending_guarantor');
        $id = (int) $r->json('data.id');
        $this->postJson('/api/v1/sacco/me/loan-requests', ['uuid' => $uuid, 'guarantor_member_id' => $guarantor->id] + $ask, $mb)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/sacco/me/loan-requests', ['guarantor_member_id' => $guarantor->id] + $ask, $mb)->assertStatus(422)->assertJsonPath('errors.code', 'request_pending');
        $this->assertSame(1, SaccoMemberNotice::where('member_id', $guarantor->id)->where('kind', 'guarantor_ask')->count());

        // The guarantor sees who, how much, how long and the schedule, and approves.
        $g = $this->getJson('/api/v1/sacco/me/guarantor-requests', $mg)->assertOk()->json('data.0');
        $this->assertSame([$id, true, 12], [$g['id'], $g['can_decide'], count($g['schedule'])]);
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/chair', ['decision' => 'approve'], $this->staffHeaders($chair))->assertStatus(422); // not yet
        $this->postJson('/api/v1/sacco/me/guarantor-requests/'.$id, ['decision' => 'approve', 'note' => 'I know her well'], $mg)->assertOk()->assertJsonPath('data.status', 'pending_chair');
        $this->postJson('/api/v1/sacco/me/guarantor-requests/'.$id, ['decision' => 'approve'], $mg)->assertStatus(422);
        $this->assertTrue(DB::table('app_notifications')->where('company_id', $cid)->where('user_id', $chair->id)->where('type', 'sacco_loans')->exists(), 'the chair is told');

        // Only the chairperson approves.
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/chair', ['decision' => 'approve'], $this->staffHeaders($treasurer))->assertStatus(403);
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/chair', ['decision' => 'approve', 'note' => 'OK'], $this->staffHeaders($chair))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $treasurer->id)->where('type', 'sacco_loans')->exists(), 'the treasurer is told');

        // The treasurer pays it out, once.
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/pay-out', ['method' => 'bank'], $this->staffHeaders($treasurer))->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_funds');
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/pay-out', ['method' => 'cash'], $this->staffHeaders($chair))->assertStatus(403);
        $p = $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/pay-out', ['method' => 'cash', 'notes' => 'Handed over at the office'], $this->staffHeaders($treasurer))->assertOk()
            ->assertJsonPath('data.status', 'disbursed')->assertJsonPath('data.position.outstanding_minor', 1_200_000);
        $this->postJson('/api/v1/sacco/loan-requests/'.$id.'/pay-out', ['method' => 'cash'], $this->staffHeaders($treasurer))->assertOk()->assertJsonPath('data.status', 'disbursed');
        $this->assertSame(1, SaccoJournalEntry::where('company_id', $cid)->where('kind', 'loan_disbursement')->count());
        $this->assertSame(12, SaccoLoanScheduleLine::where('loan_id', $id)->count());
        $this->assertSame(['requested', 'guarantor_approved', 'chair_approved', 'paid_out'], SaccoLoanStep::where('loan_id', $id)->orderBy('id')->pluck('step')->all());
        $steps = $p->json('data.steps');
        $this->assertSame(['I know her well', 'Grace'], [$steps[1]['note'], explode(' ', $steps[1]['by'])[0]]);
        $this->assertTrue(SaccoMemberNotice::where('member_id', $borrower->id)->where('kind', 'loan_paid_out')->exists());
        $this->assertGreaterThan(0, DB::table('message_log')->where('company_id', $cid)->where('to', $guarantor->phone_e164)->count(), 'SMS/WhatsApp to the guarantor');
        $this->getJson('/api/v1/sacco/me/loan-requests/'.$id, $mb)->assertOk()->assertJsonPath('data.status', 'disbursed');
        $this->getJson('/api/v1/sacco/loan-requests?status=disbursed', $this->staffHeaders($chair))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_every_decline_and_the_not_paid_out_end(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $a = $this->saver($company, $owner);
        $g = $this->saver($company, $owner);
        $flow = new LoanFlow();
        $ask = fn () => $flow->request($company, $a->fresh(), ['amount_minor' => 300_000, 'months' => 3, 'guarantor_member_id' => $g->id]);

        $l1 = $ask();
        $this->assertSame('declined_by_guarantor', $flow->guarantorDecide($company, $g, (int) $l1->id, 'decline', 'Not this time')->status);
        $this->assertSame('loan_not_found', $this->errorCode(fn () => $flow->guarantorDecide($company, $a, (int) $l1->id, 'approve')));

        $l2 = $ask();
        $flow->guarantorDecide($company, $g, (int) $l2->id, 'approve');
        $this->assertSame('declined_by_chair', $flow->chairDecide($company, $owner, (int) $l2->id, 'decline', 'Too soon')->status);
        $this->assertSame('invalid_loan_status', $this->errorCode(fn () => $flow->payOut($company, $owner, (int) $l2->id, ['method' => 'cash'])));

        $l3 = $ask();
        $flow->guarantorDecide($company, $g, (int) $l3->id, 'approve');
        $flow->chairDecide($company, $owner, (int) $l3->id, 'approve');
        $this->assertSame('reason_required', $this->errorCode(fn () => $flow->notPaidOut($company, $owner, (int) $l3->id, ' ')));
        $l3 = $flow->notPaidOut($company, $owner, (int) $l3->id, 'The member no longer needs it');
        $this->assertSame('not_disbursed', LoanFlow::status($l3));
        $this->assertSame('released', DB::table('sacco_loan_guarantors')->where('loan_id', $l3->id)->value('consent_status'));
        $this->assertSame(['declined_by_guarantor', 'declined_by_chair', 'not_disbursed'], SaccoLoan::where('member_id', $a->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame('member_not_active', $this->errorCode(function () use ($flow, $company, $a, $g) {
            DB::table('sacco_members')->where('id', $a->id)->update(['status' => 'suspended']);
            $flow->request($company, $a->fresh(), ['amount_minor' => 1000, 'months' => 1, 'guarantor_member_id' => $g->id]);
        }));
    }
}
