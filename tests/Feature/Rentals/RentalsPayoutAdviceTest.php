<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalPayout;
use App\Services\Rentals\Landlords\PayoutAdvice;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalLinks;
use App\Support\Rentals\AmountWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/** A payout's advice (LC-10): frozen as it was recorded, stamped when reversed, signature room only for cash, and only for those who may see it. */
class RentalsPayoutAdviceTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A landlord owed 270,000 in cash. @return array{0: \App\Models\Company, 1: \App\Models\User, 2: RentalLandlord, 3: \App\Models\RentalLease} */
    private function paidLandlord(): array
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash']);
        $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($property->landlord_id);

        return [$company, $owner, $landlord, $lease];
    }

    public function test_figures_stay_as_they_were_after_later_changes(): void
    {
        [$company, $owner, $landlord, $lease] = $this->paidLandlord();
        $landlord->forceFill(['payout_details' => 'Stanbic 123456'])->save();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 100000, 'method' => 'cash', 'reference' => 'RCPT-1']);
        $this->assertSame('Stanbic 123456', $p->paid_to_snapshot);
        $this->assertSame(270000, $p->balance_before_minor);
        $this->assertSame(170000, $p->balance_after_minor);
        $first = PayoutAdvice::parts($p);
        $this->assertSame('UGX 100,000', $first['amount']);
        $this->assertSame(AmountWords::of(100000, (int) $company->id), $first['words']);
        $this->assertSame(['Owed to you before', 'UGX 270,000'], $first['before']);
        $this->assertSame(['Owed to you after', 'UGX 170,000'], $first['after']);
        $this->assertSame('RCPT-1', $first['reference']);
        $this->assertSame((string) $owner->name, $first['recorded_by']);

        $landlord->forceFill(['payout_details' => 'Changed bank 999'])->save();
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 50000, 'method' => 'cash']);
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash']);
        $this->assertSame($first, PayoutAdvice::parts($p->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_reversed_advice_is_stamped_and_keeps_saying_so(): void
    {
        [$company, $owner, $landlord] = $this->paidLandlord();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 100000, 'method' => 'cash']);
        $this->assertNull(PayoutAdvice::parts($p)['reversed']);
        app(Payouts::class)->reverse($owner, $p, 'Wrong landlord');
        $parts = PayoutAdvice::parts($p->fresh());
        $this->assertSame('Wrong landlord', $parts['reversed']['reason']);
        $html = view('rentals.payout-advice', ['doc' => ['title' => 'Payout advice', 'head' => ['name' => 'X', 'logo' => null, 'lines' => []], 'sub' => []], 'a' => $parts])->render();
        $this->assertStringContainsString('REVERSED on 5 Mar 2026. Wrong landlord', $html);
        $this->assertStringStartsWith('%PDF', PayoutAdvice::pdf($p->fresh()));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_cash_shows_signatures_and_bank_does_not(): void
    {
        [$company, $owner, $landlord] = $this->paidLandlord();
        app(\App\Services\Rentals\CashBook::class)->transfer($owner, 'cash', 'bank', 100000, '2026-03-05', 'Banked');
        $cash = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash']);
        $bank = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'bank']);
        $draw = fn (RentalPayout $p) => view('rentals.payout-advice', ['doc' => ['title' => 'Payout advice', 'head' => ['name' => 'X', 'logo' => null, 'lines' => []], 'sub' => []], 'a' => PayoutAdvice::parts($p)])->render();
        $this->assertStringContainsString('Received by the landlord', $draw($cash));
        $this->assertStringNotContainsString('Received by the landlord', $draw($bank));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_row_older_than_the_snapshot_renders_without_those_lines(): void
    {
        [, $owner, $landlord] = $this->paidLandlord();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash']);
        DB::table('rental_payouts')->where('id', $p->id)->update(['paid_to_snapshot' => null, 'balance_before_minor' => null, 'balance_after_minor' => null, 'advance_minor' => 0]);
        $parts = PayoutAdvice::parts($p->fresh());
        $this->assertNull($parts['before']);
        $this->assertNull($parts['after']);
        $this->assertNull($parts['paid_to']);
        $this->assertNull($parts['ahead']);
        $html = view('rentals.payout-advice', ['doc' => ['title' => 'Payout advice', 'head' => ['name' => 'X', 'logo' => null, 'lines' => []], 'sub' => []], 'a' => $parts])->render();
        $this->assertStringNotContainsString('Your account', $html);
        $this->assertStringStartsWith('%PDF', PayoutAdvice::pdf($p->fresh()));
    }

    public function test_an_advance_says_how_much_was_paid_ahead(): void
    {
        [$company, $owner, $landlord] = $this->paidLandlord();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 300000, 'method' => 'cash', 'advance' => true, 'advance_reason' => 'School fees', 'advance_others_ok' => true]);
        $parts = PayoutAdvice::parts($p);
        $this->assertSame('UGX 30,000', $parts['ahead']);
        $this->assertSame(['Owed back to us after', 'UGX 30,000'], $parts['after']);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_signed_link_opens_only_for_who_may_see_it(): void
    {
        [$company, $owner, $landlord] = $this->paidLandlord();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash']);

        $staff = RentalLinks::payoutAdvicePdf($p, 'staff', (int) $owner->id)['url'];
        $res = $this->get($staff);
        $res->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->assertStringContainsString('payout-advice-', (string) $res->headers->get('Content-Disposition'));

        // Altered: refused. Someone without the permission: not there. A caretaker: not there.
        $this->get($staff.'x')->assertStatus(403);
        $viewer = $this->rentalsStaff($company, 'viewer');
        $this->get(RentalLinks::payoutAdvicePdf($p, 'staff', (int) $viewer->id)['url'])->assertNotFound();

        // The landlord's own sign-in opens theirs, and not another landlord's.
        $mine = $this->login($owner, $landlord, 'mine@example.com');
        $this->get(RentalLinks::payoutAdvicePdf($p, 'portal', (int) $mine->id)['url'])->assertOk();
        [$otherProperty] = $this->building($owner, 1, 100000);
        $other = RentalLandlord::withoutGlobalScopes()->findOrFail($otherProperty->landlord_id);
        $theirs = $this->login($owner, $other, 'theirs@example.com');
        $this->get(RentalLinks::payoutAdvicePdf($p, 'portal', (int) $theirs->id)['url'])->assertNotFound();

        // Another account's staff cannot open it, even signed.
        [, $stranger] = $this->rentalsAccount();
        $this->get(RentalLinks::payoutAdvicePdf($p, 'staff', (int) $stranger->id)['url'])->assertNotFound();
    }

    private function login(\App\Models\User $by, RentalLandlord $landlord, string $email): RentalLogin
    {
        return app(RentalLogins::class)->enable($by, 'landlord', (int) $landlord->id, $email, null, 'Secret-pass-123');
    }

    public function test_its_paper_names_only_the_account(): void
    {
        [$company, $owner, $landlord] = $this->paidLandlord();
        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash']);
        $html = view('rentals.payout-advice', ['doc' => ['title' => 'Payout advice', 'head' => \App\Services\Rentals\Reports\RentalDocument::letterhead((int) $company->id), 'sub' => []], 'a' => PayoutAdvice::parts($p)])->render();
        foreach (['Budget Pro', 'Estate Ledger', 'Shelfie', 'Kuku', 'Pledge', 'Akiba', 'Solavia'] as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $html);
        }
    }
}
