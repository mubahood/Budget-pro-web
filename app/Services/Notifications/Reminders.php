<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Support\AppFamily;
use App\Support\AppVersions;
use App\Support\Money;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled notifications of the engage plan (§3), run by `notifications:engage` at :20 and :40 (the host's reliable minutes).
 * Each part has a window in Kampala time and fires once per account and period (Notices::once, claimed only when there is
 * something to say), so running it every 20 minutes is safe. Accounts go in batches; one account failing never stops the rest.
 *
 * Morning (08:00-10:59): rent, loans, membership, family dates, flock. 10:00-11:59: app updates. Sunday 18:00-19:59: the week.
 * The texts of the SACCO, rentals, family and membership apps never name another app.
 */
class Reminders
{
    /** Deaths in a day above this share of a batch's birds are not normal. */
    public const MORTALITY_SHARE = 0.01;

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly Notifier $notifier = new Notifier()) {}

    /** @return array<string, int> notices sent per part */
    public function run(?Carbon $at = null): array
    {
        $local = ($at ?? now())->copy()->setTimezone(Notifier::TZ);
        $this->counts = [];
        $morning = $local->hour >= 8 && $local->hour < 11;
        $digest = $local->isSunday() && $local->hour >= 18 && $local->hour < 20;
        if ($morning || $digest) {
            Company::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('is_demo')->orWhere('is_demo', false))->orderBy('id')
                ->chunkById(100, function ($companies) use ($local, $morning, $digest) {
                    foreach ($companies as $company) {
                        try {
                            $modules = $company->modules();
                            if ($morning) {
                                $this->morning($company, $modules, $local);
                            }
                            if ($digest) {
                                $this->weekly($company, $modules, $local);
                            }
                        } catch (\Throwable $e) {
                            Log::warning("Reminders for company {$company->id} failed: ".$e->getMessage());
                        }
                    }
                });
        }
        if ($local->hour >= 10 && $local->hour < 12) {
            $this->appUpdates();
        }

        return $this->counts;
    }

    private function morning(Company $company, array $modules, Carbon $local): void
    {
        foreach (['rentals' => 'rent', 'sacco' => 'loans', 'unity' => 'unity', 'kin' => 'kinDates', 'poultry' => 'poultry'] as $module => $method) {
            if (in_array($module, $modules, true)) {
                $this->{$method}($company, $local);
            }
        }
        if (in_array('rentals', $modules, true)) {
            $this->leases($company, $local);
        }
    }

    /** Once per account, key and period; the managers (or $users) get it through Notifier::send. @param array<int, User>|null $users */
    private function once(Company $company, string $key, string $period, string $app, string $kind, string $title, string $body, string $link = 'notifications', ?array $users = null): void
    {
        $cid = (int) $company->id;
        Notices::once($cid, 'engage:'.$key, $period, function () use ($company, $cid, $app, $kind, $title, $body, $link, $users, $key) {
            foreach ($users ?? $this->notifier->managers($cid) as $user) {
                if ($this->notifier->send($user, $app, $kind, $title, $body, $link, false, $cid) !== null) {
                    $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
                }
            }
        });
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }

    // ---- Rentals: bills due in 3 days (and what is overdue); on Mondays the overdue list alone; leases ending in 30 days ----

    private function rent(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $soonDay = $local->copy()->addDays(3)->toDateString();
        $open = fn () => DB::table('rental_charges')->where('company_id', $cid)->where('status', 'open')->whereNull('voided_at')->whereNotIn('kind', ['deposit', 'refund']);
        $soon = $open()->where('due_date', $soonDay)->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor - paid_minor), 0) AS owed')->first();
        $late = $open()->where('due_date', '<', $local->toDateString())->selectRaw('COUNT(DISTINCT lease_id) AS n, COALESCE(SUM(amount_minor - paid_minor), 0) AS owed')->first();
        $soonN = (int) $soon->n;
        $lateN = (int) $late->n;
        if ($soonN === 0 && ! ($local->isMonday() && $lateN > 0)) {
            return;
        }
        $parts = [];
        if ($soonN > 0) {
            $parts[] = self::plural($soonN, 'bill').' fall'.($soonN === 1 ? 's' : '').' due on '.Carbon::parse($soonDay)->format('D j M').' ('.Minor::format((int) $soon->owed, $cid).').';
        }
        if ($lateN > 0) {
            $parts[] = self::plural($lateN, 'lease').' '.($lateN === 1 ? 'is' : 'are').' behind, '.Minor::format((int) $late->owed, $cid).' overdue in all.';
        }
        $this->once($company, 'rent_due', $local->toDateString(), 'estate-ledger', 'rent_due', $soonN > 0 ? 'Rent falling due' : 'Rent overdue', implode(' ', $parts));
    }

    private function leases(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $day = $local->copy()->addDays(30)->toDateString();
        $rows = DB::table('rental_leases as l')->leftJoin('rental_tenants as t', 't.id', '=', 'l.tenant_id')->where('l.company_id', $cid)
            ->whereIn('l.status', ['active', 'notice'])->where('l.end_date', $day)->orderBy('l.id')->get(['l.id', 'l.number', 't.name']);
        if ($rows->isEmpty()) {
            return;
        }
        $names = $rows->take(5)->map(fn ($r) => $r->number.($r->name ? " ({$r->name})" : ''))->implode(', ');
        $this->once($company, 'leases', $local->toDateString(), 'estate-ledger', 'leases', self::plural($rows->count(), 'lease').' end in 30 days',
            "Ending on ".Carbon::parse($day)->format('D j M').": {$names}".($rows->count() > 5 ? ' and '.($rows->count() - 5).' more' : '').'. Renew or plan the move-out.',
            $rows->count() === 1 ? 'rent/'.$rows->first()->id : 'notifications');
    }

    // ---- SACCO: instalments due in 3 days (and loans in arrears); on Mondays the arrears alone ----

    private function loans(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $soonDay = $local->copy()->addDays(3)->toDateString();
        $live = fn ($q) => $q->where('l.company_id', $cid)->whereNotNull('l.disbursed_on')->whereNull('l.closed_on')->whereNull('l.written_off_on');
        $soon = DB::table('sacco_loan_schedule_lines as s')->join('sacco_loans as l', fn ($j) => $j->on('l.id', '=', 's.loan_id')->on('l.schedule_version', '=', 's.schedule_version'))
            ->tap($live)->where('s.due_on', $soonDay)->whereIn('s.status', ['due', 'partial'])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.principal_due_minor + s.interest_due_minor + s.fee_due_minor - s.principal_paid_minor - s.interest_paid_minor - s.fee_paid_minor), 0) AS owed')->first();
        $late = DB::table('sacco_loans as l')->tap($live)->where('l.days_past_due', '>', 0)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(l.arrears_principal_minor + l.arrears_interest_minor), 0) AS owed')->first();
        $soonN = (int) $soon->n;
        $lateN = (int) $late->n;
        if ($soonN === 0 && ! ($local->isMonday() && $lateN > 0)) {
            return;
        }
        $fmt = fn (int $m) => \App\Services\Sacco\Loans\LoanBooks::fmt($cid, $m);
        $parts = [];
        if ($soonN > 0) {
            $parts[] = self::plural($soonN, 'loan instalment').' fall'.($soonN === 1 ? 's' : '').' due on '.Carbon::parse($soonDay)->format('D j M').' ('.$fmt((int) $soon->owed).').';
        }
        if ($lateN > 0) {
            $parts[] = self::plural($lateN, 'loan').' '.($lateN === 1 ? 'is' : 'are').' in arrears, '.$fmt((int) $late->owed).' in all.';
        }
        $this->once($company, 'loan_due', $local->toDateString(), 'akiba-ledger', 'loan_due', $soonN > 0 ? 'Loan repayments due' : 'Loans in arrears', implode(' ', $parts));
    }

    // ---- Membership: periods ending in 7 days; on Mondays the lapsed count too ----

    private function unity(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $day = $local->copy()->addDays(7)->toDateString();
        $base = fn () => DB::table('sacco_members')->where('company_id', $cid)->where('is_deleted', 0)->whereNull('date_of_death');
        $ending = $base()->where('membership_ends_on', $day)->whereIn('standing', [\App\Services\Unity\Memberships::GOOD])->count();
        $lapsed = $local->isMonday() ? $base()->where('standing', \App\Services\Unity\Memberships::LAPSED)->count() : 0;
        if ($ending === 0 && $lapsed === 0) {
            return;
        }
        $parts = [];
        if ($ending > 0) {
            $parts[] = self::plural($ending, 'membership').' end'.($ending === 1 ? 's' : '').' on '.Carbon::parse($day)->format('D j M').'.';
        }
        if ($lapsed > 0) {
            $parts[] = self::plural($lapsed, 'member').' '.($lapsed === 1 ? 'has' : 'have').' lapsed.';
        }
        $this->once($company, 'unity_due', $local->toDateString(), 'unitydesk', 'unity_due', $ending > 0 ? 'Memberships ending' : 'Lapsed members', implode(' ', $parts));
    }

    // ---- Family: birthdays (exact dates, living people) and remembrance days, to everyone with a sign-in ----

    private function kinDates(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $md = $local->format('m-d');
        $days = [$md];
        if ($md === '02-28' && ! $local->isLeapYear()) {
            $days[] = '02-29'; // a 29 February birthday is kept on the 28th
        }
        $people = fn () => DB::table('sacco_members')->where('company_id', $cid)->where('is_deleted', 0);
        $born = $people()->whereNull('date_of_death')->whereNotNull('date_of_birth')->where(fn ($q) => $q->whereNull('birth_date_precision')->orWhere('birth_date_precision', 'exact'))
            ->whereIn(DB::raw("DATE_FORMAT(date_of_birth, '%m-%d')"), $days)->whereYear('date_of_birth', '<', $local->year)->orderBy('id')->limit(20)
            ->get(['id', 'first_name', 'last_name', 'date_of_birth']);
        $gone = $people()->whereNotNull('date_of_death')->whereIn(DB::raw("DATE_FORMAT(date_of_death, '%m-%d')"), $days)->whereYear('date_of_death', '<', $local->year)
            ->orderBy('id')->limit(20)->get(['id', 'first_name', 'last_name', 'date_of_death']);
        if ($born->isEmpty() && $gone->isEmpty()) {
            return;
        }
        $name = fn ($p) => trim($p->first_name.' '.$p->last_name);
        $parts = [];
        if ($born->isNotEmpty()) {
            $parts[] = 'Birthday today: '.$born->map(fn ($p) => $name($p).' ('.($local->year - (int) substr((string) $p->date_of_birth, 0, 4)).')')->implode(', ').'.';
        }
        if ($gone->isNotEmpty()) {
            $parts[] = 'Remembered today: '.$gone->map(fn ($p) => $name($p).' ('.($local->year - (int) substr((string) $p->date_of_death, 0, 4)).' years)')->implode(', ').'.';
        }
        $all = $born->concat($gone);
        $this->once($company, 'kin_dates', $local->toDateString(), 'family-roots', 'kin_dates', $born->isNotEmpty() ? 'A birthday in the family' : 'A day to remember',
            implode(' ', $parts), $all->count() === 1 ? 'kin/person/'.$all->first()->id : 'notifications', $this->notifier->everyone($cid));
    }

    // ---- Flock: vaccinations due tomorrow, feed running low (once a week), deaths above normal yesterday ----

    private function poultry(Company $company, Carbon $local): void
    {
        $cid = (int) $company->id;
        $tomorrow = $local->copy()->addDay()->toDateString();
        $vax = DB::table('poultry_vaccination_events as v')->leftJoin('poultry_batches as b', 'b.id', '=', 'v.batch_id')->where('v.company_id', $cid)->where('v.is_deleted', 0)
            ->where('v.done', 0)->where('v.due_date', $tomorrow)->orderBy('v.id')->limit(10)->get(['v.vaccine', 'b.name']);
        if ($vax->isNotEmpty()) {
            $this->once($company, 'poultry_vaccine', $local->toDateString(), 'kuku-tally', 'poultry', 'Vaccination tomorrow',
                $vax->map(fn ($v) => $v->vaccine.($v->name ? " for {$v->name}" : ''))->implode(', ').'.');
        }

        $summary = (new \App\Services\Poultry\PoultrySummary())->for($company, $local->copy());
        if (! empty($summary['feed_low'])) {
            $days = $summary['feed_days_left'];
            $this->once($company, 'poultry_feed', $local->format('o-\WW'), 'kuku-tally', 'poultry', 'Feed running low',
                $days !== null ? 'About '.self::plural((int) $days, 'day').' of feed left. Buy feed soon.' : 'Feed is running low. Buy feed soon.');
        }

        $yesterday = $local->copy()->subDay()->toDateString();
        $deaths = [];
        foreach (DB::table('poultry_daily_records')->where('company_id', $cid)->where('is_deleted', 0)->where('date', $yesterday)->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(mortality) AS d')->get() as $r) {
            $deaths[(int) $r->batch_id] = ($deaths[(int) $r->batch_id] ?? 0) + (int) $r->d;
        }
        foreach (DB::table('poultry_mortality_events')->where('company_id', $cid)->where('is_deleted', 0)->where('date', $yesterday)->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(`count`) AS d')->get() as $r) {
            $deaths[(int) $r->batch_id] = ($deaths[(int) $r->batch_id] ?? 0) + (int) $r->d;
        }
        $high = [];
        foreach ($summary['batches'] ?? [] as $b) {
            $d = $deaths[(int) $b['id']] ?? 0;
            $alive = (int) $b['birds'] + $d; // birds at the start of yesterday
            if ($d > 0 && $alive > 0 && $d / $alive > self::MORTALITY_SHARE) {
                $high[] = "{$b['name']}: {$d} of {$alive}";
            }
        }
        if ($high !== []) {
            $this->once($company, 'poultry_deaths', $local->toDateString(), 'kuku-tally', 'poultry', 'More deaths than normal',
                'Yesterday '.implode('; ', $high).'. Check the birds and call the vet if it goes on.');
        }
    }

    // ---- App updates: a phone on an older version than its app's latest, once per person, app and version ----

    private function appUpdates(): void
    {
        $latest = [];
        foreach (array_keys(AppFamily::APPS) as $slug) {
            $latest[$slug] = AppVersions::forSlug($slug)['latest_version'];
        }
        DB::table('devices')->whereNotNull('push_token')->whereNotNull('app')->whereNotNull('app_version')->whereNull('revoked_at')
            ->orderBy('id')->chunkById(500, function ($devices) use ($latest) {
                foreach ($devices as $d) {
                    $want = $latest[$d->app] ?? null;
                    if ($want === null || version_compare((string) $d->app_version, (string) $want, '>=')) {
                        continue;
                    }
                    $user = User::withoutGlobalScopes()->find($d->user_id);
                    if ($user === null) {
                        continue;
                    }
                    try {
                        $sent = $this->notifier->send($user, $d->app, 'app_update', 'Update ready', 'Version '.$want.' of '.AppFamily::name($d->app).' is on Google Play, with the newest fixes.',
                            'notifications', false, (int) $d->company_id, 'update:'.$want);
                        if ($sent !== null) {
                            $this->counts['app_update'] = ($this->counts['app_update'] ?? 0) + 1;
                        }
                    } catch (\Throwable $e) {
                        Log::warning("App update notice for device {$d->id} failed: ".$e->getMessage());
                    }
                }
            });
    }

    // ---- Sunday evening: the week in three figures per part of the account, to the managers ----

    private function weekly(Company $company, array $modules, Carbon $local): void
    {
        $cid = (int) $company->id;
        $from = $local->copy()->subDays(6)->toDateString();
        $to = $local->toDateString();
        $lines = [];
        $money = fn ($v) => Money::format((float) $v, null, $cid);
        if (in_array('shop', $modules, true)) {
            $s = DB::table('sale_records')->where('company_id', $cid)->where('is_deleted', 0)->whereNull('voided_at')->whereBetween('sale_date', [$from, $to])
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS t')->first();
            if ((int) $s->n > 0) {
                $lines[] = 'Sales: '.self::plural((int) $s->n, 'sale').', '.$money($s->t).', about '.$money((float) $s->t / (int) $s->n).' each.';
            }
        }
        if (in_array('budget', $modules, true)) {
            $pledged = (float) DB::table('contribution_records')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->sum('amount');
            $paid = (float) DB::table('contribution_payments')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween('paid_on', [$from, $to])->sum('amount');
            if ($pledged > 0 || $paid > 0) {
                $lines[] = 'Pledges: '.$money($pledged).' pledged, '.$money($paid).' paid this week.';
            }
        }
        if (in_array('rentals', $modules, true)) {
            $in = (int) DB::table('rental_payments')->where('company_id', $cid)->whereNull('reversed_at')->whereBetween('paid_on', [$from, $to])->sum('amount_minor');
            $late = (int) DB::table('rental_charges')->where('company_id', $cid)->where('status', 'open')->whereNull('voided_at')->where('due_date', '<', $to)
                ->sum(DB::raw('amount_minor - paid_minor'));
            if ($in > 0 || $late > 0) {
                $lines[] = 'Rent: '.Minor::format($in, $cid).' received, '.Minor::format($late, $cid).' overdue.';
            }
        }
        if (in_array('sacco', $modules, true)) {
            $out = DB::table('sacco_loans')->where('company_id', $cid)->whereBetween('disbursed_on', [$from, $to])->selectRaw('COUNT(*) AS n, COALESCE(SUM(approved_minor), 0) AS t')->first();
            $arrears = DB::table('sacco_loans')->where('company_id', $cid)->whereNotNull('disbursed_on')->whereNull('closed_on')->whereNull('written_off_on')->where('days_past_due', '>', 0)->count();
            if ((int) $out->n > 0 || $arrears > 0) {
                $lines[] = 'Loans: '.self::plural((int) $out->n, 'loan').' paid out ('.\App\Services\Sacco\Loans\LoanBooks::fmt($cid, (int) $out->t).'), '.self::plural($arrears, 'loan').' in arrears.';
            }
        }
        if (in_array('unity', $modules, true)) {
            $paid = (int) DB::table('unity_subscriptions')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween('paid_on', [$from, $to])->sum('paid_minor');
            $lapsed = DB::table('sacco_members')->where('company_id', $cid)->where('is_deleted', 0)->where('standing', \App\Services\Unity\Memberships::LAPSED)->count();
            if ($paid > 0 || $lapsed > 0) {
                $lines[] = 'Members: '.Minor::format($paid, $cid).' in subscriptions, '.self::plural($lapsed, 'member').' lapsed.';
            }
        }
        if (in_array('kin', $modules, true)) {
            $added = DB::table('sacco_members')->where('company_id', $cid)->where('is_deleted', 0)->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])->count();
            if ($added > 0) {
                $lines[] = 'Family: '.self::plural($added, 'person', 'people').' added to the tree.';
            }
        }
        if (in_array('poultry', $modules, true)) {
            $p = (new \App\Services\Poultry\PoultrySummary())->for($company, $local->copy());
            if ((int) ($p['birds_alive'] ?? 0) > 0) {
                $lines[] = 'Flock: '.self::plural((int) $p['birds_alive'], 'bird').', '.(int) ($p['eggs_7d'] ?? 0).' eggs and '.self::plural((int) ($p['deaths_7d'] ?? 0), 'death').' this week.';
            }
        }
        if ($lines === []) {
            return;
        }
        $this->once($company, 'weekly_digest', $local->format('o-\WW'), \App\Support\StoreLink::appOf(null, $company) ?? 'budget-pro', 'weekly_digest', 'Your week', implode("\n", $lines));
    }
}
