<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalMaintenance;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\Portal\TenantRequests;

/** A tenant's and a landlord's first screens and their new routes: only their own records, a landlord never reads a tenant's phone, and neither token reaches the other's routes or staff's. */
class RentalsPeopleApiTest extends RentalsPhoneApiCase
{
    private const LANDLORD_ROUTES = ['approvals', 'repairs', 'expenses', 'statements'];

    public function test_a_tenants_home(): void
    {
        $w = $this->world();
        [$h, $login] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $res = $this->getJson('/api/v1/rentals/me/home', $h)->assertOk();
        $d = $res->json('data');
        $this->assertSame(['type', 'account', 'name', 'owed_minor', 'credit_minor', 'homes', 'last_payment', 'paid_reports_waiting', 'repairs_open'], array_keys($d));
        $this->assertSame('tenant', $d['type']);
        $this->assertSame('Brian Sample', $d['name']);
        $this->assertSame(600000, $d['owed_minor']);
        $this->assertSame(['id', 'name', 'currency', 'payment_instructions', 'phone'], array_keys($d['account']));
        $this->assertSame('MTN MoMo 0700 000 100 (fictional)', $d['account']['payment_instructions']);
        $this->assertCount(1, $d['homes']);
        $this->assertKeys($d['homes'][0], ['id', 'number', 'status', 'rent_minor', 'unit', 'property', 'position', 'notice_request'], 'home');
        $this->assertSame((int) $w['brian']->id, $d['homes'][0]['id']);
        $this->assertSame('overdue', $d['homes'][0]['position']['state']);
        $this->assertSame('2026-04-01', $d['homes'][0]['position']['next_due']['date']);
        $this->assertNull($d['homes'][0]['notice_request']);
        $this->assertNull($d['last_payment']);

        // Their notice, waiting for the office.
        app(TenantRequests::class)->notice($login, ['lease_id' => (int) $w['brian']->id, 'move_out_on' => '2026-04-30']);
        $this->assertSame('2026-04-30', $this->getJson('/api/v1/rentals/me/home', $h)->assertOk()->json('data.homes.0.notice_request.move_out_on'));
        $this->fixture('me_home_tenant', $res->json());

        // Who pays on time.
        [$amina] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $paid = $this->getJson('/api/v1/rentals/me/home', $amina)->assertOk()->json('data');
        $this->assertSame(0, $paid['owed_minor']);
        $this->assertSame(300000, $paid['last_payment']['amount_minor']);
        $this->assertSame(1, $paid['repairs_open'], 'the tap, at their door');
    }

    public function test_a_tenants_statement_link_opens_their_own_only(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $link = $this->getJson('/api/v1/rentals/me/lease/'.$w['brian']->id.'/statement-link', $h)->assertOk()->json('data');
        $this->assertSame(['url', 'expires_in'], array_keys($link));
        $this->assertSame(1800, $link['expires_in']);
        $pdf = $this->get(str_replace(config('app.url'), '', $link['url']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->getJson('/api/v1/rentals/me/lease/'.$w['carol']->id.'/statement-link', $h)->assertStatus(404);
        $this->assertStringContainsString('expired', $this->getJson(str_replace(config('app.url'), '', $link['url']).'x')->assertStatus(403)->json('message'));

        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->getJson('/api/v1/rentals/me/lease/'.$w['brian']->id.'/statement-link', $landlord)->assertStatus(404);
    }

    public function test_a_landlords_home_and_lists_show_their_own_and_no_tenants_phone(): void
    {
        $w = $this->world();
        $owner = $w['owner'];
        app(Payouts::class)->record($owner, $w['margaret'], ['amount_minor' => 5000, 'method' => 'cash', 'paid_on' => '2026-03-20']);
        app(Landlords::class)->update($owner, $w['margaret'], ['expense_limit_minor' => 50000]);
        $t = $w['urgentHere'];
        app(\App\Services\Rentals\Maintenance::class)->quote($owner, $t, 200000);
        app(LandlordApprovals::class)->ask($owner, $t->fresh());
        app(Expenses::class)->record($owner, ['property_id' => $w['p']->id, 'category' => 'Repairs', 'description' => 'Gate hinge', 'amount_minor' => 3000, 'method' => 'cash', 'charged_to' => 'landlord']);

        [$h] = $this->person($owner, 'landlord', (int) $w['margaret']->id);
        $res = $this->getJson('/api/v1/rentals/me/home', $h)->assertOk();
        $d = $res->json('data');
        $this->assertSame(['type', 'account', 'name', 'owed_minor', 'month', 'last_payout', 'occupancy', 'vacant', 'repairs_open', 'approvals_waiting'], array_keys($d));
        $this->assertSame('landlord', $d['type']);
        $this->assertSame('Margaret Fictional', $d['name']);
        $this->assertSame(['paid_on' => '2026-03-20', 'amount_minor' => 5000, 'method' => 'cash'], $d['last_payout']);
        $this->assertSame(1, $d['approvals_waiting']);
        $this->assertSame(1, $d['repairs_open']);
        $this->assertSame('Kato Plaza', $d['vacant'][0]['property']);
        $this->assertSame(300000, $d['month']['collected_minor']);
        $this->assertStringNotContainsString('0772100', (string) $res->getContent());
        $this->fixture('me_home_landlord', $res->json());

        // Approvals: what they need to decide, and no more.
        $list = $this->getJson('/api/v1/rentals/me/approvals', $h)->assertOk();
        $row = $list->json('data.0');
        $this->assertSame(1, $list->json('meta.pagination.total'));
        $this->assertSame(200000, $row['quote_minor']);
        $this->assertSame('Kitchen tap leaking', $row['title']);
        $this->assertKeys($row, ['id', 'number', 'property', 'unit', 'title', 'details', 'photos', 'created_at', 'quote_minor'], 'approval');
        $this->assertArrayNotHasKey('assigned_to', $row);
        $this->fixture('me_approvals', $list->json());

        // Their answer. A no needs a note, a yes doesn't, and a second answer is refused.
        $this->postJson('/api/v1/rentals/me/approvals/'.$t->id, [], $h)->assertStatus(422);
        $this->postJson('/api/v1/rentals/me/approvals/'.$t->id, ['yes' => false], $h)->assertStatus(422)->assertJsonPath('errors.code', 'note_required');
        $yes = $this->postJson('/api/v1/rentals/me/approvals/'.$t->id, ['yes' => true], $h)->assertOk()->json('data');
        $this->assertSame(['id', 'number', 'approval'], array_keys($yes));
        $this->assertSame('yes', $yes['approval']['status']);
        $this->postJson('/api/v1/rentals/me/approvals/'.$t->id, ['yes' => true], $h)->assertStatus(422)->assertJsonPath('errors.code', 'not_waiting');
        $this->assertSame('yes', RentalMaintenance::withoutGlobalScopes()->find($t->id)->approval_status);
        $this->assertSame([], $this->getJson('/api/v1/rentals/me/approvals', $h)->assertOk()->json('data'));

        // Repairs: with their own answer; never who has it, never a phone.
        $repairs = $this->getJson('/api/v1/rentals/me/repairs', $h)->assertOk();
        $r = collect($repairs->json('data'))->firstWhere('id', (int) $t->id);
        $this->assertSame(200000, $r['quote_minor']);
        $this->assertFalse($r['waiting_answer']);
        $this->assertStringContainsString('You said yes', $r['answer']['word']);
        foreach (['assigned_to', 'expense_id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $r, $hidden);
        }
        $this->assertStringNotContainsString('0772100', (string) $repairs->getContent());
        $this->assertSame(1, $repairs->json('meta.pagination.total'), 'the roof at Lake View is another landlord\'s');

        // Expenses charged to them, with the word.
        $expenses = $this->getJson('/api/v1/rentals/me/expenses', $h)->assertOk();
        $this->assertSame(1, $expenses->json('meta.pagination.total'));
        $this->assertKeys($expenses->json('data.0'), ['id', 'number', 'category', 'amount_minor', 'paid_on', 'receipt_url', 'receipt_is_image', 'word'], 'expense');

        // Another landlord's repair is not found.
        $this->postJson('/api/v1/rentals/me/approvals/'.$w['urgentThere']->id, ['yes' => true], $h)->assertStatus(404);
    }

    public function test_a_landlords_statements_are_their_closed_months_newest_first(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $empty = $this->getJson('/api/v1/rentals/me/statements', $h)->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame(2026, $empty->json('meta.first_year'));
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $this->h($w['owner']))->assertOk();
        $rows = $this->getJson('/api/v1/rentals/me/statements', $h)->assertOk()->json('data');
        $this->assertSame(['month', 'number', 'issued_on'], array_keys($rows[0]));
        $this->assertSame('2026-02', $rows[0]['month']);
        $this->assertStringStartsWith('ST-2026-02-', $rows[0]['number']);
    }

    public function test_each_token_stays_on_its_own_routes(): void
    {
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        foreach (self::LANDLORD_ROUTES as $r) {
            $this->getJson('/api/v1/rentals/me/'.$r, $tenant)->assertStatus(404);
            $this->getJson('/api/v1/rentals/me/'.$r)->assertStatus(401);
            $this->getJson('/api/v1/rentals/me/'.$r, $this->h($w['owner']))->assertStatus(401);
        }
        $this->postJson('/api/v1/rentals/me/approvals/'.$w['urgentHere']->id, ['yes' => true], $tenant)->assertStatus(404);
        foreach (['home', 'paid-reports'] as $r) {
            $this->getJson('/api/v1/rentals/me/'.$r)->assertStatus(401);
        }
        foreach (['today', 'rent-book', 'search?q=brian', 'month-end', 'paid-reports'] as $r) {
            $this->getJson('/api/v1/rentals/'.$r, $landlord)->assertStatus(401);
            $this->getJson('/api/v1/rentals/'.$r, $tenant)->assertStatus(401);
        }
    }
}
