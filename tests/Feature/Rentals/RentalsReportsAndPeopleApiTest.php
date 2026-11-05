<?php

namespace Tests\Feature\Rentals;

use App\Models\Plan;
use App\Models\RentalExpense;
use App\Models\RentalSetting;
use App\Models\Subscription;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reports, statements, month end and the people's own side: what the phone sends is what these prove is honoured. All of it is sample data.
 */
class RentalsReportsAndPeopleApiTest extends RentalsPhoneApiCase
{
    private function open(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->get((string) preg_replace('#^https?://[^/]+#', '', $url));
    }

    // ── Item 8: a landlord's answer ────────────────────────────────────────

    public function test_a_landlords_payouts_hold_no_staff_words_and_staff_still_read_them(): void
    {
        $w = $this->world();
        app(Payments::class)->receive($w['owner'], $w['amina'], ['amount_minor' => 300000, 'method' => 'cash']);
        $p = app(Payouts::class)->record($w['owner'], $w['margaret'], ['amount_minor' => 1000, 'method' => 'cash', 'notes' => 'Staff note: she argued about the fee', 'advance' => true, 'advance_reason' => 'Staff reason: school fees', 'advance_others_ok' => true]);
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);

        $res = $this->getJson('/api/v1/rentals/me/payouts', $h)->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $p->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('advance_reason', $row);
        $this->assertStringNotContainsString('Staff note', (string) $res->getContent());
        $this->assertStringNotContainsString('Staff reason', (string) $res->getContent());

        $staff = collect($this->getJson('/api/v1/rentals/payouts?landlord_id='.$w['margaret']->id, $this->h($w['owner']))->assertOk()->json('data'))->firstWhere('id', $p->id);
        $this->assertSame('Staff note: she argued about the fee', $staff['notes']);
    }

    // ── Items 12, 13, G7: reports ─────────────────────────────────────────

    public function test_a_report_that_needs_a_record_refuses_before_any_link_and_the_registry_says_so(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        foreach (['landlord_statement', 'landlord_rent_sheet', 'landlord_year'] as $key) {
            $this->getJson("/api/v1/rentals/reports/{$key}?format=pdf&from=2026-03-01&to=2026-03-31", $h)->assertStatus(422)->assertJsonPath('errors.code', 'landlord_required')->assertJsonMissingPath('data.url');
        }
        $this->getJson('/api/v1/rentals/reports/tenant_statement?format=pdf', $h)->assertStatus(422)->assertJsonPath('errors.code', 'lease_required');
        $this->getJson('/api/v1/rentals/reports/rent_roll?format=pdf', $h)->assertOk();

        $registry = collect($this->getJson('/api/v1/rentals/reports', $h)->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('landlord', $registry['landlord_statement']['needs']);
        $this->assertSame('lease', $registry['tenant_statement']['needs']);
        $this->assertNull($registry['rent_roll']['needs']);
        $this->assertContains('landlord', $registry['landlord_year']['filters']);

        // With the record the phone sends, the link opens a file.
        $url = $this->getJson('/api/v1/rentals/reports/landlord_statement?format=pdf&from=2026-03-01&to=2026-03-31&landlord_id='.$w['margaret']->id, $h)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($url)->assertOk()->headers->get('Content-Type'));
        $url = $this->getJson('/api/v1/rentals/reports/tenant_statement?format=pdf&lease_id='.$w['brian']->id, $h)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($url)->assertOk()->headers->get('Content-Type'));
        $url = $this->getJson('/api/v1/rentals/reports/landlord_year?format=xlsx&year=2026&landlord_id='.$w['margaret']->id, $h)->assertOk()->json('data.url');
        $this->open($url)->assertOk();

        // An owner has one landlord: itself. Nothing to choose.
        [, $own] = $this->rentalsAccount('owner');
        $owner = collect($this->getJson('/api/v1/rentals/reports', $this->h($own))->assertOk()->json('data'))->keyBy('key');
        $this->assertNull($owner['landlord_statement']['needs']);
        $this->assertNull($owner['landlord_year']['needs']);
        $this->assertArrayNotHasKey('landlord_rent_sheet', $owner->all());
        $this->getJson('/api/v1/rentals/reports/landlord_statement?format=pdf', $this->h($own))->assertOk();
    }

    public function test_a_refused_download_is_json_so_the_phone_can_keep_it_out_of_the_browser(): void
    {
        $w = $this->world();
        // A link made before the rule, or one altered: what opens is a refusal in JSON, with its words and code.
        $link = RentalLinks::report((int) $w['company']->id, (int) $w['owner']->id, 'landlord_statement', 'pdf', []);
        $res = $this->open($link['url'])->assertStatus(422);
        $this->assertStringContainsString('json', (string) $res->headers->get('Content-Type'));
        $this->assertSame('landlord_required', $res->json('errors.code'));
        $this->assertSame('Choose the landlord.', $res->json('message'));

        $expired = $this->open($link['url'].'x')->assertStatus(403);
        $this->assertStringContainsString('json', (string) $expired->headers->get('Content-Type'));
        $this->assertSame('link_expired', $expired->json('errors.code'));
    }

    public function test_reports_honour_the_period_the_phone_sends_on_screen_and_in_the_link(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $this->getJson('/api/v1/rentals/reports/monthly_building?format=json&month=2026-02', $h)->assertOk()->assertJsonPath('data.from', '2026-02-01')->assertJsonPath('data.to', '2026-02-28');
        $this->getJson('/api/v1/rentals/reports/collections?format=json&from=2026-02-01&to=2026-02-10', $h)->assertOk()->assertJsonPath('data.from', '2026-02-01')->assertJsonPath('data.to', '2026-02-10');
        $this->getJson('/api/v1/rentals/reports/landlord_year?format=json&year=2025&landlord_id='.$w['margaret']->id, $h)->assertOk()->assertJsonPath('data.from', '2025-01-01')->assertJsonPath('data.to', '2025-12-31');
        $this->getJson('/api/v1/rentals/reports/landlord_rent_sheet?format=json&month=2026-02&landlord_id='.$w['margaret']->id, $h)->assertOk()->assertJsonPath('data.to', '2026-02-28');

        $url = $this->getJson('/api/v1/rentals/reports/monthly_building?format=pdf&month=2026-02&property_id='.$w['p']->id, $h)->assertOk()->json('data.url');
        $this->assertStringContainsString('month=2026-02', $url);
        $this->assertStringContainsString('property_id='.$w['p']->id, $url);
        $this->open($url)->assertOk();

        // Today's "October's report is ready" names its month, and that is the query above.
        Carbon::setTestNow('2026-04-05 09:00:00');
        $today = $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data');
        $line = collect(data_get($today, 'groups'))->flatMap(fn ($g) => $g['lines'])->firstWhere('kind', 'last_month_report');
        $this->assertNotNull($line);
        $this->assertSame(['type' => 'report', 'id' => null, 'params' => ['key' => 'monthly_building', 'month' => '2026-03']], $line['link']);
    }

    public function test_every_report_the_web_offers_reads_on_a_phone_as_a_table(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        foreach (RentalReports::registry((int) $w['company']->id) as $r) {
            $q = ['format' => 'json'];
            if (RentalReports::needs($r['key'], false) === 'landlord') {
                $q['landlord_id'] = $w['margaret']->id;
            }
            if (RentalReports::needs($r['key'], false) === 'lease') {
                $q['lease_id'] = $w['brian']->id;
            }
            $res = $this->getJson('/api/v1/rentals/reports/'.$r['key'].'?'.http_build_query($q), $h);
            $res->assertOk();
            $d = $res->json('data');
            $this->assertNotEmpty($d['columns'], $r['key']);
            foreach ($d['columns'] as $c) {
                $this->assertContains($c['type'], ['text', 'money', 'int', 'date', 'pct'], $r['key']);
            }
            $this->assertIsArray($d['rows'], $r['key']);
            $this->assertIsArray($d['extra_totals'], $r['key']);
        }
        $d = $this->getJson('/api/v1/rentals/reports/occupancy?format=json', $h)->assertOk()->json('data');
        foreach ($d['extra_totals'] as $t) {
            $this->assertArrayHasKey('label', $t);
            $this->assertContains($t['type'], ['money', 'pct', 'int']);
        }
    }

    // ── Item 15: sign-in codes ────────────────────────────────────────────

    public function test_with_email_off_the_answer_to_a_code_says_so_for_everyone_alike(): void
    {
        $w = $this->world();
        [, $login] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        config(['messaging.mail.driver' => 'log', 'mail.default' => 'smtp']);
        $known = $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk()->json();
        $nobody = $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => 'nobody@example.com'])->assertOk()->json();
        $this->assertSame($known, $nobody, 'it is about the service, not about anyone');
        $this->assertFalse($known['data']['codes_on']);
        $this->assertStringContainsString('email is switched off', $known['message']);

        config(['messaging.mail.driver' => 'mail', 'mail.default' => 'array']);
        $on = $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => 'nobody@example.com'])->assertOk()->json();
        $this->assertTrue($on['data']['codes_on']);
        $this->assertStringContainsString('a code is on its way', $on['message']);
    }

    // ── Item 16 ────────────────────────────────────────────────────────────

    public function test_an_answer_to_a_repair_no_longer_waiting_is_refused_and_says_so(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $w['margaret']->forceFill(['expense_limit_minor' => 1000])->save();
        $m = app(Maintenance::class)->open($w['owner'], (int) $w['company']->id, ['unit_id' => $w['units'][0]->id, 'title' => 'Burst pipe']);
        $m = app(LandlordApprovals::class)->ask($w['owner'], app(Maintenance::class)->quote($w['owner'], $m, 5000));
        $this->postJson('/api/v1/rentals/me/approvals/'.$m->id, ['yes' => true], $h)->assertOk()->assertJsonPath('data.approval.status', 'yes');
        // A second answer, the opposite one, did not count: the phone must not say it did.
        $this->postJson('/api/v1/rentals/me/approvals/'.$m->id, ['yes' => false, 'note' => 'Changed my mind'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'not_waiting');
        $this->assertSame('yes', $m->fresh()->approval_status);
    }

    // ── Item 18 ────────────────────────────────────────────────────────────

    public function test_arrears_rows_and_their_headline_are_overdue_while_owed_stays_apart(): void
    {
        $w = $this->world();
        app(Billing::class)->charge($w['owner'], $w['brian'], ['description' => 'A key', 'amount_minor' => 5000, 'goes_to' => 'agency', 'due_date' => '2026-04-20']);
        $res = $this->getJson('/api/v1/rentals/arrears', $this->h($w['owner']))->assertOk();
        $rows = collect($res->json('data'));
        $brian = $rows->firstWhere('id', $w['brian']->id);
        $this->assertSame((int) $brian['overdue_minor'] + 5000, (int) $brian['position']['owed_minor'], 'a bill not late yet is owed, not overdue');
        $aging = $res->json('meta.aging');
        $this->assertSame((int) $rows->sum('overdue_minor'), (int) $aging['total_minor'], 'the headline adds up the rows');
        $this->assertSame((int) array_sum($aging['aging']), (int) $aging['total_minor'], 'and its bars');
        $this->assertSame($rows->count(), (int) $aging['leases']);

        // A page past any number there is: an empty page, not an overflow.
        $this->getJson('/api/v1/rentals/arrears?page=99999999999999999999', $this->h($w['owner']))->assertOk()->assertJsonPath('data', []);
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->getJson('/api/v1/rentals/me/arrears?page=99999999999999999999', $h)->assertOk()->assertJsonPath('data', []);
    }

    // ── Item 20 ────────────────────────────────────────────────────────────

    public function test_a_tenants_bills_page_as_the_phone_asks(): void
    {
        $w = $this->world();
        foreach ([1, 2, 3] as $i) {
            app(Billing::class)->charge($w['owner'], $w['brian'], ['description' => "Extra {$i}", 'amount_minor' => 1000 * $i, 'goes_to' => 'agency']);
        }
        [$h] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $one = $this->getJson('/api/v1/rentals/me/charges?page=1&per_page=2', $h)->assertOk();
        $this->assertCount(2, $one->json('data'));
        $last = (int) $one->json('meta.pagination.last_page');
        $this->assertGreaterThan(1, $last);
        $seen = [];
        for ($p = 1; $p <= $last; $p++) {
            $seen = array_merge($seen, array_column($this->getJson('/api/v1/rentals/me/charges?page='.$p.'&per_page=2', $h)->assertOk()->json('data'), 'id'));
        }
        $this->assertCount((int) $one->json('meta.pagination.total'), array_unique($seen));
    }

    // ── G5: a tenant's statement for staff ────────────────────────────────

    public function test_a_lease_statement_is_for_whoever_may_see_the_lease(): void
    {
        $w = $this->world();
        $caretaker = $this->h($w['caretaker']);
        $this->getJson('/api/v1/rentals/reports/tenant_statement?format=json&lease_id='.$w['brian']->id, $caretaker)->assertStatus(403);

        $d = $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/statement?from=2026-02-01&to=2026-03-26', $caretaker)->assertOk()->json('data');
        $this->assertSame('tenant_statement', $d['key']);
        $this->assertSame('2026-02-01', $d['from']);
        $this->assertNotEmpty($d['rows']);

        $url = $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/statement?format=pdf', $caretaker)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($url)->assertOk()->headers->get('Content-Type'));
        $xlsx = $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/statement?format=xlsx', $this->h($w['owner']))->assertOk()->json('data.url');
        $this->open($xlsx)->assertOk();

        // Another building's lease does not exist for them, and a link they were given stops when they stop looking after it.
        $this->getJson('/api/v1/rentals/leases/'.$w['carol']->id.'/statement', $caretaker)->assertStatus(404);
        DB::table('rental_property_staff')->where('user_id', $w['caretaker']->id)->delete();
        $this->open($url)->assertStatus(404);
        $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/statement', $this->stranger())->assertStatus(404);
        $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/statement?format=doc', $this->h($w['owner']))->assertStatus(422)->assertJsonPath('errors.code', 'unknown_format');
    }

    // ── G9: held back at month end ────────────────────────────────────────

    public function test_what_is_held_back_reads_and_records_as_the_web_does(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $this->getJson('/api/v1/rentals/month-end/withholding?month=2026-02', $h)->assertOk()->assertJsonPath('data.on', false)->assertJsonPath('data.rows', []);
        $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['margaret']->id, 'month' => '2026-02', 'amount_minor' => 100, 'method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'withholding_off');

        RentalSetting::withoutGlobalScopes()->where('company_id', $w['company']->id)->update(['withholding_on' => true]);
        $w['margaret']->forceFill(['withholding_bp' => 600])->save();
        $d = $this->getJson('/api/v1/rentals/month-end/withholding?month=2026-02', $h)->assertOk()->json('data');
        $this->assertTrue($d['on']);
        $this->assertTrue($d['can_record']);
        $this->assertSame('2026-02', $d['month']);
        $this->assertCount(12, $d['months']);
        $row = collect($d['rows'])->firstWhere('landlord_id', $w['margaret']->id);
        $this->assertSame(600, $row['rate_bp']);
        $this->assertSame(18000, $row['suggested_minor']); // 6% of the 300,000 collected from Amina in February
        $this->assertSame(18000, $row['left_minor']);

        // The exact body the phone sends.
        $e = $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['margaret']->id, 'month' => '2026-02', 'amount_minor' => 10000, 'method' => 'cash', 'paid_on' => '2026-03-26', 'vendor' => 'The tax office'], $h)->assertStatus(201)->json('data');
        $this->assertSame('Withholding tax', $e['category']);
        $this->assertSame('landlord', $e['charged_to']);
        $this->assertSame('2026-03-26', $e['paid_on']);
        $this->assertSame('2026-02', RentalExpense::withoutGlobalScopes()->find($e['id'])->for_month);
        $after = collect($this->getJson('/api/v1/rentals/month-end/withholding?month=2026-02', $h)->assertOk()->json('data.rows'))->firstWhere('landlord_id', $w['margaret']->id);
        $this->assertSame(10000, $after['recorded_minor']);
        $this->assertSame(8000, $after['left_minor']);
        $this->assertBooksAgree((int) $w['company']->id);

        // Who may: the expenses permission writes, a landlord with no percent has nothing held back, no future month, no scoped login.
        $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['peter']->id, 'month' => '2026-02', 'amount_minor' => 100, 'method' => 'cash'], $h)->assertStatus(404);
        $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['margaret']->id, 'month' => '2026-05', 'amount_minor' => 100, 'method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'month_invalid');
        $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['margaret']->id, 'month' => '2026-02', 'amount_minor' => 100, 'method' => 'cash'], $this->h($w['viewer']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/month-end/withholding', $this->h($w['caretaker']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/month-end/withholding', $this->h($w['accountant']))->assertOk()->assertJsonPath('data.can_record', true);
        $this->postJson('/api/v1/rentals/month-end/withholding', ['landlord_id' => $w['margaret']->id, 'month' => '2026-02', 'amount_minor' => 100, 'method' => 'cash'], $this->stranger())->assertStatus(404);
    }

    // ── G10: year summaries ───────────────────────────────────────────────

    public function test_the_offices_year_summaries_prepare_messages_and_send_nothing(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $d = $this->getJson('/api/v1/rentals/year-summaries?year=2026', $h)->assertOk()->json('data');
        $this->assertSame('2026', $d['year']);
        $this->assertContains(2025, $d['years']);
        $row = collect($d['rows'])->firstWhere('id', $w['margaret']->id);
        $this->assertSame('Not sent', $row['status']);
        $this->assertStringContainsString('year-summary', $row['text']);
        $this->assertStringContainsString('wa.me', (string) $row['whatsapp']);
        $this->assertSame('application/pdf', $this->open($row['pdf_url'])->assertOk()->headers->get('Content-Type'));
        $this->open($row['xlsx_url'])->assertOk();

        $this->postJson('/api/v1/rentals/year-summaries/'.$w['margaret']->id.'/prepared', ['year' => '2026', 'channel' => 'whatsapp'], $h)->assertOk()->assertJsonPath('data.year', '2026');
        $log = DB::table('message_log')->where('company_id', $w['company']->id)->where('purpose', 'rentals_year_2026')->first();
        $this->assertSame('prepared', $log->status, 'written down as prepared, never as sent');
        $this->assertStringContainsString('Link prepared by', collect($this->getJson('/api/v1/rentals/year-summaries?year=2026', $h)->assertOk()->json('data.rows'))->firstWhere('id', $w['margaret']->id)['status']);

        $this->getJson('/api/v1/rentals/year-summaries', $this->h($w['caretaker']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/year-summaries', $this->h($w['viewer']))->assertStatus(403);
        $this->postJson('/api/v1/rentals/year-summaries/'.$w['margaret']->id.'/prepared', ['year' => '2026', 'channel' => 'pigeon'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'via_invalid');
        $this->postJson('/api/v1/rentals/year-summaries/'.$w['margaret']->id.'/prepared', ['year' => '2026', 'channel' => 'sms'], $this->stranger())->assertStatus(404);

        [, $own] = $this->rentalsAccount('owner');
        $this->getJson('/api/v1/rentals/year-summaries', $this->h($own))->assertStatus(422)->assertJsonPath('errors.code', 'agency_only');
    }

    public function test_a_landlord_reads_their_own_year_as_a_file(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        [$other] = $this->person($w['owner'], 'landlord', (int) $w['peter']->id);
        $this->getJson('/api/v1/rentals/me/year-summary', $h)->assertOk()->assertJsonPath('data.years', [2026]);
        $pdf = $this->getJson('/api/v1/rentals/me/year-summary?year=2026&format=pdf', $h)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($pdf)->assertOk()->headers->get('Content-Type'));
        $this->open($this->getJson('/api/v1/rentals/me/year-summary?year=2026&format=xlsx', $h)->assertOk()->json('data.url'))->assertOk();
        $this->getJson('/api/v1/rentals/me/year-summary?year=2019&format=pdf', $h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_date');
        $this->getJson('/api/v1/rentals/me/year-summary?year=2026&format=doc', $h)->assertStatus(422)->assertJsonPath('errors.code', 'unknown_format');
        $this->assertNotSame($pdf, $this->getJson('/api/v1/rentals/me/year-summary?year=2026&format=pdf', $other)->assertOk()->json('data.url'));

        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->getJson('/api/v1/rentals/me/year-summary', $tenant)->assertStatus(404);
    }

    // ── G11, G15: after a payout ──────────────────────────────────────────

    public function test_after_a_payout_the_statement_is_offered_and_written_down_as_prepared(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        app(Payments::class)->receive($w['owner'], $w['amina'], ['amount_minor' => 300000, 'method' => 'cash']);
        $p = app(Payouts::class)->record($w['owner'], $w['margaret'], ['amount_minor' => 1000, 'method' => 'cash']);
        $d = $this->getJson('/api/v1/rentals/payouts/'.$p->id.'/statement-message', $h)->assertOk()->json('data');
        $this->assertSame('March 2026', $d['month']);
        $this->assertSame($p->number, $d['number']);
        $this->assertStringContainsString('wa.me', (string) $d['whatsapp']);
        $this->assertSame('application/pdf', $this->open($d['pdf'])->assertOk()->headers->get('Content-Type'));
        $this->assertSame('application/pdf', $this->open($d['advice'])->assertOk()->headers->get('Content-Type'));

        $this->postJson('/api/v1/rentals/payouts/'.$p->id.'/statement-prepared', ['channel' => 'sms'], $h)->assertOk();
        $this->assertSame('prepared', DB::table('message_log')->where('company_id', $w['company']->id)->where('purpose', 'rentals_statement')->value('status'));

        $this->getJson('/api/v1/rentals/payouts/'.$p->id.'/statement-message', $this->h($w['caretaker']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/payouts/'.$p->id.'/statement-message', $this->stranger())->assertStatus(404);
        $this->postJson('/api/v1/rentals/payouts/'.$p->id.'/statement-prepared', ['channel' => 'carrier'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'via_invalid');
    }

    public function test_a_landlord_opens_the_advice_of_their_own_payout_only(): void
    {
        $w = $this->world();
        app(Payments::class)->receive($w['owner'], $w['amina'], ['amount_minor' => 300000, 'method' => 'cash']);
        $p = app(Payouts::class)->record($w['owner'], $w['margaret'], ['amount_minor' => 1000, 'method' => 'cash']);
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        [$other] = $this->person($w['owner'], 'landlord', (int) $w['peter']->id);
        $url = $this->getJson('/api/v1/rentals/me/payouts/'.$p->id.'/advice', $h)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($url)->assertOk()->headers->get('Content-Type'));
        $this->getJson('/api/v1/rentals/me/payouts/'.$p->id.'/advice', $other)->assertStatus(404);
        $this->getJson('/api/v1/rentals/me/payouts/99999999/advice', $h)->assertStatus(404);
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->getJson('/api/v1/rentals/me/payouts/'.$p->id.'/advice', $tenant)->assertStatus(404);
    }

    // ── The landlord's door-by-door month ─────────────────────────────────

    public function test_a_landlords_month_door_by_door_holds_names_and_figures_only(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $res = $this->getJson('/api/v1/rentals/me/sheet?month=2026-03', $h)->assertOk();
        $d = $res->json('data');
        $this->assertSame('2026-03', $d['month']);
        $this->assertSame('2026-03-26', $d['as_of'], 'the month so far');
        $rows = collect($d['rows']);
        $this->assertSame(300000, $rows->firstWhere('tenant', 'Amina Fictional')['paid_minor']);
        $this->assertSame(600000, $rows->firstWhere('tenant', 'Brian Sample')['owed_minor']);
        $this->assertSame(2, $rows->firstWhere('tenant', 'Brian Sample')['months_behind']);
        $this->assertNotNull($rows->firstWhere('tenant', null)['vacant_since']);
        $this->assertSame((int) $rows->sum('paid_minor'), $d['totals']['paid_minor']);
        $this->assertNull($rows->firstWhere('tenant', 'Carol Madeup'), 'another landlord\'s tenant');
        foreach (['0772100001', '0772100002', '256772100002', 'CF90SECRET789Z', 'commission', 'lease_id', 'tenant_id'] as $never) {
            $this->assertStringNotContainsString($never, (string) $res->getContent());
        }
        $this->getJson('/api/v1/rentals/me/sheet?month=2026-13', $h)->assertStatus(422);
        $this->getJson('/api/v1/rentals/me/sheet?month=2026-04', $h)->assertStatus(422);
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->getJson('/api/v1/rentals/me/sheet', $tenant)->assertStatus(404);
    }

    // ── G16: an ended lease ───────────────────────────────────────────────

    public function test_a_tenant_keeps_an_ended_lease_and_its_statement_while_another_home_is_live(): void
    {
        $w = $this->world();
        [$r, $rooms] = $this->building($w['owner'], 2, 100000, ['name' => 'Spare Rooms']);
        $old = $this->tenantIn($w['owner'], $rooms[0], '2026-03-01', ['deposit_minor' => 0, 'tenant_id' => $w['amina']->tenant_id]);
        app(Payments::class)->receive($w['owner'], $old, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Leases::class)->moveOut($w['owner'], $old->fresh(), ['date' => '2026-03-20']);
        [$h] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $leases = collect($this->getJson('/api/v1/rentals/me', $h)->assertOk()->json('data.tenant.leases'))->keyBy('id');
        $this->assertSame('ended', $leases[$old->id]['status']);
        $this->assertSame(0, (int) $leases[$old->id]['position']['owed_minor']);
        $this->getJson('/api/v1/rentals/me/lease/'.$old->id, $h)->assertOk()->assertJsonPath('data.status', 'ended');
        $url = $this->getJson('/api/v1/rentals/me/lease/'.$old->id.'/statement-link', $h)->assertOk()->json('data.url');
        $this->assertSame('application/pdf', $this->open($url)->assertOk()->headers->get('Content-Type'));
    }

    // ── The people's side stays on paid plans ─────────────────────────────

    public function test_every_new_person_route_stops_when_the_plan_has_no_sign_in_for_them(): void
    {
        $w = $this->world();
        app(Payments::class)->receive($w['owner'], $w['amina'], ['amount_minor' => 300000, 'method' => 'cash']);
        $p = app(Payouts::class)->record($w['owner'], $w['margaret'], ['amount_minor' => 1000, 'method' => 'cash']);
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $url = $this->getJson('/api/v1/rentals/me/year-summary?year=2026&format=pdf', $h)->assertOk()->json('data.url');
        $advice = $this->getJson('/api/v1/rentals/me/payouts/'.$p->id.'/advice', $h)->assertOk()->json('data.url');
        $this->seed(\Database\Seeders\EstateLedgerPlanSeeder::class);
        Subscription::where('company_id', $w['company']->id)->update(['plan_id' => Plan::where('slug', 'estate-ledger-free')->value('id')]);
        foreach (['me/sheet', 'me/year-summary', 'me/payouts/'.$p->id.'/advice'] as $path) {
            [$again] = $this->freshToken($w);
            $this->getJson('/api/v1/rentals/'.$path, $again)->assertStatus(401)->assertJsonPath('errors.code', 'portal_not_on_plan');
        }
        $this->open($url)->assertStatus(404);
        $this->open($advice)->assertStatus(404);
    }

    /** The first refusal deletes a token, so each path gets its own. @return array{0: array<string, string>} */
    private function freshToken(array $w): array
    {
        $login = \App\Models\RentalLogin::withoutGlobalScopes()->where('company_id', $w['company']->id)->where('subject_type', 'landlord')->where('subject_id', $w['margaret']->id)->firstOrFail();

        return [$this->auth($login->createToken('t', [\App\Models\RentalLogin::ABILITY])->plainTextToken)];
    }
}
