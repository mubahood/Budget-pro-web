<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\EventBook;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * PB-09 and decision D3: a seat decides who opens an event, and what they may take away. PB-10: the phone's printable
 * group list shows one event. PB-08: the ability to record spending.
 */
class EventSeatsTest extends PledgeTestCase
{
    /** Every cell of the review's table. */
    public function test_each_role_has_what_the_table_says(): void
    {
        $event = $this->program();
        $people = [];
        foreach (['chair', 'treasurer', 'secretary', 'member', 'viewer'] as $role) {
            $people[$role] = $this->teammate();
            $this->seat($event, $people[$role], $role);
        }
        $people['no seat'] = $this->teammate();
        $people['manager'] = $this->teammate('manager');
        $people['accountant'] = $this->teammate('accountant');
        $people['owner'] = ['user' => $this->ownerUser()];

        $table = [
            //                 open, phones, remind, export, names to WhatsApp
            'chair' => [true, true, true, true, true],
            'treasurer' => [true, true, true, true, true],
            'secretary' => [true, true, true, true, false],
            'member' => [true, true, true, false, false],
            'viewer' => [true, false, false, false, false],
            'no seat' => [false, false, false, false, false],
            'owner' => [true, true, true, true, true],
            'manager' => [true, true, true, true, true],
            'accountant' => [true, true, true, true, true],
        ];
        foreach ($table as $who => $row) {
            $u = $people[$who]['user'];
            $this->assertSame($row, [BudgetAccess::canOpen($u, $event), BudgetAccess::canSeePhones($u, $event), BudgetAccess::canRemind($u, $event), BudgetAccess::canExport($u, $event), BudgetAccess::canShareNames($u, $event)], $who);
        }
    }

    public function test_can_export_is_for_the_chair_the_treasurer_the_secretary_and_full_access(): void
    {
        $event = $this->program();
        $other = $this->program();
        $allowed = [];
        foreach (['chair' => true, 'treasurer' => true, 'secretary' => true, 'member' => false, 'viewer' => false] as $role => $may) {
            $mate = $this->teammate();
            $this->seat($event, $mate, $role);
            $allowed[$role] = BudgetAccess::canExport($mate['user'], $event);
            $this->assertFalse(BudgetAccess::canExport($mate['user'], $other), 'a seat on one event says nothing of another');
            $this->assertSame($may, $allowed[$role], $role);
        }
        $this->assertTrue(BudgetAccess::canExport($this->ownerUser(), $event));
        $this->assertTrue(BudgetAccess::canExport($this->teammate('manager')['user'], $event));
        $this->assertFalse(BudgetAccess::canExport($this->teammate()['user'], $event), 'no seat');
        $this->assertFalse(BudgetAccess::canExport(null, $event));
    }

    public function test_someone_switched_off_opens_nothing_and_nobody_is_a_null_user(): void
    {
        $event = $this->program();
        $this->assertFalse(BudgetAccess::canOpen(null, $event));
    }

    public function test_the_older_read_everything_lists_follow_the_seats_too(): void
    {
        $event = $this->program();
        $this->pledge($event, ['name' => 'Aunt Grace', 'phone' => '0772123456']);
        $viewer = $this->teammate();
        $seatless = $this->teammate();
        $this->seat($event, $viewer, 'viewer');

        // These endpoints answer with exit() (Utils::success), so their one query rule is tested directly.
        $records = fn ($u) => BudgetAccess::narrowLegacyList(ContributionRecord::withoutGlobalScopes()->where('company_id', $this->cid()), $u, ContributionRecord::class)->get();
        $this->assertCount(0, $records($seatless['user']));
        $seen = $records($viewer['user']);
        $this->assertCount(1, $seen);
        BudgetAccess::hidePhones($seen, $viewer['user']);
        $this->assertNull($seen->first()->phone);
        $this->assertCount(0, BudgetAccess::narrowLegacyList(BudgetProgram::withoutGlobalScopes()->where('company_id', $this->cid()), $seatless['user'], BudgetProgram::class)->get());
        $this->assertCount(1, BudgetAccess::narrowLegacyList(BudgetProgram::withoutGlobalScopes()->where('company_id', $this->cid()), $viewer['user'], BudgetProgram::class)->get());

        // A withdrawn pledge has left these lists too.
        $this->postJson('/api/v1/contribution-records/'.$seen->first()->id.'/withdraw', ['reason' => 'Entered twice'], $this->oh)->assertOk();
        $this->assertCount(0, $records($this->ownerUser()));
    }

    public function test_the_list_of_events_follows_the_seats(): void
    {
        $mine = $this->program('Mine');
        $other = $this->program('Other');
        $seated = $this->teammate();
        $seatless = $this->teammate();
        $this->seat($mine, $seated, 'viewer');
        $company = Company::withoutGlobalScopes()->findOrFail($this->cid());
        $book = app(EventBook::class);

        $ids = fn (?User $u) => array_column($book->events($company, 'all', null, $u), 'id');
        $this->assertEqualsCanonicalizing([$mine, $other], $ids($this->ownerUser()));
        $this->assertSame([$mine], $ids($seated['user']));
        $this->assertSame([], $ids($seatless['user']));
    }

    public function test_the_dashboards_events_card_follows_the_seats(): void
    {
        $mine = $this->program('Mine');
        $this->program('Other');
        $seated = $this->teammate();
        $seatless = $this->teammate();
        $this->seat($mine, $seated, 'viewer');
        $company = Company::withoutGlobalScopes()->findOrFail($this->cid());
        $summary = new \App\Services\Budget\BudgetSummary();

        $this->assertSame(2, $summary->for($company, null, 3, $this->ownerUser())['open_count']);
        $this->assertSame([$mine], array_column($summary->for($company, null, 3, $seated['user'])['events'], 'id'));
        $this->assertSame(0, $summary->for($company, null, 3, $seatless['user'])['open_count']);
    }

    public function test_the_api_reads_follow_the_seats(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['name' => 'Aunt Grace', 'phone' => '0772123456']);
        $viewer = $this->teammate();
        $member = $this->teammate();
        $seatless = $this->teammate();
        $this->seat($event, $viewer, 'viewer');
        $this->seat($event, $member, 'member');

        // No seat: nothing is seen, in any list.
        $this->getJson('/api/v1/budget-programs', $seatless['h'])->assertOk()->assertJsonMissing(['id' => $event]);
        $this->getJson("/api/v1/budget-programs/{$event}", $seatless['h'])->assertNotFound();
        $this->getJson('/api/v1/contribution-records', $seatless['h'])->assertOk()->assertDontSee('Aunt Grace');
        $this->getJson("/api/v1/contribution-records/{$p['id']}", $seatless['h'])->assertNotFound();
        $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $seatless['h'])->assertNotFound();
        $this->getJson('/api/v1/contribution-records/search?q=Aunt', $seatless['h'])->assertDontSee('Aunt Grace');
        $this->getJson('/api/v1/contribution-records?filter[phone]=%2B256772123456', $seatless['h'])->assertDontSee('Aunt Grace');
        $this->getJson("/api/v1/budget-programs/{$event}/figures", $seatless['h'])->assertNotFound();
        $this->getJson("/api/v1/budget-programs/{$event}/members", $seatless['h'])->assertNotFound();
        $this->getJson("/api/v1/budget-programs/{$event}/settings", $seatless['h'])->assertNotFound();
        $this->postJson("/api/v1/budget-programs/{$event}/print-link", [], $seatless['h'])->assertNotFound();

        // A viewer: names and amounts, not phones.
        $this->getJson("/api/v1/contribution-records/{$p['id']}", $viewer['h'])->assertOk()->assertJsonPath('data.name', 'Aunt Grace')->assertJsonPath('data.phone', null);
        $this->getJson('/api/v1/contribution-records', $viewer['h'])->assertOk()->assertSee('Aunt Grace')->assertDontSee('772123456');
        $this->getJson("/api/v1/contribution-records/{$p['id']}/payments", $viewer['h'])->assertOk()->assertJsonPath('data.pledge.phone', null);

        // A member: phones too.
        $this->getJson("/api/v1/contribution-records/{$p['id']}", $member['h'])->assertOk()->assertJsonPath('data.phone', '+256772123456');
    }

    public function test_the_abilities_answer_gains_spending_for_the_chair_and_the_treasurer_only(): void
    {
        $event = $this->program();
        $treasurer = $this->teammate();
        $secretary = $this->teammate();
        $this->seat($event, $treasurer, 'treasurer');
        $this->seat($event, $secretary, 'secretary');
        $this->assertTrue(BudgetAccess::can($treasurer['user'], $event, BudgetAccess::SPENDING));
        $this->assertFalse(BudgetAccess::can($secretary['user'], $event, BudgetAccess::SPENDING));
        $this->assertTrue(BudgetAccess::can($this->ownerUser(), $event, BudgetAccess::SPENDING));
    }

    // ── PB-10 ──

    public function test_the_phones_group_list_shows_one_event_and_a_tampered_event_is_refused(): void
    {
        $wedding = $this->program('Wedding');
        $kukyala = $this->program('Kukyala');
        $this->pledge($wedding, ['name' => 'Wedding Friend', 'category_id' => 'Friend', 'amount' => 100000]);
        $this->pledge($kukyala, ['name' => 'Kukyala Friend', 'category_id' => 'Friend', 'amount' => 300000]);

        $url = $this->postJson('/api/v1/contribution-records/print-link', ['category_id' => 'Friend', 'budget_program_id' => $wedding], $this->oh)->assertOk()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee('Wedding Friend')->assertDontSee('Kukyala Friend')->assertSee('100,000')->assertDontSee('400,000');

        $this->get(str_replace('program='.$wedding, 'program='.$kukyala, $path))->assertStatus(403);
    }

    public function test_an_older_app_sees_its_most_recent_open_event_and_a_line_saying_so(): void
    {
        $older = $this->program('Older');
        $newer = $this->program('Newer');
        $closed = $this->program('Closed');
        $this->pledge($older, ['name' => 'Older Friend', 'category_id' => 'Friend']);
        $this->pledge($newer, ['name' => 'Newer Friend', 'category_id' => 'Friend']);
        $this->pledge($closed, ['name' => 'Closed Friend', 'category_id' => 'Friend']);
        $this->close($closed);

        $url = $this->postJson('/api/v1/contribution-records/print-link', ['category_id' => 'Friend'], $this->oh)->assertOk()->json('data.url');
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk()
            ->assertSee('Newer Friend')->assertDontSee('Older Friend')->assertDontSee('Closed Friend')->assertSee('only. Update the app to choose the event.');
    }

    public function test_who_may_make_the_phones_list(): void
    {
        $event = $this->program();
        $secretary = $this->teammate();
        $member = $this->teammate();
        $viewer = $this->teammate();
        $this->seat($event, $secretary, 'secretary');
        $this->seat($event, $member, 'member');
        $this->seat($event, $viewer, 'viewer');

        $link = fn (array $h) => $this->postJson('/api/v1/contribution-records/print-link', ['category_id' => 'Friend', 'budget_program_id' => $event], $h);
        $link($secretary['h'])->assertOk();
        $link($member['h'])->assertForbidden();
        $link($viewer['h'])->assertForbidden();
    }

    // ── events:seat-team ──

    public function test_the_seat_command_is_a_dry_run_first_then_seats_viewers_once(): void
    {
        $open = $this->program('Open');
        $closed = $this->program('Closed');
        $this->close($closed);
        $viewer = $this->teammate();
        $manager = $this->teammate('manager');
        $seated = $this->teammate();
        $removed = $this->teammate();
        $this->seat($open, $seated, 'member');
        $seatId = $this->postJson("/api/v1/budget-programs/{$open}/members", ['user_id' => $removed['user_id'], 'role' => 'member'], $this->oh)->assertStatus(201)->json('data.id');
        $this->deleteJson("/api/v1/budget-programs/{$open}/members/{$seatId}", [], $this->oh)->assertOk();

        $seats = fn () => DB::table('budget_program_members')->where('budget_program_id', $open)->where('is_deleted', 0)->pluck('role', 'user_id')->all();
        $before = $seats();
        Artisan::call('events:seat-team', ['--company' => $this->cid()]);
        $this->assertStringContainsString('Dry run', Artisan::output());
        $this->assertSame($before, $seats(), 'a dry run writes nothing');

        Artisan::call('events:seat-team', ['--apply' => true, '--company' => $this->cid()]);
        $after = $seats();
        $this->assertSame('viewer', $after[$viewer['user_id']]);
        $this->assertSame('member', $after[$seated['user_id']], 'nobody gains a right or loses one');
        $this->assertArrayNotHasKey($manager['user_id'], $after, 'full access needs no seat');
        $this->assertArrayNotHasKey($removed['user_id'], $after, 'someone taken off stays off');
        $this->assertSame(0, DB::table('budget_program_members')->where('budget_program_id', $closed)->where('user_id', $viewer['user_id'])->count(), 'open events only');

        // Someone who joins later does not get a seat from a second run: the chair adds them.
        $later = $this->teammate();
        Artisan::call('events:seat-team', ['--apply' => true, '--company' => $this->cid()]);
        $this->assertStringContainsString('Already done', Artisan::output());
        $this->assertArrayNotHasKey($later['user_id'], $seats());
    }
}
