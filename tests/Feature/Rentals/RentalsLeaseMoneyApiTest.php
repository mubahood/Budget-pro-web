<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalEntry;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the phone's lease and money screens send, proved honoured: each body here is the one the phone's own test
 * (estate-ledger app test/rentals/lease_money_test.dart) asserts it sends, key for key, so the two sides cannot agree with themselves only.
 * Move-out posts its refunds, Charge the full period forgives nothing, money goes back under the web's rule, a split is kept as chosen.
 */
class RentalsLeaseMoneyApiTest extends RentalsPhoneApiCase
{
    /** Brian's lease with a deposit of 300,000 paid, 100,000 paid ahead and March settled, on the 16th of a March that runs to the 31st. @return array{0: array<string, mixed>, 1: RentalLease} */
    private function leaving(): array
    {
        $w = $this->world();
        $lease = $this->tenantIn($w['owner'], $w['units'][2], '2026-03-01', ['deposit_minor' => 300000, 'tenant' => ['name' => 'Esther Sample', 'phone' => '0772100009', 'email' => 'esther'.uniqid().'@example.com']]);
        // The deposit, March's rent and 100,000 more, which stays paid ahead.
        app(Payments::class)->receive($w['owner'], $lease, ['amount_minor' => 700000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-16 09:00:00');

        return [$w, $lease->fresh()];
    }

    public function test_move_out_with_refund_now_really_posts_both_refunds_and_answers_the_settlement(): void
    {
        [$w, $lease] = $this->leaving();
        $cid = (int) $w['company']->id;
        $before = RentalLedger::cash($cid)['cash'];
        // Exactly what MoveOutScreen sends for "Refund it now" on both, by cash, with the full period charged.
        $body = ['date' => '2026-03-16', 'readings' => [], 'damages' => [], 'unused_days' => false, 'refund_deposit' => 'cash', 'refund_credit' => 'cash', 'reason' => 'Moved to Jinja'];
        $res = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", $body, $this->h($w['owner']))->assertOk();
        $this->assertSame(['forgiven_minor' => 0, 'deposit_used_minor' => 0, 'deposit_refunded_minor' => 300000, 'deposit_kept_minor' => 0, 'credit_refunded_minor' => 100000, 'credit_kept_minor' => 0, 'still_owed_minor' => 0], $res->json('data.settlement'));
        $this->assertSame('Moved to Jinja', $res->json('data.lease.end_reason'));
        $this->assertSame('ended', $res->json('data.lease.status'));
        $this->assertSame($before - 400000, RentalLedger::cash($cid)['cash'], 'the money left the cash account');
        $this->assertSame(1, RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('type', 'deposit_refund')->count());
        $this->assertSame(1, RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('type', 'credit_refund')->count());
        $this->assertBooksAgree($cid);
    }

    public function test_keeping_both_posts_nothing_and_says_what_is_still_held(): void
    {
        [$w, $lease] = $this->leaving();
        $cid = (int) $w['company']->id;
        $before = RentalLedger::cash($cid)['cash'];
        $body = ['date' => '2026-03-16', 'readings' => [], 'damages' => [], 'unused_days' => false];
        $res = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", $body, $this->h($w['owner']))->assertOk();
        $this->assertSame(300000, $res->json('data.settlement.deposit_kept_minor'));
        $this->assertSame(100000, $res->json('data.settlement.credit_kept_minor'));
        $this->assertSame($before, RentalLedger::cash($cid)['cash']);
        $this->assertBooksAgree($cid);
    }

    public function test_charge_the_full_period_forgives_nothing_and_waive_forgives_the_days_left(): void
    {
        [$w, $lease] = $this->leaving();
        $h = $this->h($w['owner']);
        $charged = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out-preview", ['date' => '2026-03-16', 'unused_days' => false], $h)->assertOk();
        $this->assertSame(0, $charged->json('data.forgiven_minor'));
        $this->assertGreaterThan(0, $charged->json('data.unused_suggested_minor'));
        $waived = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out-preview", ['date' => '2026-03-16', 'unused_days' => true], $h)->assertOk();
        $this->assertSame($charged->json('data.unused_suggested_minor'), $waived->json('data.forgiven_minor'));

        // The word that used to pass as a yes is refused now, and nothing is written.
        foreach (['charge', 'waive'] as $word) {
            $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-16', 'unused_days' => $word], $h)->assertStatus(422)->assertJsonPath('errors.code', 'unused_days_invalid');
        }
        $this->assertSame('active', $lease->fresh()->status);

        $real = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-16', 'readings' => [], 'damages' => [], 'unused_days' => true], $h)->assertOk();
        $this->assertSame($waived->json('data.forgiven_minor'), $real->json('data.settlement.forgiven_minor'));
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_the_preview_says_what_the_move_out_then_does_and_writes_nothing(): void
    {
        [$w, $lease] = $this->leaving();
        $cid = (int) $w['company']->id;
        $h = $this->h($w['owner']);
        $body = ['date' => '2026-03-16', 'readings' => [], 'damages' => [['description' => 'Broken window', 'amount_minor' => 50000, 'goes_to' => 'landlord']], 'unused_days' => false, 'refund_deposit' => 'cash', 'refund_credit' => 'cash'];
        $before = RentalLedger::cash($cid)['cash'];
        $p = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out-preview", $body, $h)->assertOk()->json('data');
        $this->assertSame($before, RentalLedger::cash($cid)['cash']);
        $this->assertSame('active', $lease->fresh()->status);
        $s = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", $body, $h)->assertOk()->json('data.settlement');
        foreach (['forgiven_minor', 'deposit_used_minor', 'deposit_refunded_minor', 'deposit_kept_minor', 'credit_refunded_minor', 'credit_kept_minor', 'still_owed_minor'] as $k) {
            $this->assertSame($s[$k], $p[$k], $k);
        }
        $this->assertSame(50000, $p['damages_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_a_future_move_out_is_refused_in_plain_words(): void
    {
        [$w, $lease] = $this->leaving();
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-17'], $this->h($w['owner']))->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
    }

    public function test_a_refund_with_too_little_cash_refuses_the_whole_move_out_in_words_that_give_the_way_through(): void
    {
        [$w, $lease] = $this->leaving();
        $res = $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-16', 'unused_days' => false, 'refund_deposit' => 'bank'], $this->h($w['owner']))->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->assertStringContainsString('refund later', (string) $res->json('message'));
        $this->assertSame('active', $lease->fresh()->status);
    }

    public function test_money_goes_back_later_under_the_webs_rule_reverse_and_collect(): void
    {
        [$w, $lease] = $this->leaving();
        $cid = (int) $w['company']->id;
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-16', 'readings' => [], 'damages' => [], 'unused_days' => false], $this->h($w['owner']))->assertOk();
        $cash = RentalLedger::cash($cid)['cash'];
        $caretaker = $this->h($w['caretaker']);
        $viewer = $this->h($w['viewer']);
        $accountant = $this->h($w['accountant']);

        // A caretaker receives money but does not pay it out; nor does a viewer.
        foreach ([$caretaker, $viewer] as $h) {
            $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash'], $h)->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_reverse');
            $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash'], $h)->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_reverse');
        }
        $this->assertSame($cash, RentalLedger::cash($cid)['cash']);

        // An accountant may: exactly what the phone's refund sheet sends.
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash', 'amount_minor' => 120000], $accountant)->assertOk()
            ->assertJsonPath('data.deposit_refunded_minor', 120000)->assertJsonPath('data.deposit_held_minor', 180000);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'mobile_money', 'amount_minor' => 100000], $accountant)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash', 'amount_minor' => 100000], $accountant)->assertOk()
            ->assertJsonPath('data.credit_refunded_minor', 100000)->assertJsonPath('data.credit_minor', 0);
        $this->assertSame($cash - 220000, RentalLedger::cash($cid)['cash']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_refunds_list_and_what_is_still_to_go_back(): void
    {
        [$w, $lease] = $this->leaving();
        $owner = $this->h($w['owner']);
        $this->getJson('/api/v1/rentals/refunds/waiting', $owner)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => '2026-03-16', 'readings' => [], 'damages' => [], 'unused_days' => false], $owner)->assertOk();

        $waiting = $this->getJson('/api/v1/rentals/refunds/waiting', $owner)->assertOk()->json('data');
        $this->assertCount(1, $waiting);
        $this->assertSame((int) $lease->id, $waiting[0]['id']);
        $this->assertSame(300000, $waiting[0]['position']['deposit_held_minor']);
        $this->assertSame(100000, $waiting[0]['position']['credit_minor']);
        $this->assertSame('Esther Sample', $waiting[0]['tenant']['name']);

        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-deposit", ['method' => 'cash', 'amount_minor' => 300000], $owner)->assertOk();
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/refund-credit", ['method' => 'cash', 'amount_minor' => 100000], $owner)->assertOk();
        $this->getJson('/api/v1/rentals/refunds/waiting', $owner)->assertOk()->assertJsonCount(0, 'data');

        $rows = $this->getJson('/api/v1/rentals/refunds', $owner)->assertOk()->assertJsonPath('meta.pagination.total', 2)->json('data');
        $byKind = collect($rows)->keyBy('kind');
        $this->assertSame(300000, $byKind['deposit']['amount_minor']);
        $this->assertSame(100000, $byKind['credit']['amount_minor']);
        $this->assertSame('cash', $byKind['credit']['method']);
        $this->assertSame((int) $lease->id, $byKind['deposit']['lease_id']);
        $this->assertSame('2026-03-16', $byKind['deposit']['occurred_on']);
        $this->assertSame('Esther Sample', $byKind['deposit']['tenant_name']);
        $this->getJson('/api/v1/rentals/refunds?q=Nobody', $owner)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/rentals/refunds?from=2026-03-17', $owner)->assertOk()->assertJsonCount(0, 'data');

        // Another account sees none of it.
        $this->getJson('/api/v1/rentals/refunds', $this->stranger())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_caretaker_sees_only_the_refunds_of_their_own_buildings(): void
    {
        $w = $this->world();
        $owner = $this->h($w['owner']);
        $elsewhere = $w['carol']; // Lake View: not the caretaker's
        app(Payments::class)->receive($w['owner'], $elsewhere, ['amount_minor' => 900000, 'method' => 'cash']);
        $this->postJson("/api/v1/rentals/leases/{$elsewhere->id}/move-out", ['date' => '2026-03-26', 'readings' => [], 'damages' => [], 'unused_days' => false, 'refund_credit' => 'cash'], $owner)->assertOk();
        $this->assertGreaterThan(0, $this->getJson('/api/v1/rentals/refunds', $owner)->assertOk()->json('meta.pagination.total'));
        $this->getJson('/api/v1/rentals/refunds', $this->h($w['caretaker']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_renew_takes_a_later_end_or_none(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $id = $w['amina']->id;
        $this->postJson("/api/v1/rentals/leases/{$id}/renew", ['end_date' => '2027-03-31'], $h)->assertOk()->assertJsonPath('data.end_date', '2027-03-31');
        $this->postJson("/api/v1/rentals/leases/{$id}/renew", ['end_date' => null], $h)->assertOk()->assertJsonPath('data.end_date', null);
        $this->postJson("/api/v1/rentals/leases/{$id}/renew", ['end_date' => '2026-01-01'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'end_invalid');
        $this->postJson("/api/v1/rentals/leases/{$id}/renew", ['end_date' => '2027-03-31'], $this->h($w['accountant']))->assertStatus(403);
    }

    public function test_a_rent_change_on_a_billed_day_is_refused_and_the_day_after_what_is_billed_is_taken(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $lease = $w['amina']->fresh();
        $billed = $lease->billed_until->toDateString();
        $this->assertNotNull($billed);
        $res = $this->postJson("/api/v1/rentals/leases/{$lease->id}/rent-change", ['rent_minor' => 350000, 'effective_from' => $billed, 'reason' => 'New year'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'period_billed');
        $this->assertStringContainsString('already billed', (string) $res->json('message'));
        // The phone's default: the day after what is billed.
        $next = $lease->billed_until->copy()->addDay()->toDateString();
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/rent-change", ['rent_minor' => 350000, 'effective_from' => $next, 'reason' => 'New year'], $h)->assertOk();
    }

    public function test_a_payment_split_over_chosen_bills_is_kept_as_chosen_and_a_resend_is_the_same_payment(): void
    {
        $w = $this->world();
        $lease = $w['brian']->fresh();
        $h = $this->h($w['owner']);
        $open = Payments::openCharges($lease, false)->values();
        $this->assertGreaterThanOrEqual(2, $open->count());
        $older = $open[0];
        $newer = $open[1];
        $uuid = (string) Str::uuid();
        // Exactly what the outbox sends for a payment split on the phone: the newer bill first, part of the older one after.
        $body = ['lease_id' => (int) $lease->id, 'amount_minor' => 400000, 'method' => 'cash', 'paid_on' => '2026-03-26', 'uuid' => $uuid, 'device_id' => 'device-1',
            'split' => [['charge_id' => (int) $newer->id, 'amount_minor' => 300000], ['charge_id' => (int) $older->id, 'amount_minor' => 100000]]];
        $this->postJson('/api/v1/rentals/payments', $body, $h)->assertCreated();
        $this->assertSame(300000, (int) $newer->fresh()->paid_minor, 'the newer bill took what was chosen');
        $this->assertSame(100000, (int) $older->fresh()->paid_minor);
        $this->postJson('/api/v1/rentals/payments', $body, $h)->assertOk()->assertJsonPath('message', 'Already received.');
        $this->assertSame(1, RentalPayment::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('uuid', $uuid)->count());

        // The same payment with another split is a conflict, and a split that leaves money on no bill is refused.
        $other = $body;
        $other['split'] = [['charge_id' => (int) $older->id, 'amount_minor' => 200000], ['charge_id' => (int) $newer->id, 'amount_minor' => 0]];
        $this->postJson('/api/v1/rentals/payments', $other, $h)->assertStatus(409);
        $short = ['lease_id' => (int) $lease->id, 'amount_minor' => 50000, 'method' => 'cash', 'paid_on' => '2026-03-26', 'uuid' => (string) Str::uuid(), 'split' => [['charge_id' => (int) $older->id, 'amount_minor' => 10000]]];
        $this->postJson('/api/v1/rentals/payments', $short, $h)->assertStatus(422)->assertJsonPath('errors.code', 'split_total');
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_reference_already_on_a_receipt_or_a_waiting_report_warns_and_never_refuses(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $first = app(Payments::class)->receive($w['owner'], $w['amina'], ['amount_minor' => 1000, 'method' => 'mobile_money', 'reference' => 'MP2611050001', 'paid_on' => '2026-03-26']);
        $url = '/api/v1/rentals/leases/'.$w['brian']->id.'/payment-preview';
        $words = 'Receipt '.$first->number.' of 26 Mar already has this reference. Check it is not the same money.';

        // Amount and reference together, as the phone asks once both are typed.
        $both = $this->getJson($url.'?amount_minor=1000&method=mobile_money&reference=MP2611050001', $h)->assertOk();
        $this->assertSame($words, $both->json('data.reference_warning'));
        $this->assertArrayHasKey('lines', $both->json('data'), 'the rest of the preview still comes');
        // The reference typed first: no amount yet, and the warning still comes, alone.
        $alone = $this->getJson($url.'?method=mobile_money&reference=MP2611050001', $h)->assertOk();
        $this->assertSame(['reference_warning' => $words], $alone->json('data'));
        // Spaces and small letters are the same reference.
        $this->assertSame($words, $this->getJson($url.'?method=mobile_money&reference=%20mp2611050001%20', $h)->assertOk()->json('data.reference_warning'));
        // Cash has none; nor has another reference, nor no reference at all.
        $this->assertNull($this->getJson($url.'?method=cash&reference=MP2611050001', $h)->assertOk()->json('data.reference_warning'));
        $this->assertNull($this->getJson($url.'?method=mobile_money&reference=MP999', $h)->assertOk()->json('data.reference_warning'));
        $this->assertNull($this->getJson($url.'?amount_minor=1000', $h)->assertOk()->json('data.reference_warning'));
        // With neither, the preview still asks for an amount.
        $this->getJson($url, $h)->assertStatus(422);

        // A warning only: the same reference is still taken, exactly as the outbox sends it.
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => (int) $w['brian']->id, 'amount_minor' => 1000, 'method' => 'mobile_money', 'reference' => 'MP2611050001', 'paid_on' => '2026-03-26', 'uuid' => (string) Str::uuid(), 'device_id' => 'device-1'], $h)->assertCreated();

        // A reversed receipt no longer counts.
        foreach (RentalPayment::withoutGlobalScopes()->where('company_id', $w['company']->id)->where('reference', 'MP2611050001')->get() as $p) {
            app(Payments::class)->reverse($w['owner'], $p, 'Typed twice');
        }
        $this->assertNull($this->getJson($url.'?method=mobile_money&reference=MP2611050001', $h)->assertOk()->json('data.reference_warning'));

        // A tenant's report waiting with it does.
        [$person] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 5000, 'method' => 'mobile_money', 'reference' => 'MP2611050002', 'paid_on' => '2026-03-26'], $person)->assertCreated();
        $this->assertStringContainsString('report waiting', (string) $this->getJson($url.'?method=mobile_money&reference=MP2611050002', $h)->assertOk()->json('data.reference_warning'));
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_move_in_takes_the_deposit_holder_the_commission_and_the_anchor(): void
    {
        $w = $this->world();
        Carbon::setTestNow('2026-03-26 09:00:00');
        $h = $this->h($w['owner']);
        $tenant = $this->postJson('/api/v1/rentals/tenants', ['kind' => 'person', 'name' => 'Esther Sample', 'phone' => '0772100009'], $h)->assertCreated()->json('data.id');
        // Exactly what MoveInScreen sends with all three chosen.
        $body = ['unit_id' => (int) $w['units'][2]->id, 'tenant_id' => (int) $tenant, 'start_date' => '2026-03-16', 'rent_minor' => 300000, 'cycle_months' => 1, 'deposit_minor' => 300000,
            'items' => [], 'anchor' => 'first', 'deposit_held_by' => 'landlord', 'commission' => ['type' => 'flat', 'bp' => 0, 'minor' => 25000]];
        $preview = $this->postJson('/api/v1/rentals/leases/move-in-preview', $body, $h)->assertOk()->json('data');
        $this->assertSame(16, $preview['part']['days']);
        $this->assertSame('2026-03-31', $preview['part']['end']);
        $this->assertSame(154839, $preview['part']['suggested_minor']);
        $this->assertSame(300000 + 154839, $preview['total_minor'], 'the deposit and the part month: April is not billed yet, five days before the 1st is the 27th');
        $lease = $this->postJson('/api/v1/rentals/leases', $body, $h)->assertCreated()->json('data');
        $this->assertSame('first', $lease['anchor']);
        $this->assertSame('landlord', $lease['deposit_held_by']);
        $this->assertSame(['type' => 'flat', 'bp' => 0, 'minor' => 25000], $lease['commission']);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_one_lease_commission_changes_from_a_day_after_what_is_billed_and_keeps_its_history(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $lease = $w['amina']->fresh();
        $billed = $lease->billed_until->toDateString();
        $next = $lease->billed_until->copy()->addDay()->toDateString();
        $res = $this->postJson("/api/v1/rentals/leases/{$lease->id}/commission", ['type' => 'percent', 'bp' => 750, 'minor' => 0, 'effective_from' => $billed, 'reason' => 'Agreed'], $h)->assertStatus(422);
        $this->assertSame('period_billed', $res->json('errors.code'));
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/commission", ['type' => 'percent', 'bp' => 750, 'minor' => 0, 'effective_from' => $next, 'reason' => ''], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        // Exactly what the phone's sheet sends.
        $done = $this->postJson("/api/v1/rentals/leases/{$lease->id}/commission", ['type' => 'percent', 'bp' => 750, 'minor' => 0, 'effective_from' => $next, 'reason' => 'Agreed with the landlord'], $h)->assertOk()->json('data');
        $this->assertCount(2, $done['commission_changes'], 'the first change also writes where it started');
        $this->assertSame(['type' => 'percent', 'bp' => 750, 'minor' => 0], collect($done['commission_changes'])->firstWhere('effective_from', $next)['commission']);
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/commission", ['type' => 'none', 'bp' => 0, 'minor' => 0, 'effective_from' => $next, 'reason' => 'x'], $this->h($w['accountant']))->assertStatus(403);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_unit_answers_its_own_commission_to_those_who_may_change_it_and_never_in_the_landlords_view(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $unit = $w['units'][2];
        $this->getJson("/api/v1/rentals/units/{$unit->id}", $h)->assertOk()->assertJsonPath('data.commission', null);
        // Exactly what UnitFormScreen sends for "A flat amount".
        $this->putJson("/api/v1/rentals/units/{$unit->id}", ['commission_type' => 'flat', 'commission_bp' => null, 'commission_minor' => 40000], $h)->assertOk()->assertJsonPath('data.commission', ['type' => 'flat', 'bp' => 0, 'minor' => 40000]);
        $this->getJson("/api/v1/rentals/units/{$unit->id}", $h)->assertOk()->assertJsonPath('data.commission.minor', 40000);
        $this->getJson("/api/v1/rentals/units/{$unit->id}", $this->h($w['viewer']))->assertOk()->assertJsonMissingPath('data.commission');
        $this->getJson("/api/v1/rentals/units/{$unit->id}", $this->h($w['caretaker']))->assertOk()->assertJsonMissingPath('data.commission');
        // Blank follows the property again.
        $this->putJson("/api/v1/rentals/units/{$unit->id}", ['commission_type' => null, 'commission_bp' => null, 'commission_minor' => null], $h)->assertOk()->assertJsonPath('data.commission', null);

        [$person] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->putJson("/api/v1/rentals/units/{$unit->id}", ['commission_type' => 'flat', 'commission_minor' => 40000], $h)->assertOk();
        $units = $this->getJson('/api/v1/rentals/me/properties', $person)->assertOk();
        $this->assertStringNotContainsString('"commission_minor"', (string) $units->getContent());
    }

    public function test_nonsense_in_any_field_of_the_new_writes_is_a_refusal_and_never_a_fault(): void
    {
        [$w, $lease] = $this->leaving();
        $h = $this->h($w['owner']);
        $nonsense = [[], ['x'], ['a' => ['b' => 'c']], 'abc', '', -1, 1.5, true, null, str_repeat('x', 5000), '9999999999999999999999', '0000-00-00', "\u{1F3E0}"];
        $routes = [
            "/api/v1/rentals/leases/{$lease->id}/move-out-preview" => ['date' => '2026-03-16', 'readings' => [['utility' => 'water', 'reading' => '20']], 'damages' => [['description' => 'A window', 'amount_minor' => 5000, 'goes_to' => 'landlord']], 'unused_days' => true, 'refund_deposit' => 'cash', 'refund_credit' => 'cash', 'reason' => 'Moved away'],
            '/api/v1/rentals/leases/move-in-preview' => ['start_date' => '2026-04-15', 'rent_minor' => 300000, 'cycle_months' => 1, 'anchor' => 'first', 'end_date' => '2027-04-14', 'deposit_minor' => 300000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000]]],
            "/api/v1/rentals/leases/{$lease->id}/commission" => ['type' => 'percent', 'bp' => 750, 'minor' => 0, 'effective_from' => '2026-06-01', 'reason' => 'Agreed'],
        ];
        $bad = [];
        foreach ($routes as $url => $good) {
            $this->postJson($url, $good, $h)->assertOk();
            $fields = array_keys($good);
            foreach ($good as $k => $v) { // and one level in, for the lists of lines
                if (is_array($v) && isset($v[0]) && is_array($v[0])) {
                    foreach (array_keys($v[0]) as $inner) {
                        $fields[] = "{$k}.0.{$inner}";
                    }
                }
            }
            foreach ($fields as $field) {
                foreach ($nonsense as $value) {
                    $body = $good;
                    data_set($body, $field, $value);
                    $res = $this->postJson($url, $body, $h);
                    if ($res->getStatusCode() >= 500) {
                        $bad[] = "{$url} {$field}=".json_encode($value).': '.$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
                    }
                }
            }
        }
        $this->assertSame([], $bad);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_tenants_own_lease_never_carries_the_commission_history(): void
    {
        $w = $this->world();
        $lease = $w['amina']->fresh();
        $next = $lease->billed_until->copy()->addDay()->toDateString();
        $this->postJson("/api/v1/rentals/leases/{$lease->id}/commission", ['type' => 'percent', 'bp' => 750, 'minor' => 0, 'effective_from' => $next, 'reason' => 'Agreed with the landlord'], $this->h($w['owner']))->assertOk();
        [$person] = $this->person($w['owner'], 'tenant', (int) $lease->tenant_id);
        $res = $this->getJson("/api/v1/rentals/me/lease/{$lease->id}", $person)->assertOk();
        $this->assertEmpty($res->json('data.commission_changes') ?? $res->json('data.lease.commission_changes') ?? []);
        $this->assertStringNotContainsString('Agreed with the landlord', (string) $res->getContent());
    }

    public function test_an_expense_over_the_landlords_limit_is_saved_with_a_reason_and_refused_without(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        \App\Models\RentalLandlord::withoutGlobalScopes()->whereKey($w['margaret']->id)->update(['expense_limit_minor' => 10000]);
        // Exactly what ExpenseFormScreen sends once the reason is given.
        $body = ['property_id' => (int) $w['p']->id, 'category' => 'Repairs', 'description' => 'New pump', 'amount_minor' => 50000, 'method' => 'cash', 'paid_on' => '2026-03-26', 'charged_to' => 'landlord'];
        $res = $this->postJson('/api/v1/rentals/expenses', $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'over_limit_reason_required');
        $this->assertStringContainsString('Say why', (string) $res->json('message'));
        $this->postJson('/api/v1/rentals/expenses', $body + ['over_limit_reason' => 'The pump had burnt out'], $h)->assertCreated();
        $this->assertBooksAgree((int) $w['company']->id);
    }
}
