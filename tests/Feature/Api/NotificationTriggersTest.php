<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Services\Notifications\Reminders;
use App\Services\Notifications\Triggers;
use App\Services\Rentals\Payments\Payments;
use App\Services\Team\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesKin;
use Tests\Concerns\MakesRentals;
use Tests\Concerns\MakesUnity;

/**
 * The engage plan's triggers (§3): the event-driven ones tell the others, never the person who did it; the scheduled ones
 * (notifications:engage) go once per account and day however often the scheduler runs. Invented names only.
 */
class NotificationTriggersTest extends ApiTestCase
{
    use MakesKin, MakesRentals, MakesUnity;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.firebase.credentials' => '']); // inbox only: the push itself is PushNotificationsTest's
        $this->travelTo(Carbon::parse('2026-11-25 08:20', 'Africa/Kampala')); // a Wednesday morning
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function inbox(int $userId, ?string $type = null)
    {
        return DB::table('app_notifications')->where('user_id', $userId)->when($type, fn ($q) => $q->where('type', $type))->orderBy('id')->get();
    }

    public function test_pledges_and_a_role_change_tell_the_others_not_the_doer(): void
    {
        $owner = $this->registerTenant();
        $h = $this->auth($owner['token']);
        $company = Company::withoutGlobalScopes()->findOrFail($owner['company_id']);
        $manager = app(TeamService::class)->createMember($company, User::withoutGlobalScopes()->findOrFail($owner['user_id']), 'Grace Sample',
            'grace_'.uniqid().'@example.com', null, 'Secret-pass-123', 'manager');

        $program = (int) $this->postJson('/api/v1/budget-programs', ['name' => 'Wedding '.uniqid()], $h)->assertStatus(201)->json('data.id');
        $pledge = (int) $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $program, 'name' => 'Okello Sample', 'amount' => 100000], $h)
            ->assertStatus(201)->json('data.id');
        $made = $this->inbox((int) $manager->id, 'pledges')->first();
        $this->assertNotNull($made, 'the manager hears of the pledge');
        $this->assertSame("pledge/{$pledge}", $made->link);
        $this->assertStringContainsString('Okello Sample pledged UGX 100,000', $made->body);
        $this->assertCount(0, $this->inbox((int) $owner['user_id'], 'pledges'), 'the one who wrote it is not told');

        $this->postJson("/api/v1/contribution-records/{$pledge}/payments", ['amount' => 100000, 'method' => 'cash'], $h)->assertStatus(201);
        $paid = $this->inbox((int) $manager->id, 'pledges')->last();
        $this->assertSame('Pledge payment', $paid->title);
        $this->assertStringContainsString('fully paid', $paid->body);

        // A budget line crossing its target is told once; one already over it is not told again.
        $item = new \App\Models\BudgetItem();
        $item->forceFill(['id' => 991, 'company_id' => $company->id, 'budget_program_id' => $program, 'name' => 'Tent hire']);
        Triggers::budgetOverspent($item, '900', '1200', '1000');
        Triggers::budgetOverspent($item, '1100', '1300', '1000');
        Triggers::budgetOverspent($item, '900', '1200', '1000');
        $over = $this->inbox((int) $manager->id, 'budget');
        $this->assertCount(1, $over);
        $this->assertSame("budget/{$program}", $over->first()->link);

        app(TeamService::class)->setRole($company, $manager, 'cashier');
        $this->assertSame('You are now Cashier on '.$company->name.'.', $this->inbox((int) $manager->id, 'team')->last()->body);
        $count = $this->inbox((int) $manager->id, 'team')->count();
        app(TeamService::class)->setRole($company, $manager, 'cashier');
        $this->assertSame($count, $this->inbox((int) $manager->id, 'team')->count(), 'the same role again is no news');
    }

    public function test_rent_repairs_family_and_membership_events_reach_the_right_people(): void
    {
        [$rentals, $landlordOwner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($landlordOwner, 1);
        $lease = $this->tenantIn($landlordOwner, $units[0], '2026-11-01', ['tenant' => ['name' => 'Tenant Sample', 'phone' => '0701555111']]);
        app(Payments::class)->receive(null, $lease->fresh(), ['amount_minor' => 300000, 'method' => 'cash']);
        $rent = $this->inbox((int) $landlordOwner->id, 'rent_paid')->first();
        $this->assertNotNull($rent);
        $this->assertSame("rent/{$lease->id}", $rent->link);
        $this->assertSame('estate-ledger', $rent->app);
        $this->assertStringContainsString('Tenant Sample paid UGX 300,000', $rent->body);

        // Family: a person added to a branch reaches its coordinator, not the head who added them.
        [$family, $head, $token] = $this->kinAccount();
        $coordinator = $this->kinStaff($family, 'kin_branch_coordinator');
        $branch = new \App\Models\KinBranch(['company_id' => $family->id, 'name' => 'Branch of Amos', 'coordinator_user_id' => $coordinator->id]);
        $branch->save();
        $person = (int) $this->postJson('/api/v1/kin/people', ['first_name' => 'Amos', 'last_name' => 'Sample', 'branch_id' => $branch->id], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()->json('data.id');
        $kin = $this->inbox((int) $coordinator->id, 'kin_people')->first();
        $this->assertSame("kin/person/{$person}", $kin->link);
        $this->assertSame('family-roots', $kin->app);
        $this->assertCount(0, $this->inbox((int) $head->id, 'kin_people'));

        // Membership: money recorded by the treasurer reaches the chair.
        [$assoc, $chair, $chairToken] = $this->unityAccount();
        $ch = ['Authorization' => 'Bearer '.$chairToken];
        $cat = (int) $this->postJson('/api/v1/unity/categories', ['name' => 'Annual', 'fee_minor' => 50000, 'period_months' => 12], $ch)->assertCreated()->json('data.id');
        $member = (int) $this->postJson('/api/v1/unity/members', ['first_name' => 'Bena', 'last_name' => 'Sample', 'category_id' => $cat], $ch)->assertCreated()->json('data.id');
        $treasurer = $this->unityStaff($assoc, 'unity_treasurer');
        $this->postJson('/api/v1/unity/subscriptions', ['member_id' => $member, 'paid_minor' => 50000], ['Authorization' => 'Bearer '.$treasurer->createToken('t')->plainTextToken])
            ->assertCreated();
        $paid = $this->inbox((int) $chair->id, 'unity_paid')->first();
        $this->assertSame("member/{$member}", $paid->link);
        $this->assertStringContainsString('Bena Sample paid UGX 50,000', $paid->body);

        foreach (DB::table('app_notifications')->whereIn('app', ['estate-ledger', 'family-roots', 'unitydesk'])->get() as $row) {
            $this->assertDoesNotMatchRegularExpression('/Budget Pro|Shelfie|Kuku|Pledge &|Akiba|Estate Ledger|Family Roots|UnityDesk/i', $row->title.' '.$row->body);
        }
    }

    public function test_a_family_hears_new_announcements_and_gatherings(): void
    {
        [$family, $head, $token] = $this->kinAccount();
        $member = $this->kinStaff($family, 'kin_member');
        $auth = ['Authorization' => 'Bearer '.$token];
        $a = (int) $this->postJson('/api/v1/kin/announcements', ['title' => 'Clan meeting moved', 'body' => 'We meet at the old home.'], $auth)->assertSuccessful()->json('data.id');
        $e = (int) $this->postJson('/api/v1/kin/events', ['kind' => 'meeting', 'title' => 'Family meeting', 'starts_at' => '2026-12-05 10:00:00', 'venue' => 'The old home'], $auth)
            ->assertSuccessful()->json('data.event.id');

        $news = $this->inbox((int) $member->id, 'kin_announcement')->first();
        $this->assertSame(['Clan meeting moved', "kin/announcement/{$a}", 'family-roots'], [$news?->title, $news?->link, $news?->app]);
        $meet = $this->inbox((int) $member->id, 'kin_event')->first();
        $this->assertSame(['Family meeting', "kin/event/{$e}"], [$meet?->title, $meet?->link]);
        $this->assertStringContainsString('The old home', $meet->body);
        $this->assertSame([], $this->inbox((int) $head->id)->whereIn('type', ['kin_announcement', 'kin_event'])->all(), 'never the one who posted it');

        // One set for later says nothing now.
        $this->postJson('/api/v1/kin/announcements', ['title' => 'Later news', 'published_at' => '2026-12-20 09:00:00'], $auth)->assertSuccessful();
        $this->assertCount(1, $this->inbox((int) $member->id, 'kin_announcement'));
    }

    public function test_scheduled_reminders_go_once_a_day(): void
    {
        // Rent due in three days.
        [$rentals, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1);
        $lease = $this->tenantIn($owner, $units[0], '2026-11-01');
        DB::table('rental_charges')->where('lease_id', $lease->id)->where('status', 'open')->update(['due_date' => '2026-11-28']);

        // A birthday today (an exact date) and one known only by the year (not told).
        [$family, $head, $token] = $this->kinAccount();
        $h = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/v1/kin/people', ['first_name' => 'Ruth', 'last_name' => 'Sample', 'date_of_birth' => '1990-11-25'], $h)->assertCreated();
        $yearOnly = (int) $this->postJson('/api/v1/kin/people', ['first_name' => 'Old', 'last_name' => 'Sample', 'date_of_birth' => '1950-11-25'], $h)->assertCreated()->json('data.id');
        DB::table('sacco_members')->where('id', $yearOnly)->update(['birth_date_precision' => 'year']);

        // A phone on an old version of its app.
        $shop = $this->registerTenant();
        config(['mobile.apps.shelfie.latest_version' => '3.4.0']);
        $this->postJson('/api/v1/devices/register', ['device_id' => 'phone-old-0001', 'push_token' => 'tok-old', 'push_provider' => 'fcm', 'app' => 'shelfie'], $this->auth($shop['token']))->assertOk();
        Device::withoutGlobalScopes()->where('device_id', 'phone-old-0001')->update(['app_version' => '3.3.0']);

        $this->travelTo(Carbon::parse('2026-11-25 10:20', 'Africa/Kampala'));
        $counts = app(Reminders::class)->run();
        $this->assertSame(1, $counts['rent_due'] ?? 0);
        $this->assertSame(1, $counts['app_update'] ?? 0);

        $due = $this->inbox((int) $owner->id, 'rent_due')->first();
        $this->assertStringContainsString('falls due on Sat 28 Nov', $due->body);
        $birthday = $this->inbox((int) $head->id, 'kin_dates')->first();
        $this->assertSame('Birthday today: Ruth Sample (36).', $birthday->body);
        $this->assertStringContainsString('Version 3.4.0 of Shelfie', $this->inbox((int) $shop['user_id'], 'app_update')->first()->body);

        $this->travelTo(Carbon::parse('2026-11-25 10:40', 'Africa/Kampala'));
        $this->artisan('notifications:engage')->assertSuccessful();
        $this->assertCount(1, $this->inbox((int) $owner->id, 'rent_due'), 'once a day, however often it runs');
        $this->assertCount(1, $this->inbox((int) $head->id, 'kin_dates'));
        $this->assertCount(1, $this->inbox((int) $shop['user_id'], 'app_update'));

        $this->travelTo(Carbon::parse('2026-11-25 14:20', 'Africa/Kampala'));
        $this->assertSame([], app(Reminders::class)->run(), 'nothing outside the windows');
    }

    public function test_every_part_runs_cleanly_for_every_kind_of_account(): void
    {
        $users = [];
        foreach ([['shop'], ['budget'], ['poultry'], ['sacco'], ['unity'], ['kin'], ['rentals']] as $modules) {
            $t = $this->registerTenant(['modules' => $modules]);
            $users[$modules[0]] = (int) $t['user_id'];
        }
        DB::table('contribution_records')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => DB::table('admin_users')->where('id', $users['budget'])->value('company_id'),
            'budget_program_id' => 0, 'name' => 'Okello Sample', 'amount' => 5000, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Log::spy();
        foreach (['2026-11-30 08:20', '2026-11-29 18:20'] as $at) { // a Monday morning (arrears lists), a Sunday evening (the week)
            $this->travelTo(Carbon::parse($at, 'Africa/Kampala'));
            app(Reminders::class)->run();
        }
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        $week = $this->inbox($users['budget'], 'weekly_digest')->first();
        $this->assertSame('Your week', $week?->title);
        $this->assertStringContainsString('UGX 5,000 pledged', $week->body);
    }
}
