<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalPayout;
use App\Models\RentalStatement;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Reports\MoneyPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The office on the phone: month end, closing and reopening, statements' messages, the rent sheet, payout runs, whose money, the agency's drawings, the handover preview, asking a landlord.
 * Every money rule is the services'; these check the answers, who may ask, and that a caretaker reads none of it.
 */
class RentalsOfficeApiTest extends RentalsPhoneApiCase
{
    public function test_month_end_shows_the_month_before_it_is_final(): void
    {
        $w = $this->world();
        $res = $this->getJson('/api/v1/rentals/month-end', $this->h($w['owner']))->assertOk();
        $d = $res->json('data');
        $this->assertKeys($d, ['month', 'name', 'from', 'to', 'currency', 'owner', 'status', 'closed_through', 'next_month', 'latest_closed', 'can_close', 'can_reopen', 'first_choices', 'look_at', 'books', 'figures', 'send'], 'month end');
        $this->assertSame('2026-02', $d['month'], 'the month that closes next');
        $this->assertSame('February 2026', $d['name']);
        $this->assertSame('open', $d['status']);
        $this->assertTrue($d['can_close']);
        $this->assertFalse($d['can_reopen']);
        $this->assertNull($d['closed_through']);
        $this->assertSame(['month' => '2026-02', 'name' => 'February 2026'], $d['first_choices'][0]);
        $this->assertKeys($d['look_at'], ['bills_missing', 'reports_waiting', 'expenses_no_receipt', 'below_zero', 'below_zero_minor', 'agency_own_minor', 'commission_exceptions'], 'look at');
        $this->assertSame(['checked' => false, 'problems' => []], $d['books']);
        $this->assertSame([], $d['send']);
        $row = collect($d['figures']['rows'])->firstWhere('landlord_id', (int) $w['margaret']->id);
        $this->assertKeys($row, ['landlord_id', 'name', 'code', 'opening_minor', 'collected_minor', 'commission_minor', 'direct_minor', 'rate_bp', 'deposits_minor', 'expenses_minor', 'payouts_minor', 'other_minor', 'closing_minor', 'lines'], 'row');
        $this->assertSame(300000, $row['collected_minor']);
        $this->assertSame(30000, $row['commission_minor']);
        $this->assertKeys($d['figures']['totals'], ['collected', 'direct', 'commission', 'landlord_share', 'deposits_passed', 'expenses', 'payouts', 'other', 'owed_to_landlords', 'owed_back_by_landlords', 'deposits', 'credit', 'cash', 'cash_total', 'agency_own'], 'totals');
        $this->assertSame(['checked' => true, 'problems' => []], $this->getJson('/api/v1/rentals/month-end?check=1', $this->h($w['owner']))->assertOk()->json('data.books'));

        $this->getJson('/api/v1/rentals/month-end?month=2026-13', $this->h($w['owner']))->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $this->getJson('/api/v1/rentals/month-end?month=2026-04', $this->h($w['owner']))->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
        $this->assertFalse($this->getJson('/api/v1/rentals/month-end?month=2026-03', $this->h($w['owner']))->assertOk()->json('data.can_close'), 'this month is not over');
        $this->fixture('month_end', $res->json());
    }

    public function test_closing_issues_the_statements_and_sending_is_written_down_as_prepared(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-03'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'month_not_over');
        $key = ['Idempotency-Key' => 'close-feb'];
        $closed = $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $h + $key)->assertOk();
        $this->assertSame(['month', 'status', 'version', 'closed_at', 'closed_through', 'next_month', 'statements'], array_keys($closed->json('data')));
        $this->assertSame('2026-02-28', $closed->json('data.closed_through'));
        $this->assertSame(2, $closed->json('data.statements.issued'));
        $this->assertNull($closed->json('data.next_month'), 'March is not over');
        $this->assertSame('true', $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $h + $key)->assertOk()->headers->get('Idempotent-Replay'));
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'month_already_closed');

        $d = $this->getJson('/api/v1/rentals/month-end?month=2026-02', $h)->assertOk()->json('data');
        $this->assertSame('closed', $d['status']);
        $this->assertTrue($d['can_reopen'], 'the account owner');
        $this->assertCount(2, $d['send']);
        $row = collect($d['send'])->firstWhere('landlord_id', (int) $w['margaret']->id);
        $this->assertSame(['statement_id', 'landlord_id', 'name', 'number', 'closing_minor', 'phone', 'email', 'has_sign_in', 'words'], array_keys($row));
        $this->assertSame('Not sent', $row['words']);

        // The message: a signed link and the words for this person's own phone. The server sends nothing.
        $msg = $this->getJson('/api/v1/rentals/statements/'.$row['statement_id'].'/message', $h)->assertOk()->json('data');
        $this->assertSame(['statement_id', 'landlord_id', 'name', 'number', 'month', 'closing_minor', 'phone', 'email', 'has_sign_in', 'words', 'link', 'expires_in', 'text', 'whatsapp', 'sms'], array_keys($msg));
        $this->assertSame(10080 * 60, $msg['expires_in']);
        $this->assertStringContainsString('/rentals/pdf/statement', $msg['link']);
        $this->assertStringContainsString($msg['link'], $msg['text']);
        $this->assertStringStartsWith('https://wa.me/', (string) $msg['whatsapp']);
        $this->get(str_replace(config('app.url'), '', $msg['link']))->assertOk();

        $this->postJson('/api/v1/rentals/statements/'.$row['statement_id'].'/sent', ['via' => 'pigeon'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'via_invalid');
        $sent = $this->postJson('/api/v1/rentals/statements/'.$row['statement_id'].'/sent', ['via' => 'whatsapp'], $h)->assertOk()->json('data');
        $this->assertSame(['statement_id', 'via', 'prepared_at', 'words'], array_keys($sent));
        $this->assertStringStartsWith('Link prepared', $sent['words']);
        $this->assertStringNotContainsStringIgnoringCase('delivered', $sent['words']);
        $this->assertSame(1, DB::table('message_log')->where('company_id', $w['company']->id)->where('purpose', 'rentals_statement')->where('status', 'prepared')->count());
        $this->assertStringStartsWith('Link prepared', collect($this->getJson('/api/v1/rentals/month-end?month=2026-02', $h)->json('data.send'))->firstWhere('landlord_id', (int) $w['margaret']->id)['words']);

        // Reopening: the owner, with a reason, the latest only.
        $this->postJson('/api/v1/rentals/month-end/reopen', ['month' => '2026-02'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        $this->postJson('/api/v1/rentals/month-end/reopen', ['month' => '2026-01', 'reason' => 'x'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reopen_latest_only');
        $accountant = $this->h($w['accountant']);
        $this->postJson('/api/v1/rentals/month-end/reopen', ['month' => '2026-02', 'reason' => 'x'], $accountant)->assertStatus(403);
        $this->assertFalse($this->getJson('/api/v1/rentals/month-end?month=2026-02', $accountant)->assertOk()->json('data.can_reopen'));
        $reopened = $this->postJson('/api/v1/rentals/month-end/reopen', ['month' => '2026-02', 'reason' => 'A receipt was missed'], $h)->assertOk()->json('data');
        $this->assertSame(['month' => '2026-02', 'status' => 'reopened', 'reopened_at' => $reopened['reopened_at'], 'reopen_reason' => 'A receipt was missed', 'closed_through' => null], $reopened);
        $this->assertNull(MonthClose::closedThrough((int) $w['company']->id));
    }

    public function test_a_caretaker_and_a_viewer_read_none_of_the_office(): void
    {
        $w = $this->world();
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $this->h($w['owner']))->assertOk();
        $statement = (int) RentalStatement::withoutGlobalScopes()->where('company_id', $w['company']->id)->value('id');
        $run = $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['landlord_id' => $w['margaret']->id, 'amount_minor' => 1000, 'method' => 'cash']]], $this->h($w['owner']))->assertStatus(201)->json('data.id');
        $routes = [['GET', 'month-end'], ['POST', 'month-end/close'], ['POST', 'month-end/reopen'], ['GET', "statements/{$statement}/message"], ['POST', "statements/{$statement}/sent"], ['GET', 'landlords/'.$w['margaret']->id.'/sheet'],
            ['GET', 'payout-runs'], ['POST', 'payout-runs'], ['GET', 'payout-runs/candidates'], ['GET', "payout-runs/{$run}"], ['PUT', "payout-runs/{$run}"], ['POST', "payout-runs/{$run}/post"], ['POST', "payout-runs/{$run}/cancel"], ['GET', "payout-runs/{$run}/sheet"],
            ['GET', 'money-position'], ['POST', 'cash/drawings'], ['POST', 'cash/drawings/1/reverse'], ['GET', 'properties/'.$w['p']->id.'/handover-preview?landlord_id='.$w['peter']->id]];
        foreach (['caretaker', 'viewer'] as $who) {
            $h = $this->h($w[$who]);
            foreach ($routes as [$m, $uri]) {
                $this->json($m, '/api/v1/rentals/'.$uri, [], $h)->assertStatus(403);
            }
        }
    }

    public function test_a_caretaker_given_more_permissions_still_reads_no_whole_account_money(): void
    {
        $w = $this->world();
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $this->h($w['owner']))->assertOk();
        $statement = (int) RentalStatement::withoutGlobalScopes()->where('company_id', $w['company']->id)->value('id');
        foreach (['rentals_landlords', 'rentals_close', 'rentals_settings', 'rentals_manage', 'rentals_reports', 'rentals_bill', 'rentals_reverse'] as $permission) {
            DB::table('company_role_permissions')->insert(['company_id' => $w['company']->id, 'role' => 'rentals_caretaker', 'permission' => $permission, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $h = $this->h($w['caretaker']);
        foreach (['month-end', 'money-position', 'payout-runs', 'payout-runs/candidates', 'landlords/'.$w['margaret']->id.'/sheet', "statements/{$statement}/message"] as $uri) {
            $this->getJson('/api/v1/rentals/'.$uri, $h)->assertStatus(403);
        }
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-03'], $h)->assertStatus(403);
        $this->postJson('/api/v1/rentals/cash/drawings', ['method' => 'cash', 'amount_minor' => 100], $h)->assertStatus(403);
        $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['landlord_id' => $w['margaret']->id, 'amount_minor' => 100, 'method' => 'cash']]], $h)->assertStatus(403);

        $today = $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data');
        $this->assertNull($today['money_position']);
        $this->assertNull($today['month_end_next']);
        $this->assertNull($today['figures']['landlords_minor']);
        $this->assertNull(collect($today['groups'])->firstWhere('key', 'month_end'));

        // The landlord's balance, the commission, and the landlord in search stay away too.
        $this->assertNull($this->getJson('/api/v1/rentals/properties/'.$w['p']->id.'/handover-preview?landlord_id='.$w['peter']->id, $h)->assertOk()->json('data.old.balance_minor'));
        $preview = $this->getJson('/api/v1/rentals/leases/'.$w['brian']->id.'/payment-preview?amount_minor=1200000', $h)->assertOk()->json('data');
        $this->assertSame(['start', 'end', 'label', 'amount_minor'], array_keys($preview['ahead']['periods'][0]));
        $this->assertArrayNotHasKey('commission_minor', $preview['direct']);
        $this->assertArrayNotHasKey('approval', $this->getJson('/api/v1/rentals/maintenance/'.$w['urgentHere']->id, $h)->assertOk()->json('data'));
        $this->indexed((int) $w['company']->id);
        $this->assertNull(collect($this->getJson('/api/v1/rentals/search?q=Margaret', $h)->assertOk()->json('data.groups'))->firstWhere('kind', 'landlord'));
    }

    public function test_another_accounts_records_do_not_exist(): void
    {
        $w = $this->world();
        $owner = $this->h($w['owner']);
        $this->postJson('/api/v1/rentals/month-end/close', ['month' => '2026-02'], $owner)->assertOk();
        $statement = (int) RentalStatement::withoutGlobalScopes()->where('company_id', $w['company']->id)->value('id');
        $run = $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['landlord_id' => $w['margaret']->id, 'amount_minor' => 1000, 'method' => 'cash']]], $owner)->assertStatus(201)->json('data.id');
        $h = $this->stranger();
        foreach (["statements/{$statement}/message", 'landlords/'.$w['margaret']->id.'/sheet', "payout-runs/{$run}", "payout-runs/{$run}/sheet", 'properties/'.$w['p']->id.'/handover-preview?landlord_id='.$w['peter']->id] as $uri) {
            $this->getJson('/api/v1/rentals/'.$uri, $h)->assertStatus(404);
        }
        $this->postJson("/api/v1/rentals/statements/{$statement}/sent", ['via' => 'link'], $h)->assertStatus(404);
        $this->postJson("/api/v1/rentals/payout-runs/{$run}/post", [], $h)->assertStatus(404);
        $this->postJson('/api/v1/rentals/maintenance/'.$w['urgentHere']->id.'/ask-landlord', [], $h)->assertStatus(404);
        $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['landlord_id' => $w['margaret']->id, 'amount_minor' => 1000, 'method' => 'cash']]], $h)->assertStatus(404);
    }

    public function test_the_rent_sheet_is_the_services_with_a_link_to_its_pdf(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $res = $this->getJson('/api/v1/rentals/landlords/'.$w['margaret']->id.'/sheet?month=2026-03', $h)->assertOk();
        $d = $res->json('data');
        $this->assertSame(['landlord', 'month', 'as_of', 'currency', 'rows', 'totals', 'pdf_url', 'expires_in'], array_keys($d));
        $this->assertSame('2026-03-31', $d['as_of']);
        $this->assertCount(3, $d['rows']);
        $expected = UnitSheet::rows($w['margaret'], '2026-03-31');
        $this->assertEquals($expected, $d['rows']);
        $this->assertSame(array_sum(array_column($expected, 'paid_minor')), $d['totals']['paid_minor']);
        $this->assertStringNotContainsString('0772100001', (string) $res->getContent());
        $this->get(str_replace(config('app.url'), '', $d['pdf_url']))->assertOk();
        $this->getJson('/api/v1/rentals/landlords/'.$w['margaret']->id.'/sheet?month=2026-05', $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
        $this->fixture('landlord_sheet', $res->json());
    }

    public function test_a_payout_run_from_draft_to_paid_and_its_refusals(): void
    {
        $w = $this->world();
        $h = $this->h($w['accountant']);
        $cid = (int) $w['company']->id;
        $cands = $this->getJson('/api/v1/rentals/payout-runs/candidates?above=0', $h)->assertOk()->json('data');
        $this->assertSame(['landlord_id', 'name', 'code', 'phone', 'phone_e164', 'phone_2', 'balance_minor', 'method', 'details', 'last_paid_on', 'last_paid_minor'], array_keys($cands[0]));
        $this->assertSame(540000, collect($cands)->firstWhere('landlord_id', (int) $w['margaret']->id)['balance_minor']);
        $this->assertSame([], $this->getJson('/api/v1/rentals/payout-runs/candidates?above=9000000', $h)->assertOk()->json('data'));

        $this->postJson('/api/v1/rentals/payout-runs', ['lines' => []], $h)->assertStatus(422)->assertJsonPath('errors.code', 'run_empty');
        $line = ['landlord_id' => $w['margaret']->id, 'amount_minor' => 200000, 'method' => 'cash'];
        $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [$line, $line]], $h)->assertStatus(422)->assertJsonPath('errors.code', 'run_duplicate');
        $bad = $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['amount_minor' => 900000] + $line]], $h)->assertStatus(422);
        $bad->assertJsonPath('errors.code', 'run_lines_invalid');
        $this->assertArrayHasKey((string) $w['margaret']->id, $bad->json('errors.rows'));

        $made = $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [$line]], $h)->assertStatus(201);
        $d = $made->json('data');
        $this->assertSame(['id', 'number', 'status', 'paid_on', 'posted_at', 'created_at', 'lines_count', 'total_minor', 'currency', 'lines', 'check'], array_keys($d));
        $this->assertSame('draft', $d['status']);
        $this->assertSame(['id', 'landlord_id', 'name', 'code', 'amount_minor', 'method', 'balance_at_draft_minor', 'balance_minor', 'reference', 'payout_id', 'details'], array_keys($d['lines'][0]));
        $this->assertSame(['accounts', 'total_minor', 'agency_own_minor', 'short'], array_keys($d['check']));
        $this->assertSame(['method', 'label', 'paying_minor', 'recorded_minor', 'left_minor', 'short'], array_keys($d['check']['accounts'][0]));
        $this->assertSame(400000, $d['check']['accounts'][0]['left_minor']);
        $this->assertFalse($d['check']['short']);
        $id = $d['id'];

        // Replacing a draft's lines; asking again; the list.
        $d = $this->putJson('/api/v1/rentals/payout-runs/'.$id, ['lines' => [['amount_minor' => 150000] + $line]], $h)->assertOk()->json('data');
        $this->assertSame(150000, $d['total_minor']);
        $this->assertSame(150000, $this->getJson('/api/v1/rentals/payout-runs/'.$id, $h)->assertOk()->json('data.total_minor'));
        $list = $this->getJson('/api/v1/rentals/payout-runs', $h)->assertOk();
        $this->assertSame(1, $list->json('meta.pagination.total'));
        $this->assertSame(1, $list->json('data.0.lines_count'));
        $this->assertSame(['id', 'number', 'status', 'paid_on', 'posted_at', 'created_at', 'lines_count', 'total_minor'], array_keys($list->json('data.0')));

        // The sheet to take to the bank, signed.
        foreach (['pdf', 'csv', 'xlsx'] as $format) {
            $link = $this->getJson('/api/v1/rentals/payout-runs/'.$id.'/sheet?format='.$format, $h)->assertOk()->json('data');
            $this->assertSame(['url', 'expires_in', 'format'], array_keys($link));
            $this->get(str_replace(config('app.url'), '', $link['url']))->assertOk();
        }
        $this->getJson('/api/v1/rentals/payout-runs/'.$id.'/sheet?format=doc', $h)->assertStatus(422)->assertJsonPath('errors.code', 'unknown_format');

        // Short of cash: the server says so, and nothing is paid.
        $big = $this->postJson('/api/v1/rentals/payout-runs', ['lines' => [['landlord_id' => $w['margaret']->id, 'amount_minor' => 540000, 'method' => 'bank']]], $h)->assertStatus(201)->json('data');
        $this->assertTrue($big['check']['short']);
        $this->assertNotNull($big['check']['accounts'][0]['short']);
        $this->postJson('/api/v1/rentals/payout-runs/'.$big['id'].'/post', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'run_changed');
        $this->assertSame(0, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->postJson('/api/v1/rentals/payout-runs/'.$big['id'].'/cancel', [], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/rentals/payout-runs/'.$big['id'].'/cancel', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'run_not_draft');

        // Paid: one payout per line, with its reference; once.
        $this->postJson('/api/v1/rentals/payout-runs/'.$id.'/post', ['paid_on' => '2026-03-27'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
        $lineId = $d['lines'][0]['id'];
        $key = ['Idempotency-Key' => 'post-run'];
        $posted = $this->postJson('/api/v1/rentals/payout-runs/'.$id.'/post', ['paid_on' => '2026-03-26', 'references' => [$lineId => 'CHQ-1001']], $h + $key)->assertOk();
        $this->assertSame('posted', $posted->json('data.status'));
        $this->assertSame('CHQ-1001', $posted->json('data.lines.0.reference'));
        $this->assertNotNull($posted->json('data.lines.0.payout_id'));
        $this->assertSame(1, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(390000, LandlordAccounts::balance($w['margaret']));
        $this->assertSame('true', $this->postJson('/api/v1/rentals/payout-runs/'.$id.'/post', ['paid_on' => '2026-03-26', 'references' => [$lineId => 'CHQ-1001']], $h + $key)->assertOk()->headers->get('Idempotent-Replay'));
        $this->postJson('/api/v1/rentals/payout-runs/'.$id.'/post', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'run_not_draft');
        $this->assertSame(1, RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertBooksAgree($cid);
        $this->fixture('payout_run', $posted->json());
    }

    public function test_an_owner_run_account_has_no_runs_sheets_or_drawings(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount('owner');
        $h = $this->h($owner);
        [$p] = $this->building($owner, 1, 100000);
        foreach (['payout-runs', 'payout-runs/candidates', 'landlords/1/sheet', 'properties/'.$p->id.'/handover-preview?landlord_id=1'] as $uri) {
            $this->getJson('/api/v1/rentals/'.$uri, $h)->assertStatus(422)->assertJsonPath('errors.code', 'agency_only');
        }
        $this->postJson('/api/v1/rentals/cash/drawings', ['method' => 'cash', 'amount_minor' => 100], $h)->assertStatus(422)->assertJsonPath('errors.code', 'agency_only');
        $this->assertTrue($this->getJson('/api/v1/rentals/month-end', $h)->assertOk()->json('data.owner'));
    }

    public function test_whose_money_it_is_on_any_day(): void
    {
        $w = $this->world();
        $h = $this->h($w['accountant']);
        $res = $this->getJson('/api/v1/rentals/money-position', $h)->assertOk();
        $d = $res->json('data');
        $this->assertSame(['currency', 'as_of', 'cash_minor', 'owed_to_landlords_minor', 'owed_back_by_landlords_minor', 'deposits_minor', 'credit_minor', 'agency_own_minor', 'held_for_others_minor'], array_keys($d));
        $this->assertSame('2026-03-26', $d['as_of']);
        $expected = MoneyPosition::asOf((int) $w['company']->id, '2026-03-26');
        foreach ($expected as $k => $v) {
            $this->assertSame($v, $d[$k], $k);
        }
        $this->assertSame(300000, $this->getJson('/api/v1/rentals/money-position?as_of=2026-02-15', $h)->assertOk()->json('data.cash_minor'));
        $this->getJson('/api/v1/rentals/money-position?as_of=2026-03-27', $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
        $this->getJson('/api/v1/rentals/money-position?as_of=soon', $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $this->fixture('money_position', $res->json());
    }

    public function test_the_agency_takes_out_its_own_money_only_by_those_who_may_and_puts_it_back_with_a_reason(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $cid = (int) $w['company']->id;
        $this->postJson('/api/v1/rentals/cash/drawings', ['method' => 'cash', 'amount_minor' => 1000], $this->h($w['accountant']))->assertStatus(403);

        $body = ['method' => 'cash', 'amount_minor' => 40000, 'on' => '2026-03-26', 'note' => 'Office rent'];
        $own = MoneyPosition::asOf($cid, '2026-03-26')['agency_own_minor'];
        $this->assertSame(60000, $own);
        $d = $this->postJson('/api/v1/rentals/cash/drawings', $body, $h)->assertStatus(201)->json('data');
        $this->assertSame(['id', 'type', 'amount_minor', 'on', 'description', 'balances', 'method'], array_keys($d));
        $this->assertSame('agency_drawing', $d['type']);
        $this->assertSame(40000, $d['amount_minor']);
        $this->assertSame(560000, $d['balances']['cash']);

        // More than its own: asks first, then takes it. More than there is: never.
        $refused = $this->postJson('/api/v1/rentals/cash/drawings', ['amount_minor' => 30000] + $body, $h)->assertStatus(422);
        $refused->assertJsonPath('errors.code', 'agency_own_below_zero');
        $this->assertSame(20000, $refused->json('errors.agency_own_minor'));
        $second = $this->postJson('/api/v1/rentals/cash/drawings', ['amount_minor' => 30000, 'below_zero_agreed' => true] + $body, $h)->assertStatus(201)->json('data');
        $this->postJson('/api/v1/rentals/cash/drawings', ['amount_minor' => 9000000, 'below_zero_agreed' => true] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->postJson('/api/v1/rentals/cash/drawings', ['method' => 'gold'] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');
        $this->postJson('/api/v1/rentals/cash/drawings', ['amount_minor' => 1.5] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
        $this->postJson('/api/v1/rentals/cash/drawings', ['on' => '2026-03-27'] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');

        // Back, with a reason, once. It reads as the row of GET rentals/cash.
        $this->assertContains($second['id'], array_column($this->getJson('/api/v1/rentals/cash', $h)->assertOk()->json('data.rows'), 'id'));
        $this->postJson('/api/v1/rentals/cash/drawings/'.$second['id'].'/reverse', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        $back = $this->postJson('/api/v1/rentals/cash/drawings/'.$second['id'].'/reverse', ['reason' => 'Taken by mistake'], $h)->assertOk()->json('data');
        $this->assertSame('reversal', $back['type']);
        $this->assertSame($second['id'], $back['reverses_id']);
        $this->assertSame(30000, $back['amount_minor']);
        $this->postJson('/api/v1/rentals/cash/drawings/'.$second['id'].'/reverse', ['reason' => 'Again'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'already_reversed');
        $this->postJson('/api/v1/rentals/cash/drawings/999999/reverse', ['reason' => 'x'], $h)->assertStatus(404);
        $this->assertSame(560000, CashBook::balances($cid)['cash']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_handover_preview_says_what_would_happen_and_writes_nothing(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $url = '/api/v1/rentals/properties/'.$w['p']->id.'/handover-preview?landlord_id='.$w['peter']->id;
        $d = $this->getJson($url.'&from=2026-03-26', $h)->assertOk()->json('data');
        $this->assertKeys($d, ['old', 'new', 'from', 'waits', 'leases', 'bills_move', 'bills_stay', 'landlord_deposits', 'landlord_deposits_minor', 'agency_deposits_minor', 'credit_minor', 'recorded_since_minor', 'currency'], 'preview');
        $this->assertSame((int) $w['margaret']->id, $d['old']['id']);
        $this->assertSame(540000, $d['old']['balance_minor']);
        $this->assertSame((int) $w['peter']->id, $d['new']['id']);
        $this->assertFalse($d['waits']);
        $this->assertCount(2, $d['leases']);
        $this->assertTrue($this->getJson($url.'&from=2026-04-01', $h)->assertOk()->json('data.waits'), 'a day ahead waits for its day');
        $this->assertSame((int) $w['margaret']->id, (int) DB::table('rental_properties')->where('id', $w['p']->id)->value('landlord_id'));
        $this->getJson('/api/v1/rentals/properties/'.$w['p']->id.'/handover-preview?landlord_id=999999', $h)->assertStatus(422)->assertJsonPath('errors.code', 'landlord_required');
    }

    public function test_a_quote_above_the_limit_is_put_to_the_landlord_who_answers_or_the_office_writes_it_down(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        app(Landlords::class)->update($w['owner'], $w['margaret'], ['expense_limit_minor' => 100000]);
        $t = $w['urgentHere'];
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/ask-landlord', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_to_ask');

        // The quote: on the existing save. The office sees the limit; a caretaker doesn't.
        $saved = $this->putJson('/api/v1/rentals/maintenance/'.$t->id, ['quote_minor' => 250000], $h)->assertOk()->json('data');
        $this->assertSame(250000, $saved['quote_minor']);
        $this->assertSame(100000, $saved['limit_minor']);
        $this->assertTrue($saved['needs_answer']);
        $this->assertSame(['status' => 'none', 'via' => null, 'note' => null, 'at' => null], $saved['approval']);
        $this->putJson('/api/v1/rentals/maintenance/'.$t->id, ['quote_minor' => 1.5], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
        $care = $this->h($w['caretaker']);
        $seen = $this->getJson('/api/v1/rentals/maintenance/'.$t->id, $care)->assertOk()->json('data');
        $this->assertSame(250000, $seen['quote_minor']);
        foreach (['approval', 'limit_minor', 'needs_answer'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $seen, $hidden);
        }
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/ask-landlord', [], $care)->assertStatus(403);
        $this->postJson('/api/v1/rentals/maintenance/'.$w['urgentThere']->id.'/ask-landlord', [], $care)->assertStatus(404);

        // Asked: the message for this person's own phone.
        $asked = $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/ask-landlord', [], $h)->assertOk()->json('data');
        $this->assertSame(['maintenance', 'message'], array_keys($asked));
        $this->assertSame('asked', $asked['maintenance']['approval']['status']);
        $this->assertSame(['text', 'whatsapp', 'sms', 'phone', 'landlord', 'has_sign_in'], array_keys($asked['message']));
        $this->assertStringContainsString('Kitchen tap', $asked['message']['text']);
        $this->assertStringContainsString('Please reply', $asked['message']['text']);
        $this->assertStringStartsWith('https://wa.me/', (string) $asked['message']['whatsapp']);

        // The office writes down an answer it had by phone.
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/landlord-answer', ['via' => 'phone', 'who' => 'Margaret'], $h)->assertStatus(422);
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/landlord-answer', ['yes' => true, 'via' => 'pigeon', 'who' => 'Margaret'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'via_invalid');
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/landlord-answer', ['yes' => true, 'via' => 'phone', 'who' => ' '], $h)->assertStatus(422)->assertJsonPath('errors.code', 'who_required');
        $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/landlord-answer', ['yes' => true, 'via' => 'phone', 'who' => 'Margaret'], $care)->assertStatus(403);
        $yes = $this->postJson('/api/v1/rentals/maintenance/'.$t->id.'/landlord-answer', ['yes' => true, 'via' => 'phone', 'who' => 'Margaret', 'note' => 'Go ahead'], $h)->assertOk()->json('data');
        $this->assertSame('yes', $yes['approval']['status']);
        $this->assertSame('phone', $yes['approval']['via']);
        $this->assertSame('Margaret: Go ahead', $yes['approval']['note']);
        $this->assertFalse($yes['needs_answer']);

        // A plain save with no quote leaves it alone; older phones' answers still hold what they read.
        $list = $this->getJson('/api/v1/rentals/maintenance', $h)->assertOk()->json('data');
        $this->assertSame(250000, collect($list)->firstWhere('id', (int) $t->id)['quote_minor']);
        $this->assertSame('in_progress', $this->putJson('/api/v1/rentals/maintenance/'.$t->id, ['status' => 'in_progress'], $h)->assertOk()->json('data.status'));
        $this->assertSame(250000, app(Maintenance::class)->update($w['owner'], $t->fresh(), [])->quote_minor);
    }
}
