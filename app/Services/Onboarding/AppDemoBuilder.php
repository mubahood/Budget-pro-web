<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\FinancialPeriod;
use App\Models\User;
use App\Services\Team\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fills a family app's own demo account that has no shop in it (PublicDemo::spec, kind `farm` or `events`):
 *
 *  - Kuku Tally: the Mukono layers farm (FamilyDemoBuilder::seedFarm), poultry and finance only;
 *  - Pledge & Budget: Brian and Grace's Kukyala, Kwanjula and wedding (PublicDemoWedding), budget and finance only.
 *
 * The same shape as PublicDemoBuilder (build, load, bringForward, companyId, skipped), through the same services, with
 * the clock moved to each moment. bringForward() is the heal's half: today's card and egg sale, or today's pledge and
 * payments, never twice, so the account's main screen is never empty.
 */
class AppDemoBuilder
{
    /** Bumped when a build changes what these accounts hold: a live one built by an older version is rebuilt on the next run. */
    public const BUILD = 1;

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    /** The account being built (so a build that fails can be cleaned up). */
    public ?int $companyId = null;

    /** @var array<string, mixed> */
    private array $spec;

    /** @var array<string, mixed> */
    private array $data;

    private Company $co;

    private User $manager;

    /** @var array<string, User> first name (lower case) => member */
    private array $staff = [];

    /** @var array<string, mixed> the farm's batches, or the events */
    private array $made = [];

    private string $tz;

    private Carbon $realNow;

    public function __construct(private string $account)
    {
        $this->spec = PublicDemo::spec($account);
        $this->data = PublicDemo::data($account);
        $this->tz = (string) ($this->spec['shop']['timezone'] ?? 'Africa/Kampala');
    }

    /**
     * A new, complete account owned (for now) by the first of its team. Nobody signs into it until PublicDemo hands it to
     * the demo sign-in.
     *
     * @return array{company: Company, manager: User, snapshot: array<string, mixed>}
     */
    public function build(): array
    {
        $clock = Carbon::getTestNow();
        $this->realNow = now()->copy();
        Carbon::setTestNow($this->realNow->copy()->subDays(FamilyDemoBuilder::FARM_AGE + 14));
        $this->createAccount();
        $guards = $this->actAs($this->manager);
        PublicDemo::$building = true; // the visitor locks are for visitors, not for the builder
        try {
            OnboardingEvents::muted(function () {
                $this->team();
                Carbon::setTestNow($this->realNow->copy());
                if ($this->spec['kind'] === 'farm') {
                    $family = new FamilyDemoBuilder();
                    $this->made['farm'] = $family->seedFarm($this->co->fresh(), $this->manager, $this->opts() + ['ledger' => true])['batches'];
                    $this->skipped = array_merge($this->skipped, $family->skipped);
                } else {
                    $pledges = new PublicDemoWedding();
                    $made = $pledges->seed($this->co->fresh(), $this->manager, $this->committee(), $this->realNow, $this->tz);
                    $this->skipped = array_merge($this->skipped, $pledges->skipped);
                    $this->made += ['events' => $made['events'], 'wedding' => $made['wedding']];
                }
            });
        } finally {
            PublicDemo::$building = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }
        $this->bringToday();

        return ['company' => $this->co->fresh(), 'manager' => $this->manager->fresh(), 'snapshot' => [
            'build' => self::BUILD,
            'staff' => array_map(fn (User $u) => (int) $u->id, $this->staff),
        ] + $this->made];
    }

    /** Pick up an existing account (for the heal) from its snapshot. */
    public function load(Company $company, array $snapshot): self
    {
        $this->co = $company;
        $this->companyId = (int) $company->id;
        $this->tz = (string) ($company->timezone ?: $this->tz);
        $this->realNow = now()->copy();
        foreach ((array) ($snapshot['staff'] ?? []) as $key => $id) {
            if ($u = User::withoutGlobalScopes()->where('company_id', $company->id)->find($id)) {
                $this->staff[$key] = $u;
            }
        }
        $this->manager = $this->staff[$this->firstKey()] ?? User::withoutGlobalScopes()->findOrFail($company->owner_id);
        $this->made = array_intersect_key($snapshot, array_flip(['farm', 'events', 'wedding']));

        return $this;
    }

    /**
     * The heal's half: what was switched off comes back, and the account is brought up to today, never a row twice.
     *
     * @return array{sales: int, repairs: list<string>, snapshot: array<string, mixed>}
     */
    public function bringForward(): array
    {
        $repairs = $this->spec['kind'] === 'farm' ? $this->repairFarm() : $this->repairEvents();
        ['added' => $added, 'repairs' => $more] = $this->bringToday();

        return ['sales' => $added, 'repairs' => array_merge($repairs, $more), 'snapshot' => $this->made];
    }

    /** @return array{added: int, repairs: list<string>} */
    private function bringToday(): array
    {
        PublicDemo::$building = true;
        try {
            if ($this->spec['kind'] === 'farm') {
                $layers = (int) ($this->made['farm']['layers'] ?? 0);
                $family = new FamilyDemoBuilder();
                $cards = $family->topUpFarm($this->co, $this->manager, $layers, $this->opts() + ['ledger' => true]);
                $sold = $family->sellToday($this->co, $this->manager, $layers, $this->opts());
                $this->skipped = array_merge($this->skipped, $family->skipped);

                return ['added' => $cards + $sold, 'repairs' => $cards > 0 ? ["{$cards} farm day(s)"] : []];
            }
            $pledges = new PublicDemoWedding();
            $today = $pledges->keepToday($this->co, $this->manager, (array) ($this->made['events'] ?? []), $this->realNow, $this->tz);
            $this->skipped = array_merge($this->skipped, $pledges->skipped);

            return ['added' => $today['pledges'] + $today['payments'], 'repairs' => []];
        } finally {
            PublicDemo::$building = false;
        }
    }

    /** @return list<string> */
    private function repairFarm(): array
    {
        $layers = (int) ($this->made['farm']['layers'] ?? 0);
        $n = DB::table('poultry_batches')->where('company_id', $this->co->id)->where('id', $layers)->where('is_deleted', 1)->update(['is_deleted' => 0, 'updated_at' => now()]);

        return $n > 0 ? ['farm batch'] : [];
    }

    /** @return list<string> */
    private function repairEvents(): array
    {
        $repairs = [];
        $closed = array_keys(array_filter((require dirname(__DIR__, 3).'/database/data/demo_wedding.php')['events'], fn ($e) => empty($e['open'])));
        foreach ((array) ($this->made['events'] ?? []) as $key => $id) {
            $status = in_array($key, $closed, true) ? 'Inactive' : 'Active';
            $row = DB::table('budget_programs')->where('company_id', $this->co->id)->where('id', $id)->first(['status', 'is_deleted']);
            if ($row !== null && ((string) $row->status !== $status || (int) $row->is_deleted === 1)) {
                DB::table('budget_programs')->where('id', $id)->update(['status' => $status, 'is_active' => $status === 'Active' ? 'Yes' : 'No', 'is_deleted' => 0, 'updated_at' => now()]);
                $repairs[] = "{$key} status";
            }
        }

        return $repairs;
    }

    /** @return array{now: Carbon, tz: string, currency: string} */
    private function opts(): array
    {
        return ['now' => $this->realNow, 'tz' => $this->tz, 'currency' => (string) $this->co->currency];
    }

    /** The first of the team, who writes everything down (the treasurer, the farm manager). */
    private function firstKey(): string
    {
        return strtolower((string) strtok((string) $this->data['team'][0][0], ' '));
    }

    private function createAccount(): void
    {
        $s = (array) $this->spec['shop'];
        $details = (array) $this->data['company'];
        $name = (string) $this->data['team'][0][0];
        [$first, $last] = explode(' ', $name, 2);

        $manager = new User();
        $manager->first_name = $first;
        $manager->last_name = $last;
        $manager->name = $name;
        $manager->username = 'demo-'.strtolower(Str::random(6)).'-'.strtolower($first);
        $manager->setAttribute('email', null);
        $manager->phone_number = null;
        $manager->phone_e164 = null;
        $manager->password = Hash::make(Str::random(40));
        $manager->status = 'Active';
        $manager->save();

        $co = new Company();
        $co->owner_id = $manager->id; // created hook: their company_id
        $co->name = (string) $s['name'];
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = (string) ($s['currency'] ?? 'UGX');
        $co->country = (string) ($s['country'] ?? 'UG');
        $co->timezone = $this->tz;
        $co->business_type = (string) $details['business_type'];
        $co->address = (string) ($s['address'] ?? '');
        $co->phone_number = (string) $details['phone'];
        $co->email = (string) $details['email'];
        $co->license_expire = $this->realNow->copy()->addYears(5);
        $co->enabled_modules = $this->spec['modules'];
        $co->negative_stock_policy = 'allow';
        $co->receipt_channels = [];
        $co->payment_methods = ['methods' => ['cash', 'mobile_money', 'credit'], 'momo' => [], 'opening_float' => 0];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => $this->realNow->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'demo_status' => 'building', 'version' => OnboardingService::STATE_VERSION,
            'public_demo' => ['building' => true, 'account' => $this->account]]; // marks it from the start (guards apply, a dead build is cleaned up)
        $co->save();

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $manager->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $manager->refresh();
        User::ensureCompanyOwnerRole($manager);

        // One period across the whole history (the default one starts on 1 January).
        $period = new FinancialPeriod();
        $period->company_id = $co->id;
        $period->name = 'FY '.$this->realNow->year;
        $period->start_date = Carbon::parse($this->realNow->copy()->subDays(FamilyDemoBuilder::FARM_AGE + 14)->startOfMonth()->toDateString());
        $period->end_date = Carbon::parse($this->realNow->copy()->endOfYear()->toDateString());
        $period->status = 'Active';
        $period->description = 'Demo trading year';
        $period->total_investment = 0;
        $period->total_sales = 0;
        $period->total_profit = 0;
        $period->total_expenses = 0;
        $period->save();

        $this->co = $co->fresh();
        $this->companyId = (int) $co->id;
        $this->manager = $manager->fresh();
        $this->staff[$this->firstKey()] = $this->manager;
    }

    /** The rest of the team, each with their own role and no password anyone knows: they open from the /demo page's buttons. */
    private function team(): void
    {
        $domain = (string) $this->data['company']['domain'];
        foreach (array_slice($this->data['team'], 1) as [$name, $role]) {
            $slug = Str::slug($name, '.');
            try {
                $u = app(TeamService::class)->createMember($this->co, $this->manager, $name, "{$slug}.".$this->companyId.'@'.$domain, null, Str::password(24, symbols: false), $role);
            } catch (BusinessRuleException $e) {
                $this->skipped[] = $e->getMessage();
                Log::info('[public-demo] team member skipped: '.$e->getMessage());

                continue;
            }
            DB::table('admin_users')->where('id', $u->id)->update(['email' => "{$slug}@{$domain}"]);
            $this->staff[strtolower((string) strtok($name, ' '))] = $u->fresh();
        }
        DB::table('admin_users')->where('id', $this->manager->id)->update(['email' => Str::slug((string) $this->data['team'][0][0], '.').'@'.$domain]);
    }

    /** The events' committee from the team (database/data: `committee`): the first keeps the money. @return array<int, string> user id => role */
    private function committee(): array
    {
        $roles = [(int) $this->manager->id => 'treasurer'];
        foreach ((array) ($this->data['committee'] ?? []) as $who => $role) {
            if (isset($this->staff[$who])) {
                $roles += [(int) $this->staff[$who]->id => $role];
            }
        }

        return $roles;
    }

    /** @return array<string, mixed> */
    private function actAs(User $user): array
    {
        $prev = [];
        foreach (['web', 'admin'] as $name) {
            try {
                $guard = Auth::guard($name);
                $prev[$name] = $guard->hasUser() ? $guard->user() : null;
                $guard->setUser($user);
            } catch (\Throwable) {
                // guard not configured in this app
            }
        }

        return $prev;
    }

    /** @param array<string, mixed> $prev */
    private function restore(array $prev): void
    {
        foreach ($prev as $name => $user) {
            $guard = Auth::guard($name);
            if ($user !== null) {
                $guard->setUser($user);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
