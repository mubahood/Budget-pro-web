<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\EventBook;
use App\Services\Budget\EventReports;
use App\Services\Onboarding\AppDemoBuilder;
use App\Services\Onboarding\DemoPledgeExtras;
use App\Services\Onboarding\PublicDemo;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pledge & Budget's own demo (the review's PB-30): real limits per role, no negative In hand, the figures agree, and the story a committee recognises. */
class PledgeBudgetDemoTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = 'pledge-and-budget';

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.enabled' => true]);
        foreach (PublicDemo::accounts() as $account) {
            DB::table('admin_users')->where('username', PublicDemo::spec($account)['username'])->update(['username' => 'demo-local-'.uniqid()]);
        }
        app(PublicDemo::class)->run(false, self::ACCOUNT);
    }

    /** @return array<string, array<string, mixed>> event name => its figures */
    private function events(): array
    {
        $out = [];
        foreach (app(EventBook::class)->events(PublicDemo::company(self::ACCOUNT), 'all') as $e) {
            $out[(string) $e['name']] = $e;
        }

        return $out;
    }

    private function role(string $role): User
    {
        return PublicDemo::roleUser(self::ACCOUNT, $role);
    }

    public function test_in_hand_is_never_negative_and_the_figures_agree_on_every_event(): void
    {
        $this->assertCount(3, $this->events());
        foreach ($this->events() as $name => $e) {
            $this->assertGreaterThanOrEqual(0, Decimal::cmp((string) $e['in_hand'], '0'), "{$name}: In hand {$e['in_hand']}");
            $this->assertSame(0, Decimal::cmp(Decimal::add(Decimal::sub((string) $e['pledged'], (string) $e['collected']), (string) $e['extra']), (string) $e['still_owed']), "{$name}: Pledged - Collected + Extra = Still owed");
        }
        $this->assertSame(0, Decimal::cmp((string) $this->events()['Brian & Grace Kukyala']['budget'], (string) $this->events()['Brian & Grace Kukyala']['paid_out']), 'the visit is all paid out');
    }

    public function test_the_upkeep_keeps_it_true_all_day_and_adds_nothing_twice(): void
    {
        $demo = app(PublicDemo::class);
        $co = PublicDemo::company(self::ACCOUNT);
        $demo->run(false, self::ACCOUNT);
        $follow = DB::table('pledge_follow_ups')->where('company_id', $co->id)->count();
        $out = DB::table('budget_payments')->where('company_id', $co->id)->count();
        $this->assertSame('healed', $demo->run(false, self::ACCOUNT)['action']);
        $this->assertSame($follow, DB::table('pledge_follow_ups')->where('company_id', $co->id)->count(), 'a second round in the same hour adds no reminder');
        $this->assertSame($out, DB::table('budget_payments')->where('company_id', $co->id)->count(), 'nor a payment out');
        foreach ($this->events() as $name => $e) {
            $this->assertGreaterThanOrEqual(0, Decimal::cmp((string) $e['in_hand'], '0'), $name);
        }
    }

    public function test_a_visitor_who_pays_out_too_much_does_not_leave_in_hand_negative(): void
    {
        $co = PublicDemo::company(self::ACCOUNT);
        $event = $this->events()['Brian & Grace Kwanjula'];
        DB::table('budget_items')->where('budget_program_id', $event['id'])->where('company_id', $co->id)->update(['invested_amount' => DB::raw('target_amount')]);
        $this->assertTrue(DemoPledgeExtras::inHandBelowZero($co));

        $this->assertSame('rebuilt', app(PublicDemo::class)->run(false, self::ACCOUNT)['action']);
        $this->assertFalse(DemoPledgeExtras::inHandBelowZero(PublicDemo::company(self::ACCOUNT)));
    }

    public function test_each_button_has_the_real_limits_of_its_seat(): void
    {
        $roles = PublicDemo::roles(self::ACCOUNT);
        $this->assertSame(['owner', 'treasurer', 'secretary', 'member', 'viewer'], array_keys($roles));
        $wedding = (int) $this->events()['Brian & Grace Wedding']['id'];
        $co = PublicDemo::company(self::ACCOUNT);
        foreach (['treasurer' => 'treasurer', 'secretary' => 'secretary', 'member' => 'member', 'viewer' => 'viewer'] as $button => $seat) {
            $user = $this->role($button);
            $this->assertSame((int) $co->id, (int) $user->company_id);
            $this->assertSame('viewer', DB::table('company_members')->where('company_id', $co->id)->where('user_id', $user->id)->value('role'), 'the seat decides, not the account');
            $this->assertSame($seat, BudgetAccess::roleOn($user, $wedding));
        }
        $this->assertTrue(BudgetAccess::can(PublicDemo::owner(self::ACCOUNT), $wedding, BudgetAccess::COMMITTEE));
        $this->assertTrue(BudgetAccess::can($this->role('treasurer'), $wedding, BudgetAccess::PAYMENT));
        $this->assertTrue(BudgetAccess::can($this->role('treasurer'), $wedding, BudgetAccess::SPENDING));
        $this->assertFalse(BudgetAccess::can($this->role('treasurer'), $wedding, BudgetAccess::COMMITTEE), 'the treasurer does not run the committee');
        foreach (['secretary', 'member'] as $button) {
            $this->assertTrue(BudgetAccess::can($this->role($button), $wedding, BudgetAccess::PLEDGE));
            $this->assertFalse(BudgetAccess::can($this->role($button), $wedding, BudgetAccess::PAYMENT), "{$button} records no money");
        }
        $this->assertFalse(BudgetAccess::can($this->role('viewer'), $wedding, BudgetAccess::PLEDGE));
        $this->assertFalse(BudgetAccess::canSeePhones($this->role('viewer'), $wedding));
        $this->assertTrue(BudgetAccess::canOpen($this->role('viewer'), $wedding));
    }

    public function test_the_heal_puts_a_changed_seat_back(): void
    {
        $wedding = (int) $this->events()['Brian & Grace Wedding']['id'];
        $doreen = $this->role('treasurer');
        DB::table('budget_program_members')->where('budget_program_id', $wedding)->where('user_id', $doreen->id)->update(['role' => 'viewer']);
        $allan = $this->role('secretary');
        DB::table('budget_program_members')->where('budget_program_id', $wedding)->where('user_id', $allan->id)->update(['is_deleted' => 1]);

        app(PublicDemo::class)->run(false, self::ACCOUNT);

        $this->assertSame('treasurer', BudgetAccess::roleOn($doreen, $wedding));
        $this->assertSame('secretary', BudgetAccess::roleOn($allan, $wedding));
    }

    public function test_the_story_is_there_and_the_days_are_saturdays(): void
    {
        $events = $this->events();
        $wedding = $events['Brian & Grace Wedding'];
        $co = PublicDemo::company(self::ACCOUNT);
        foreach ($events as $name => $e) {
            $this->assertSame(Carbon::SATURDAY, Carbon::parse((string) $e['date'])->dayOfWeek, "{$name} is on a Saturday");
        }
        $this->assertSame(1, DB::table('contribution_records')->where('budget_program_id', $wedding['id'])->whereNotNull('withdrawn_at')->count());
        $this->assertSame(1, app(EventBook::class)->inKind((int) $co->id, (int) $wedding['id'])->count());
        $this->assertGreaterThan(0, Decimal::cmp((string) $wedding['extra'], '0'), 'one paid over the pledge');
        $holders = array_column(app(EventBook::class)->holders((int) $co->id, (int) $wedding['id']), 'name');
        $this->assertContains('Doreen Natukunda', $holders);
        $this->assertContains('Allan Kyeyune', $holders);
        $notes = array_column(app(EventBook::class)->insights($co, app(EventBook::class)->event($co, (int) $wedding['id']), fn ($m) => (string) $m), 'key');
        $this->assertContains('double_payment', $notes);
        $this->assertContains('promised', $notes);
        $this->assertGreaterThan(0, DB::table('pledge_follow_ups')->where('budget_program_id', $wedding['id'])->whereDate('promised_on', '>', now())->count(), 'promises still to come');
        $paidOut = DB::table('budget_payments')->where('budget_program_id', $wedding['id'])->where('is_deleted', 0)->where('source', 'payment')->whereNotNull('paid_to');
        $this->assertGreaterThan(5, $paidOut->count(), 'spending with suppliers');
        $this->assertSame(0, DB::table('budget_payments')->where('company_id', $co->id)->whereIn('source', ['opening', 'item_edit'])->count());

        $kukyala = $events['Brian & Grace Kukyala'];
        $this->assertFalse($kukyala['open']);
        $this->assertGreaterThan(5, DB::table('pledge_follow_ups')->where('budget_program_id', $kukyala['id'])->where('kind', 'thanks')->count());
        $final = app(EventReports::class)->finalAccount($co, (int) $kukyala['id']);
        $this->assertSame('Doreen Natukunda', $final['held_by'] ?? null);
        $this->assertSame(AppDemoBuilder::BUILD, PublicDemo::company(self::ACCOUNT)->onboarding_state['public_demo']['snapshot']['build']);
    }

    public function test_made_up_phones_stay_in_the_marked_block(): void
    {
        $this->assertMatchesRegularExpression('/^\+2567[0-8]555\d{4}$/', DemoPledgeExtras::phoneFor('Okello James'));
        $co = PublicDemo::company(self::ACCOUNT);
        $phones = DB::table('contribution_records')->where('company_id', $co->id)->whereNotNull('phone')->pluck('phone');
        $this->assertGreaterThan(50, $phones->count());
        $this->assertSame(0, $phones->reject(fn ($p) => preg_match('/^\+2567[0-8]555\d{4}$/', (string) $p) === 1)->count());
    }
}
