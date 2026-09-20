<?php

namespace Tests\Feature;

use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\HandoverRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * HandoverRecord had no CompanyScope at all (unlike its four Budget-module
 * siblings), so the admin grid showed every company's handover records to
 * any logged-in admin. This exercises the fix directly against the model,
 * the same way CompanyScope is applied for every other budget model.
 */
class HandoverRecordTenantScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_handover_records_are_scoped_to_the_authenticated_users_company(): void
    {
        $userA = new User();
        $userA->name = 'User A';
        $userA->email = 'usera_'.uniqid('', true).'@example.com';
        $userA->password = bcrypt('secret123');
        $userA->status = 'Active';
        $userA->save();

        $userB = new User();
        $userB->name = 'User B';
        $userB->email = 'userb_'.uniqid('', true).'@example.com';
        $userB->password = bcrypt('secret123');
        $userB->status = 'Active';
        $userB->save();

        $companyA = new Company();
        $companyA->owner_id = $userA->id;
        $companyA->name = 'Company A '.uniqid();
        $companyA->status = 'Active';
        $companyA->save();

        $companyB = new Company();
        $companyB->owner_id = $userB->id;
        $companyB->name = 'Company B '.uniqid();
        $companyB->status = 'Active';
        $companyB->save();

        $userA->company_id = $companyA->id;
        $userA->save();

        $programA = new BudgetProgram();
        $programA->company_id = $companyA->id;
        $programA->name = 'Program A '.uniqid();
        $programA->save();

        $programB = new BudgetProgram();
        $programB->company_id = $companyB->id;
        $programB->name = 'Program B '.uniqid();
        $programB->save();

        HandoverRecord::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'budget_program_id' => $programA->id,
            'details' => 'A hands over to A2',
            'to_approved' => 'No',
            'amount' => 1000,
        ]);
        HandoverRecord::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'budget_program_id' => $programB->id,
            'details' => 'B hands over to B2',
            'to_approved' => 'No',
            'amount' => 2000,
        ]);

        Auth::login($userA);

        $visible = HandoverRecord::all();

        $this->assertCount(1, $visible);
        $this->assertSame($companyA->id, $visible->first()->company_id);
    }
}
