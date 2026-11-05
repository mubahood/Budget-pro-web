<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalEntry;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\RentalsModule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A landlord's limit on repairs (LC-17, EL-28): above and below the limit, and none set; a yes covers what they were asked about and only theirs; the office may
 * pay above it with a reason and never blocks; the money is as it always was; a landlord answers only their own; staff answers are audited; a caretaker has
 * none of the landlord's terms; nothing is sent; a closed month still refuses the money.
 */
class RentalsRepairLimitTest extends ApiTestCase
{
    use MakesRentals;

    private int $cid;

    private User $owner;

    private RentalLandlord $landlord;

    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $this->owner] = $this->rentalsAccount();
        $this->cid = (int) $company->id;
        [$property] = $this->building($this->owner, 2, 300000);
        $this->propertyId = (int) $property->id;
        $this->landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id);
        app(CashBook::class)->opening($this->owner, 'cash', 5000000, Carbon::parse('2026-03-01'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function limit(?int $minor): void
    {
        $this->landlord = app(Landlords::class)->update($this->owner, $this->landlord, ['expense_limit_minor' => $minor]);
    }

    private function ticket(string $title = 'Roof leaking', array $extra = []): RentalMaintenance
    {
        return app(Maintenance::class)->open($this->owner, $this->cid, $extra + ['property_id' => $this->propertyId, 'title' => $title]);
    }

    private function refused(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode());

            return;
        }
        $this->fail("Expected {$code}.");
    }

    private function expense(int $amount, array $extra = []): RentalExpense
    {
        return app(Expenses::class)->record($this->owner, $extra + ['property_id' => $this->propertyId, 'category' => 'Repairs', 'amount_minor' => $amount, 'method' => 'cash']);
    }

    private function signIn(RentalLandlord $l): RentalLogin
    {
        return app(RentalLogins::class)->enable($this->owner, 'landlord', (int) $l->id, 'l'.uniqid().'@example.test');
    }

    public function test_the_limit_is_on_the_landlord_and_blank_means_none(): void
    {
        $this->assertNull($this->landlord->expense_limit_minor);
        $this->limit(100000);
        $this->assertSame(100000, $this->landlord->expense_limit_minor);
        $this->refused(fn () => app(Landlords::class)->update($this->owner, $this->landlord, ['expense_limit_minor' => -1]), 'invalid');
        $this->limit(null);
        $this->assertNull($this->landlord->expense_limit_minor);
    }

    public function test_below_at_and_above_the_limit_and_with_no_limit(): void
    {
        $c = fn (int $amount) => LandlordApprovals::check($this->cid, $this->propertyId, $amount);
        $this->assertFalse($c(10000000)['above_limit'], 'no limit');
        $this->assertFalse($c(10000000)['needs_reason']);

        $this->limit(100000);
        $this->assertFalse($c(99999)['needs_reason']);
        $this->assertFalse($c(100000)['needs_reason'], 'at the limit is not above it');
        $this->assertTrue($c(100001)['needs_reason']);
        $this->assertSame($this->landlord->name, $c(100001)['landlord']);
        $this->assertSame(-100001, $c(100001)['balance_after_minor'], 'what it does to their balance');

        $this->limit(0);
        $this->assertTrue($c(1)['needs_reason'], 'a limit of nothing asks about everything');
    }

    public function test_paying_above_it_needs_a_reason_and_is_never_blocked_and_the_money_is_as_ever(): void
    {
        $this->limit(100000);
        $before = (int) DB::table('rental_entries')->where('company_id', $this->cid)->count();
        $this->refused(fn () => $this->expense(150000), 'over_limit_reason_required');
        $this->refused(fn () => $this->expense(150000, ['over_limit_reason' => '   ']), 'over_limit_reason_required');
        $this->assertSame($before, (int) DB::table('rental_entries')->where('company_id', $this->cid)->count(), 'nothing posted');
        $this->assertSame(0, RentalExpense::withoutGlobalScopes()->where('company_id', $this->cid)->count());

        $e = $this->expense(150000, ['over_limit_reason' => 'Burst pipe, could not wait']);
        $this->assertSame('Burst pipe, could not wait', $e->over_limit_reason);
        $row = RentalEntry::withoutGlobalScopes()->where('company_id', $this->cid)->where('expense_id', $e->id)->firstOrFail();
        $this->assertSame([-150000, -150000], [$row->cash_minor, $row->landlord_minor], 'cash −, landlord −, unchanged');
        $this->assertSame(-150000, LandlordAccounts::balance($this->landlord));

        $this->assertNull($this->expense(100000, ['over_limit_reason' => 'Not needed'])->over_limit_reason, 'below it, no reason is kept');
        $this->assertNull($this->expense(900000, ['charged_to' => 'agency', 'property_id' => null, 'category' => 'Agency office'])->over_limit_reason, 'the agency\'s own has no limit');
        $this->assertBooksAgree($this->cid);
    }

    public function test_a_yes_covers_what_they_were_asked_about_and_nothing_more(): void
    {
        $this->limit(100000);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket(), 300000);
        $this->assertTrue(LandlordApprovals::needsAnswer($m));
        $m = app(LandlordApprovals::class)->record($this->owner, $m, true, 'phone', 'Mr Kato', 'Go ahead');
        $this->assertFalse(LandlordApprovals::needsAnswer($m));
        $this->assertSame('Mr Kato: Go ahead', $m->approval_note);
        $this->assertSame(['yes', 'phone'], [$m->approval_status, $m->approval_via]);
        $this->assertSame((int) $this->owner->id, (int) $m->approval_by_id);
        $this->assertSame(1, DB::table('rental_activities')->where('company_id', $this->cid)->where('action', 'maintenance.approval_recorded')->count(), 'audited');

        // Above what they said yes to: no longer covered.
        $this->assertFalse(LandlordApprovals::check($this->cid, $this->propertyId, 300000, (int) $m->id)['needs_reason']);
        $this->assertTrue(LandlordApprovals::check($this->cid, $this->propertyId, 300001, (int) $m->id)['needs_reason']);

        // Their yes on this repair says nothing about another expense.
        $this->assertTrue(LandlordApprovals::check($this->cid, $this->propertyId, 300000)['needs_reason']);

        // Closing the repair for what they agreed needs no reason, and posts only then.
        $this->assertSame(0, RentalExpense::withoutGlobalScopes()->where('company_id', $this->cid)->count(), 'nothing posts until the expense does');
        $done = app(Maintenance::class)->complete($this->owner, $m, ['cost_minor' => 300000]);
        $this->assertSame('done', $done->status);
        $e = RentalExpense::withoutGlobalScopes()->findOrFail($done->expense_id);
        $this->assertNull($e->over_limit_reason);
        $this->assertBooksAgree($this->cid);
    }

    public function test_a_new_figure_is_a_new_question(): void
    {
        $this->limit(100000);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket(), 300000);
        $m = app(LandlordApprovals::class)->record($this->owner, $m, true, 'phone', 'Mr Kato');
        $same = app(Maintenance::class)->quote($this->owner, $m, 300000);
        $this->assertSame('yes', $same->approval_status, 'the same figure keeps the answer');

        $new = app(Maintenance::class)->quote($this->owner, $same, 400000);
        $this->assertSame(['none', null, null, null], [$new->approval_status, $new->approval_via, $new->approval_note, $new->approval_at]);
        $this->assertTrue(LandlordApprovals::needsAnswer($new));
        $off = app(Maintenance::class)->quote($this->owner, $new, null);
        $this->assertNull($off->quote_minor);
        $this->assertFalse(LandlordApprovals::needsAnswer($off));
        $this->refused(fn () => app(LandlordApprovals::class)->record($this->owner, $off, true, 'phone', 'Mr Kato'), 'nothing_to_ask');
    }

    public function test_closing_a_repair_above_the_limit_with_a_no_or_no_answer_needs_a_reason(): void
    {
        $this->limit(100000);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket(), 300000);
        $this->refused(fn () => app(Maintenance::class)->complete($this->owner, $m, ['cost_minor' => 300000]), 'over_limit_reason_required');
        $this->assertSame('open', $m->fresh()->status, 'the repair stays open');
        $m = app(LandlordApprovals::class)->record($this->owner, $m, false, 'phone', 'Mr Kato', 'Too much');
        $this->refused(fn () => app(Maintenance::class)->complete($this->owner, $m, ['cost_minor' => 300000]), 'over_limit_reason_required');
        $done = app(Maintenance::class)->complete($this->owner, $m, ['cost_minor' => 300000, 'over_limit_reason' => 'Tenant had no water']);
        $this->assertSame('Tenant had no water', RentalExpense::withoutGlobalScopes()->findOrFail($done->expense_id)->over_limit_reason);
        $this->assertBooksAgree($this->cid);
    }

    public function test_a_landlord_answers_only_their_own_on_their_own_page(): void
    {
        $this->limit(100000);
        $login = $this->signIn($this->landlord);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket('Roof leaking', ['details' => 'Over the kitchen']), 300000);
        $me = PersonView::for($login);
        $this->assertSame([], LandlordApprovals::waiting($me), 'not until the office asks');
        $this->refused(fn () => app(LandlordApprovals::class)->answer($login, (int) $m->id, true), 'not_waiting');

        $m = app(LandlordApprovals::class)->ask($this->owner, $m);
        $this->assertSame('asked', $m->approval_status);
        $waiting = LandlordApprovals::waiting($me);
        $this->assertSame('Roof leaking', $waiting[0]['title']);
        $this->assertSame('Over the kitchen', $waiting[0]['details']);
        $this->assertSame(300000, $waiting[0]['quote_minor']);
        $this->assertArrayNotHasKey('assigned_to', $waiting[0]);

        // Someone else's landlord, a tenant and another account find nothing.
        [$otherProperty] = $this->building($this->owner, 1);
        $other = $this->signIn(RentalLandlord::withoutGlobalScopes()->findOrFail($otherProperty->landlord_id));
        $this->assertSame([], LandlordApprovals::waiting(PersonView::for($other)));
        $this->refused(fn () => app(LandlordApprovals::class)->answer($other, (int) $m->id, true), 'not_found');
        $tenantLogin = new RentalLogin(['company_id' => $this->cid, 'subject_type' => 'tenant', 'subject_id' => 1]);
        $this->refused(fn () => app(LandlordApprovals::class)->answer($tenantLogin, (int) $m->id, true), 'not_found');
        $this->assertSame('asked', $m->fresh()->approval_status);

        $this->refused(fn () => app(LandlordApprovals::class)->answer($login, (int) $m->id, false, '  '), 'note_required');
        $no = app(LandlordApprovals::class)->answer($login, (int) $m->id, false, 'Get a second quote');
        $this->assertSame(['no', 'portal', 'Get a second quote'], [$no->approval_status, $no->approval_via, $no->approval_note]);
        $this->assertSame((int) $this->landlord->id, (int) $no->approval_landlord_id);
        $this->assertSame([], LandlordApprovals::waiting($me));
        $this->refused(fn () => app(LandlordApprovals::class)->answer($login, (int) $m->id, true), 'not_waiting');

        // Asked again, this time a yes; and it reads on their repairs and expenses.
        $again = app(LandlordApprovals::class)->ask($this->owner, $no);
        $yes = app(LandlordApprovals::class)->answer($login, (int) $again->id, true);
        $this->assertSame('yes', $yes->approval_status);
        $this->assertSame('You said yes on 5 Mar 2026', LandlordApprovals::answersFor($me, [['id' => $yes->id]])[$yes->id]['word']);
        $done = app(Maintenance::class)->complete($this->owner, $yes, ['cost_minor' => 300000]);
        $this->assertSame([(int) $done->expense_id => 'You agreed on 5 Mar 2026'], LandlordApprovals::expenseWords($me, [['id' => (int) $done->expense_id]]));
        $this->assertBooksAgree($this->cid);
    }

    public function test_the_landlord_reads_the_offices_reason(): void
    {
        $this->limit(100000);
        $login = $this->signIn($this->landlord);
        $e = $this->expense(150000, ['over_limit_reason' => 'Burst pipe']);
        $below = $this->expense(50000);
        $words = LandlordApprovals::expenseWords(PersonView::for($login), [['id' => $e->id], ['id' => $below->id]]);
        $this->assertSame([(int) $e->id => 'Above your limit: the office\'s reason: Burst pipe'], $words);
    }

    public function test_a_yes_stays_with_who_gave_it_after_a_handover(): void
    {
        $this->limit(100000);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket(), 300000);
        $m = app(LandlordApprovals::class)->record($this->owner, $m, true, 'phone', 'Mr Kato');
        $new = app(Landlords::class)->create($this->owner, ['name' => 'New Landlord', 'phone' => '0772555666', 'expense_limit_minor' => 100000]);
        DB::table('rental_properties')->where('id', $this->propertyId)->update(['landlord_id' => $new->id]);
        $this->assertTrue(LandlordApprovals::check($this->cid, $this->propertyId, 300000, (int) $m->id)['needs_reason'], 'the old landlord\'s yes is not the new one\'s');
    }

    public function test_staff_recording_needs_how_and_who_and_only_the_office_may(): void
    {
        $this->limit(100000);
        $company = Company::withoutGlobalScopes()->findOrFail($this->cid);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket(), 300000);
        $this->refused(fn () => app(LandlordApprovals::class)->record($this->owner, $m, true, 'pigeon', 'Mr Kato'), 'via_invalid');
        $this->refused(fn () => app(LandlordApprovals::class)->record($this->owner, $m, true, 'portal', 'Mr Kato'), 'via_invalid');
        $this->refused(fn () => app(LandlordApprovals::class)->record($this->owner, $m, true, 'phone', '  '), 'who_required');

        // A caretaker may quote, in their properties, and neither ask nor record.
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        DB::table('rental_property_staff')->insert(['company_id' => $this->cid, 'property_id' => $this->propertyId, 'user_id' => $caretaker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(250000, app(Maintenance::class)->quote($caretaker, $m, 250000)->quote_minor);
        $this->refused(fn () => app(LandlordApprovals::class)->ask($caretaker, $m->fresh()), 'forbidden');
        $this->refused(fn () => app(LandlordApprovals::class)->record($caretaker, $m->fresh(), true, 'phone', 'Mr Kato'), 'forbidden');
        $this->refused(fn () => LandlordApprovals::message($caretaker, $m->fresh(), null), 'forbidden');

        // Even when their role is given the landlords' permission (EL-20).
        DB::table('company_role_permissions')->insert(['company_id' => $this->cid, 'role' => 'rentals_caretaker', 'permission' => 'rentals_landlords', 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        \App\Services\Team\Permissions::flush();
        $this->refused(fn () => app(LandlordApprovals::class)->ask($caretaker, $m->fresh()), 'forbidden');

        // A caretaker elsewhere does not find the repair, and neither does another account.
        $elsewhere = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->refused(fn () => app(Maintenance::class)->quote($elsewhere, $m->fresh(), 100), 'not_found');
        [, $stranger] = $this->rentalsAccount();
        $this->refused(fn () => app(LandlordApprovals::class)->ask($stranger, $m->fresh()), 'forbidden');
    }

    public function test_an_owner_run_account_has_no_landlord_to_ask(): void
    {
        [$company, $owner] = $this->rentalsAccount('owner', ['commission_type' => 'none']);
        $cid = (int) $company->id;
        [$property] = $this->building($owner, 1);
        RentalsModule::selfLandlord($cid)->forceFill(['expense_limit_minor' => 1])->save();
        app(CashBook::class)->opening($owner, 'cash', 500000, Carbon::parse('2026-03-01'));
        $this->assertFalse(LandlordApprovals::check($cid, (int) $property->id, 100000)['needs_reason'], 'even with a limit on the self landlord');
        $e = app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'amount_minor' => 100000, 'method' => 'cash']);
        $this->assertNull($e->over_limit_reason);
        $m = app(Maintenance::class)->open($owner, $cid, ['property_id' => $property->id, 'title' => 'Tap']);
        $m = app(Maintenance::class)->quote($owner, $m, 100000);
        $this->assertFalse(LandlordApprovals::needsAnswer($m));
        $this->refused(fn () => app(LandlordApprovals::class)->ask($owner, $m), 'agency_only');
        $this->refused(fn () => app(LandlordApprovals::class)->record($owner, $m, true, 'phone', 'Me'), 'agency_only');
        $this->assertBooksAgree($cid);
    }

    public function test_the_message_is_prepared_and_never_sent(): void
    {
        Http::fake();
        $this->limit(100000);
        $m = app(Maintenance::class)->quote($this->owner, $this->ticket('Roof leaking'), 300000);
        $before = (int) DB::table('message_log')->count();
        $offer = LandlordApprovals::message($this->owner, $m, 'https://example.test/my/repairs');
        $this->assertStringContainsString('Roof leaking', $offer['text']);
        $this->assertStringContainsString('UGX 300,000', $offer['text']);
        $this->assertStringContainsString($this->landlord->name, $offer['text']);
        $this->assertStringContainsString('Please reply', $offer['text'], 'no sign-in: no link that goes nowhere');
        $this->assertStringNotContainsString('example.test', $offer['text']);
        $this->assertFalse($offer['has_sign_in']);
        $this->assertStringStartsWith('https://wa.me/', (string) $offer['whatsapp']);
        $this->assertStringStartsWith('sms:', (string) $offer['sms']);
        $this->assertSame($before, (int) DB::table('message_log')->count(), 'preparing writes nothing');

        $this->signIn($this->landlord);
        $linked = LandlordApprovals::message($this->owner, $m, 'https://example.test/my/repairs');
        $this->assertTrue($linked['has_sign_in']);
        $this->assertStringContainsString('Answer here: https://example.test/my/repairs', $linked['text']);

        $this->assertContains('approval', RentalMessages::KINDS);
        $id = RentalMessages::recordPrepared($this->cid, 'whatsapp', $linked['phone'], $linked['text'], 'rentals_approval', (int) $this->owner->id);
        $this->assertSame('prepared', DB::table('message_log')->where('id', $id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_the_api_can_give_the_reason_too(): void
    {
        $this->limit(100000);
        $this->owner->tokens()->delete();
        $h = ['Authorization' => 'Bearer '.$this->owner->createToken('test', ['*'])->plainTextToken];
        $body = ['property_id' => $this->propertyId, 'category' => 'Repairs', 'amount_minor' => 150000, 'method' => 'cash'];
        $this->postJson('/api/v1/rentals/expenses', $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'over_limit_reason_required');
        $id = $this->postJson('/api/v1/rentals/expenses', $body + ['over_limit_reason' => 'Burst pipe'], $h)->assertCreated()->json('data.id');
        $this->assertSame('Burst pipe', RentalExpense::withoutGlobalScopes()->findOrFail($id)->over_limit_reason);

        $m = $this->ticket();
        $this->putJson('/api/v1/rentals/maintenance/'.$m->id, ['status' => 'done', 'cost_minor' => 150000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'over_limit_reason_required');
        $this->putJson('/api/v1/rentals/maintenance/'.$m->id, ['status' => 'done', 'cost_minor' => 150000, 'over_limit_reason' => 'Flooding'], $h)->assertOk();
        $this->assertSame('done', $m->fresh()->status);
        $this->assertBooksAgree($this->cid);
    }

    public function test_a_closed_month_refuses_the_expense_and_the_repair_stays_open(): void
    {
        $this->limit(100000);
        Carbon::setTestNow('2026-04-02 09:00:00');
        RentalsModule::settings($this->cid)->forceFill(['closed_through' => '2026-03-31'])->save();
        $m = $this->ticket();
        $this->refused(fn () => $this->expense(50000, ['paid_on' => '2026-03-20']), 'period_closed');
        $this->refused(fn () => app(Maintenance::class)->complete($this->owner, $m, ['cost_minor' => 50000, 'paid_on' => '2026-03-20']), 'period_closed');
        $this->assertSame('open', $m->fresh()->status);
        $this->assertSame(0, RentalExpense::withoutGlobalScopes()->where('company_id', $this->cid)->count(), 'nothing written');
        $this->assertSame(50000, (int) $this->expense(50000, ['paid_on' => '2026-04-02'])->amount_minor);
        $this->assertBooksAgree($this->cid);
    }
}
