<?php

namespace Tests\Feature\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityCategory;
use App\Models\UnityChapter;
use App\Models\UnitySubscription;
use App\Services\Unity\Memberships;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/** UnityDesk memberships: periods, renewals, standing and the lists. Every name is made up. */
class UnityMembershipsTest extends ApiTestCase
{
    use MakesUnity;

    private Company $co;

    private UnityCategory $annual;

    private UnityCategory $life;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        [$this->co] = $this->unityAccount();
        $this->annual = UnityCategory::create(['company_id' => $this->co->id, 'name' => 'Ordinary annual', 'fee_minor' => 50000, 'period_months' => 12]);
        $this->life = UnityCategory::create(['company_id' => $this->co->id, 'name' => 'Life', 'fee_minor' => 500000, 'period_months' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $first, ?Company $co = null): SaccoMember
    {
        $m = new SaccoMember(['company_id' => ($co ?? $this->co)->id, 'kind' => 'individual', 'first_name' => $first, 'last_name' => 'Sample', 'gender' => 'female']);
        $m->save();

        return $m->fresh();
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    public function test_a_member_is_numbered_active_and_has_no_standing_until_a_period_is_in_force(): void
    {
        $m = $this->member('Amina');
        $this->assertSame('active', $m->status);
        $this->assertNotEmpty($m->member_no);
        $this->assertSame('2026-06-15', $m->member_since->toDateString());
        $this->assertNull($m->standing);
        $this->assertSame(Memberships::NONE, Memberships::standingOf($m));

        Memberships::renew($m, $this->annual, ['paid_minor' => 20000]); // part paid: pending, not in force
        $this->assertNull($m->fresh()->standing);
    }

    public function test_a_paid_period_is_numbered_and_makes_the_member_good_until_the_day_before_a_year_later(): void
    {
        $m = $this->member('Bena');
        $s = Memberships::renew($m, $this->annual, ['paid_minor' => 50000, 'method' => 'cash']);
        $this->assertSame('paid', $s->status);
        $this->assertSame('2026-06-15', $s->starts_on->toDateString());
        $this->assertSame('2027-06-14', $s->ends_on->toDateString());
        $this->assertSame('2026-06-15', $s->paid_on->toDateString());
        $this->assertMatchesRegularExpression('/^RCT-2026-\d{6}$/', $s->receipt_no);
        $m = $m->fresh();
        $this->assertSame('good', $m->standing);
        $this->assertSame('2027-06-14', $m->membership_ends_on->toDateString());
        $this->assertSame((int) $this->annual->id, (int) $m->category_id);
    }

    public function test_a_renewal_starts_the_day_after_a_period_in_force_and_today_after_a_lapse(): void
    {
        $m = $this->member('Cissy');
        Memberships::renew($m, $this->annual, ['paid_minor' => 50000, 'starts_on' => '2025-09-01']); // ends 2026-08-31, in force
        $this->assertSame('2026-09-01', Memberships::nextStart($m->fresh())->toDateString());
        $next = Memberships::renew($m->fresh(), $this->annual, ['paid_minor' => 50000]);
        $this->assertSame('2027-08-31', $next->ends_on->toDateString());

        $old = $this->member('Dora');
        Memberships::renew($old, $this->annual, ['paid_minor' => 50000, 'starts_on' => '2024-03-01']); // ended 2025-02-28
        $this->assertSame('lapsed', $old->fresh()->standing);
        $this->assertSame('2026-06-15', Memberships::nextStart($old->fresh())->toDateString());
        Memberships::renew($old->fresh(), $this->annual, ['paid_minor' => 50000]);
        $this->assertSame('good', $old->fresh()->standing);
    }

    public function test_a_month_end_period_does_not_overflow(): void
    {
        $month = UnityCategory::create(['company_id' => $this->co->id, 'name' => 'Monthly', 'fee_minor' => 10000, 'period_months' => 1]);
        $this->assertSame('2026-02-27', Memberships::periodEnd(Carbon::parse('2026-01-28'), $month)->toDateString());
        $this->assertSame('2026-02-27', Memberships::periodEnd(Carbon::parse('2026-01-31'), $month)->toDateString(), 'not into March');
        $this->assertNull(Memberships::periodEnd(Carbon::parse('2026-01-31'), $this->life));
    }

    public function test_life_waived_and_cancelled_periods(): void
    {
        $m = $this->member('Esther');
        Memberships::renew($m, $this->life, ['paid_minor' => 500000]);
        $this->assertSame('life', $m->fresh()->standing);
        $this->refused('life_member', fn () => Memberships::nextStart($m->fresh()));

        $w = $this->member('Faith');
        $s = Memberships::renew($w, $this->annual, ['status' => 'waived']);
        $this->assertSame('good', $w->fresh()->standing);
        $s->update(['status' => 'cancelled']);
        $this->assertNull($w->fresh()->standing, 'nothing in force once the only period is cancelled');
        $this->refused('cancelled_final', fn () => $s->fresh()->update(['status' => 'paid']));
        $this->refused('subscription_history', fn () => $s->fresh()->delete());
    }

    public function test_the_guards(): void
    {
        $m = $this->member('Grace');
        $this->refused('not_fully_paid', fn () => Memberships::renew($m, $this->annual, ['status' => 'paid', 'paid_minor' => 100]));
        $this->refused('validation', fn () => UnitySubscription::create(['company_id' => $this->co->id, 'member_id' => $m->id, 'category_id' => $this->annual->id,
            'starts_on' => '2026-06-15', 'ends_on' => '2026-06-01', 'amount_minor' => 0, 'paid_minor' => 0, 'status' => 'waived']));

        [$other] = $this->unityAccount(['email' => 'other_'.uniqid().'@example.com']);
        $stranger = $this->member('Hope', $other);
        $this->refused('not_a_member', fn () => UnitySubscription::create(['company_id' => $this->co->id, 'member_id' => $stranger->id, 'category_id' => $this->annual->id,
            'starts_on' => '2026-06-15', 'amount_minor' => 0, 'paid_minor' => 0, 'status' => 'waived']));
        $this->refused('not_a_member', fn () => UnityChapter::create(['company_id' => $this->co->id, 'name' => 'North', 'lead_member_id' => $stranger->id]));

        // A phone may leave the end to the category.
        $s = UnitySubscription::create(['company_id' => $this->co->id, 'member_id' => $m->id, 'category_id' => $this->annual->id,
            'starts_on' => '2026-01-01', 'amount_minor' => 50000, 'paid_minor' => 50000, 'status' => 'pending']);
        $this->assertSame('paid', $s->status, 'fully paid becomes paid');
        $this->assertSame('2026-12-31', $s->ends_on->toDateString());

        $this->refused('category_in_use', fn () => $this->annual->fresh()->delete());
        $chapter = UnityChapter::create(['company_id' => $this->co->id, 'name' => 'North', 'lead_member_id' => $m->id]);
        $m->fresh()->update(['chapter_id' => $chapter->id]);
        $this->refused('chapter_in_use', fn () => $chapter->fresh()->delete());
    }

    public function test_lists_summary_and_refresh(): void
    {
        $good = $this->member('Ivy');
        Memberships::renew($good, $this->annual, ['paid_minor' => 50000, 'starts_on' => '2025-09-01']); // ends 2026-08-31
        $soon = $this->member('Joy');
        Memberships::renew($soon, $this->annual, ['paid_minor' => 50000, 'starts_on' => '2025-07-01', 'paid_on' => '2025-07-01']); // ends 2026-06-30
        $gone = $this->member('Kate');
        Memberships::renew($gone, $this->annual, ['paid_minor' => 50000, 'starts_on' => '2025-01-01', 'paid_on' => '2025-01-01']); // ended 2025-12-31
        $life = $this->member('Lydia');
        Memberships::renew($life, $this->life, ['paid_minor' => 500000]);
        $this->member('Mary'); // nothing yet

        $cid = (int) $this->co->id;
        $this->assertSame([(int) $gone->id], Memberships::lapsed($cid)->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([(int) $soon->id], Memberships::expiring($cid, 30)->pluck('id')->map(fn ($i) => (int) $i)->all());

        $sum = Memberships::summary($cid);
        $this->assertSame(5, $sum['members']);
        $this->assertSame(3, $sum['good_standing'], 'life counts as good standing');
        $this->assertSame(1, $sum['lapsed']);
        $this->assertSame(1, $sum['expiring_30_days']);
        $this->assertSame(550000, $sum['collected_this_year_minor'], 'paid this year: Ivy 50,000 and Lydia 500,000');

        // A month later Joy's period has ended: the lists see it at once, and the refresh writes the cache.
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->assertSame('good', $soon->fresh()->standing);
        $this->assertSame(2, Memberships::lapsed($cid)->count());
        $this->assertSame(1, Memberships::refreshStanding($cid));
        $this->assertSame('lapsed', $soon->fresh()->standing);
    }

    public function test_chapters_are_on_organisation_and_the_trial_and_sms_counts_on_group_and_up(): void
    {
        $plan = fn (string $slug) => \App\Models\Subscription::where('company_id', $this->co->id)->update(['plan_id' => \App\Models\Plan::where('slug', $slug)->value('id')]);
        $usage = fn () => (new \App\Services\Billing\Quotas())->usage($this->co->fresh());
        $this->assertSame(2000, $usage()['sms']['max']);
        UnityChapter::create(['company_id' => $this->co->id, 'name' => 'Central']);

        $plan('unitydesk-group');
        $this->refused('feature_not_in_plan', fn () => UnityChapter::create(['company_id' => $this->co->id, 'name' => 'West']));
        $this->assertSame(500, $usage()['sms']['max']);
        $this->assertArrayNotHasKey('members', $usage(), 'Akiba\'s member count stays Akiba\'s');

        $plan('unitydesk-free');
        $this->assertSame(0, $usage()['sms']['max']);
        $plan('unitydesk-trial');
        UnityChapter::create(['company_id' => $this->co->id, 'name' => 'East']);
        $this->assertStringNotContainsString('chapters', (string) \App\Models\Plan::where('slug', 'unitydesk-group')->value('description'));
    }

    public function test_the_daily_jobs_lapse_and_remind_each_member_once(): void
    {
        $ending = $this->member('Dora');
        Memberships::renew($ending, $this->annual, ['paid_minor' => 50000]); // ends 2027-06-14
        $ended = $this->member('Esi');
        Memberships::renew($ended, $this->annual, ['paid_minor' => 50000]);
        $notices = fn (SaccoMember $m) => \DB::table('sacco_member_notices')->where('member_id', $m->id)->count();

        Carbon::setTestNow('2027-06-10 10:00:00'); // four days before both end
        $this->artisan('unity:remind')->assertSuccessful();
        $this->artisan('unity:remind')->assertSuccessful();
        $this->assertSame(1, $notices($ending));

        Carbon::setTestNow('2027-06-16 10:00:00'); // both have ended
        $this->artisan('unity:lapse', ['--company' => $this->co->id])->assertSuccessful();
        $this->artisan('unity:lapse')->assertSuccessful();
        $this->assertSame(Memberships::LAPSED, $ended->fresh()->standing);
        $this->assertSame(2, $notices($ended)); // one reminder, one "has ended"
        $this->assertSame(0, \App\Services\Unity\StandingJobs::remind($this->co)['told']);
    }
}
