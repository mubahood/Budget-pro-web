<?php

namespace Tests\Feature\Billing;

use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use App\Services\Budget\CommitteeService;
use App\Services\Onboarding\RegistrationService;
use App\Services\Team\TeamService;
use Database\Seeders\PledgeBudgetPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The review's PB-33, decision D11: a viewer's seat is never counted on any plan, and Pledge & Budget's trial has room for 10. No price changes. */
class ViewerSeatsTest extends TestCase
{
    use DatabaseTransactions;

    /** @param list<string>|null $modules */
    private function account(?array $modules = ['budget', 'finance']): array
    {
        $r = app(RegistrationService::class)->register(array_filter(['first_name' => 'Grace', 'last_name' => 'Chair', 'email' => 'g'.uniqid('', true).'@example.test', 'password' => 'secret12345',
            'company_name' => 'Our committee', 'business_type' => $modules === ['budget', 'finance'] ? 'fundraising' : 'retail', 'currency' => 'UGX', 'country' => 'UG', 'timezone' => 'Africa/Kampala', 'modules' => $modules]), RegistrationService::PRODUCT_BUDGET, 'test');

        return [$r['user'], $r['company']];
    }

    private function member(Company $co, User $owner, string $role = 'viewer'): User
    {
        $co->subscription?->plan?->forceFill(['limits' => ['max_users' => 100]]);

        return (new TeamService())->createMember($this->unlimited($co), $owner, 'Helper '.Str::random(4), Str::random(8).'@example.test', null, 'secret12345', $role);
    }

    /** The same account with no limit, so a test can put people on it first. */
    private function unlimited(Company $co): Company
    {
        DB::table('subscriptions')->where('company_id', $co->id)->update(['plan_id' => null]);

        return Company::withoutGlobalScopes()->findOrFail($co->id);
    }

    private function onPlan(Company $co, int $maxUsers): Company
    {
        $plan = Plan::create(['name' => 'Test '.Str::random(4), 'slug' => 'test-'.Str::random(8), 'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => false, 'limits' => ['max_users' => $maxUsers], 'features' => []]);
        DB::table('subscriptions')->where('company_id', $co->id)->update(['plan_id' => $plan->id]);

        return Company::withoutGlobalScopes()->findOrFail($co->id);
    }

    private function event(Company $co): BudgetProgram
    {
        $p = new BudgetProgram();
        $p->forceFill(['company_id' => $co->id, 'name' => 'Wedding '.Str::random(4), 'title' => 'Wedding', 'status' => 'Active', 'is_default' => 'No']);
        $p->save();

        return $p;
    }

    public function test_a_viewer_seat_is_not_counted_and_any_other_seat_is(): void
    {
        [$owner, $co] = $this->account();
        $event = $this->event($co);
        $free = $this->member($co, $owner);
        $paid = $this->member($co, $owner);
        $none = $this->member($co, $owner);
        $quotas = new Quotas();
        $co = $this->onPlan($co, 50);
        $this->assertSame(4, $quotas->used($co, 'users'), 'all count before they hold viewer seats');

        (new CommitteeService())->add($owner, $event, ['user_id' => $free->id, 'role' => 'viewer']);
        $paidSeat = (new CommitteeService())->add($owner, $event, ['user_id' => $paid->id, 'role' => 'viewer']);
        $this->assertSame(2, $quotas->used($co, 'users'), 'the owner, and the viewer with no seat, who can read the account');

        (new CommitteeService())->setRole($paidSeat, 'treasurer');
        $this->assertSame(3, $quotas->used($co, 'users'));
        $this->assertSame((int) $none->company_id, (int) $co->id);
    }

    public function test_at_the_limit_a_viewer_can_still_be_seated_and_invited_and_a_treasurer_cannot(): void
    {
        [$owner, $co] = $this->account();
        $event = $this->event($co);
        $viewer = $this->member($co, $owner);
        $co = $this->onPlan($co, 2);
        (new CommitteeService())->add($owner, $event, ['user_id' => $viewer->id, 'role' => 'viewer']);
        $quotas = new Quotas();
        $this->assertSame(1, $quotas->used($co, 'users'));

        $invited = (new CommitteeService())->add($owner, $event, ['phone' => '0772555101', 'role' => 'viewer', 'name' => 'The couple']);
        $this->assertSame('invited', $invited->status);
        $this->assertSame(1, $quotas->used($co, 'users'), 'a viewer invite is free too');

        $treasurer = (new CommitteeService())->add($owner, $event, ['phone' => '0772555102', 'role' => 'treasurer', 'name' => 'Treasurer']);
        $this->assertSame(2, $quotas->used($co, 'users'));

        try {
            (new CommitteeService())->setRole(\App\Models\BudgetProgramMember::withoutGlobalScopes()->where('user_id', $viewer->id)->firstOrFail(), 'secretary');
            $this->fail('a third login past the plan');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode());
        }
        $this->assertSame('invited', $treasurer->status);
    }

    public function test_a_new_events_account_has_ten_logins_in_its_trial_and_a_shop_keeps_its_own(): void
    {
        (new PledgeBudgetPlanSeeder())->run();
        (new PledgeBudgetPlanSeeder())->run();
        $this->assertSame(1, Plan::where('slug', PlanLine::EVENTS_TRIAL)->count(), 'seeding twice adds nothing');
        $trial = Plan::where('slug', PlanLine::EVENTS_TRIAL)->firstOrFail();
        $this->assertFalse((bool) $trial->is_public);
        $this->assertEquals(0, (float) $trial->price_ugx);
        $this->assertSame(PlanLine::FAMILY, PlanLine::of($trial));

        [, $events] = $this->account();
        $this->assertSame(10, (new Quotas())->limit($events->fresh(), 'users'));
        [, $shop] = $this->account(['shop', 'finance']);
        $this->assertNotSame(PlanLine::EVENTS_TRIAL, $shop->fresh()->subscription?->plan?->slug);
    }

    public function test_a_plan_of_a_line_nobody_sells_is_never_offered(): void
    {
        $stale = Plan::create(['name' => 'Old line', 'slug' => 'old-line-'.Str::random(6), 'price' => 0, 'price_ugx' => 35000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'limits' => [], 'features' => ['line' => 'keyroll']]);
        $family = PlanLine::scope(Plan::where('is_public', true), PlanLine::FAMILY)->pluck('slug')->all();
        $this->assertNotContains($stale->slug, $family);
    }
}
