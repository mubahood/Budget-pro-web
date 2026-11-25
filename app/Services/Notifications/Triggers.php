<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Support\Money;
use App\Support\Rentals\Minor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The event-driven notifications of the engage plan (§3): each one is called where the event already happens, runs after the
 * transaction commits, never tells the person who did it, and never breaks the business step (any failure is only logged).
 * Each goes through Notifier::send (inbox + push, the person's own choice per kind, quiet hours, 3 a day, once only).
 *
 * The texts of the SACCO, rentals, family and membership apps never name another app.
 */
final class Triggers
{
    /** Model events from imports, seeders and the demo upkeep run on the console: those tell nobody. */
    private static function fromConsole(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }

    /** After commit, and safe: a notice is never worth a failed sale, payment or save. */
    private static function later(string $what, callable $fn): void
    {
        $run = function () use ($what, $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning("Notification trigger {$what} failed: ".$e->getMessage());
            }
        };
        try {
            DB::afterCommit($run);
        } catch (\Throwable $e) {
            Log::warning("Notification trigger {$what} could not be queued: ".$e->getMessage());
        }
    }

    /** @param iterable<User|null> $users */
    private static function tell(int $companyId, ?string $app, string $kind, iterable $users, string $title, string $body, ?string $link,
        ?string $dedupe = null, ?int $actor = null, bool $urgent = false): int
    {
        if ((bool) DB::table('companies')->where('id', $companyId)->value('is_demo')) {
            return 0; // a demo's shared logins are shown, not told
        }
        $actor ??= auth()->id() ? (int) auth()->id() : null;
        $notifier = app(Notifier::class);
        $seen = [];
        $sent = 0;
        foreach ($users as $user) {
            if ($user === null || isset($seen[$user->id]) || ($actor !== null && (int) $user->id === $actor)) {
                continue;
            }
            $seen[$user->id] = true;
            if ($notifier->send($user, $app, $kind, $title, $body, $link, $urgent, $companyId, $dedupe) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @param array<int, int|null> $ids @return array<int, User> */
    private static function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : User::withoutGlobalScopes()->whereIn('id', $ids)->get()->all();
    }

    private static function managers(int $companyId): array
    {
        return app(Notifier::class)->managers($companyId);
    }

    // ---- Pledge & Budget ----

    /** The event's committee (active members with a sign-in) and the account's managers. */
    private static function committee(int $companyId, int $programId): array
    {
        $ids = DB::table('budget_program_members')->where('company_id', $companyId)->where('budget_program_id', $programId)
            ->where('is_deleted', 0)->where('status', 'active')->whereNotNull('user_id')->pluck('user_id')->all();

        return array_merge(self::users($ids), self::managers($companyId));
    }

    private static function eventName(int $programId): string
    {
        return (string) (DB::table('budget_programs')->where('id', $programId)->value('name') ?: 'the event');
    }

    /** A new pledge (web, phone sync): the committee hears who pledged what. */
    public static function pledgeMade(\App\Models\ContributionRecord $r): void
    {
        if (self::fromConsole() || $r->is_deleted || ! $r->company_id || ! $r->budget_program_id) {
            return;
        }
        $cid = (int) $r->company_id;
        $pid = (int) $r->budget_program_id;
        $id = (int) $r->id;
        $name = trim((string) $r->name) ?: 'Someone';
        $amount = (float) $r->amount;
        $paid = (float) $r->paid_amount;
        $actor = auth()->id() ? (int) auth()->id() : ((int) ($r->chaned_by_id ?: $r->treasurer_id) ?: null);
        self::later('pledge_made', function () use ($cid, $pid, $id, $name, $amount, $paid, $actor) {
            if ($amount <= 0 && $paid <= 0) {
                return;
            }
            $event = self::eventName($pid);
            $body = $amount > 0 ? "{$name} pledged ".Money::format($amount, null, $cid)." to {$event}." : "{$name} gave ".Money::format($paid, null, $cid)." to {$event}.";
            self::tell($cid, null, 'pledges', self::committee($cid, $pid), 'New pledge', $body, "pledge/{$id}", "pledge:{$id}:made", $actor);
        });
    }

    /** A payment on a pledge (ContributionPayments::record, not a replay). @param array{payment: mixed, record: mixed, replayed: bool} $result */
    public static function pledgePaid(array $result, int $userId): void
    {
        if (! empty($result['replayed'])) {
            return;
        }
        $p = $result['payment'];
        $r = $result['record'];
        $cid = (int) $r->company_id;
        $pid = (int) $r->budget_program_id;
        $rid = (int) $r->id;
        $paymentId = (int) $p->id;
        $amount = (float) $p->amount;
        $name = trim((string) $r->name) ?: 'Someone';
        $full = $r->fully_paid === 'Yes' || $r->fully_paid === true || $r->fully_paid === 1;
        self::later('pledge_paid', function () use ($cid, $pid, $rid, $paymentId, $amount, $name, $full, $userId) {
            $body = "{$name} paid ".Money::format($amount, null, $cid).' towards '.self::eventName($pid).'.'.($full ? ' The pledge is now fully paid.' : '');
            self::tell($cid, null, 'pledges', self::committee($cid, $pid), 'Pledge payment', $body, "pledge/{$rid}", "pledge-pay:{$paymentId}", $userId);
        });
    }

    /** A budget line's spending went past its target (BudgetPayments::recalculate): once per line. */
    public static function budgetOverspent(\App\Models\BudgetItem $item, string $before, string $paid, string $target): void
    {
        if ((float) $target <= 0 || (float) $before > (float) $target || (float) $paid <= (float) $target) {
            return; // only the crossing: at or under the target before, over it now
        }
        $cid = (int) $item->company_id;
        $pid = (int) $item->budget_program_id;
        $iid = (int) $item->id;
        $line = trim((string) $item->name) ?: 'A budget line';
        self::later('budget_overspent', function () use ($cid, $pid, $iid, $line, $paid, $target) {
            $over = Money::format((float) $paid - (float) $target, null, $cid);
            self::tell($cid, null, 'budget', self::committee($cid, $pid), 'Over budget', "{$line} is {$over} over its budget of ".Money::format((float) $target, null, $cid)
                .' for '.self::eventName($pid).'.', "budget/{$pid}", "budget-over:{$iid}");
        });
    }

    // ---- SACCO ----

    /** The committee's decision on a loan (full flow): the managers and the loan's officer hear it. */
    public static function loanDecided(\App\Models\SaccoLoan $loan, bool $approved, ?int $actor = null): void
    {
        $cid = (int) $loan->company_id;
        $id = (int) $loan->id;
        $no = (string) $loan->loan_no;
        $officer = $loan->officer_id ? (int) $loan->officer_id : null;
        $memberId = (int) $loan->member_id;
        $amount = (int) ($loan->approved_minor ?: $loan->applied_minor);
        self::later('loan_decided', function () use ($cid, $id, $no, $officer, $memberId, $amount, $approved, $actor) {
            $m = DB::table('sacco_members')->where('id', $memberId)->first(['first_name', 'last_name']);
            $who = $m ? trim($m->first_name.' '.$m->last_name) : 'a member';
            $users = array_merge(self::managers($cid), self::users([$officer]));
            $approved
                ? self::tell($cid, 'akiba-ledger', 'sacco_loans', $users, 'Loan approved', "Loan {$no} for {$who}: ".\App\Services\Sacco\Loans\LoanBooks::fmt($cid, $amount).' approved.', "loan/{$id}", "loan:{$id}:approved", $actor)
                : self::tell($cid, 'akiba-ledger', 'sacco_loans', $users, 'Loan not approved', "Loan {$no} for {$who} was not approved.", "loan/{$id}", "loan:{$id}:rejected", $actor);
        });
    }

    // ---- Rentals ----

    /** Rent received (Payments::receive) or paid to the landlord directly (receiveDirect). */
    public static function rentPaid(\App\Models\RentalPayment $p, \App\Models\RentalLease $lease, ?int $by, bool $direct = false): void
    {
        $cid = (int) $p->company_id;
        $pid = (int) $p->id;
        $leaseId = (int) $lease->id;
        $amount = (int) $p->amount_minor;
        $tenantId = (int) $lease->tenant_id;
        $propertyId = (int) $lease->property_id;
        $number = (string) $lease->number;
        self::later('rent_paid', function () use ($cid, $pid, $leaseId, $amount, $tenantId, $propertyId, $number, $by, $direct) {
            $tenant = (string) (DB::table('rental_tenants')->where('id', $tenantId)->value('name') ?: 'A tenant');
            $place = (string) (DB::table('rental_properties')->where('id', $propertyId)->value('name') ?: '');
            $where = "lease {$number}".($place !== '' ? " at {$place}" : '');
            self::tell($cid, 'estate-ledger', 'rent_paid', self::managers($cid), $direct ? 'Paid to the landlord' : 'Rent received',
                $direct ? "{$tenant} paid ".Minor::format($amount, $cid)." straight to the landlord on {$where}." : "{$tenant} paid ".Minor::format($amount, $cid)." on {$where}.",
                "rent/{$leaseId}", "rent-pay:{$pid}", $by);
        });
    }

    /** A repair opened or closed: the managers and the person it is assigned to. Urgent repairs reach them at night too. */
    public static function maintenance(\App\Models\RentalMaintenance $m, string $what, ?int $by): void
    {
        $cid = (int) $m->company_id;
        $id = (int) $m->id;
        $title = (string) $m->title;
        $number = (string) $m->number;
        $assignee = $m->assigned_to_id ? (int) $m->assigned_to_id : null;
        $urgent = $what === 'opened' && $m->priority === 'urgent';
        $from = (string) $m->reported_by;
        self::later('maintenance', function () use ($cid, $id, $title, $number, $assignee, $urgent, $what, $by, $from) {
            $users = array_merge(self::managers($cid), self::users([$assignee]));
            $what === 'opened'
                ? self::tell($cid, 'estate-ledger', 'maintenance', $users, $urgent ? 'Urgent repair' : 'New repair', "{$number}: {$title}".($from === 'tenant' ? ' (reported by the tenant)' : '').'.',
                    "maintenance/{$id}", "repair:{$id}:opened", $by, $urgent)
                : self::tell($cid, 'estate-ledger', 'maintenance', $users, 'Repair done', "{$number}: {$title} is done.", "maintenance/{$id}", "repair:{$id}:done", $by);
        });
    }

    // ---- Family tree ----

    /** A new person in a branch: the branch's coordinator hears it. */
    public static function kinPersonAdded(\App\Models\SaccoMember $m): void
    {
        if (self::fromConsole() || ! $m->branch_id || $m->is_deleted || ! \App\Services\Kin\TreeService::isFamily((int) $m->company_id)) {
            return;
        }
        $cid = (int) $m->company_id;
        $id = (int) $m->id;
        $branchId = (int) $m->branch_id;
        $name = trim($m->first_name.' '.$m->last_name) ?: 'Someone';
        $actor = auth()->id() ? (int) auth()->id() : ($m->created_by_id ? (int) $m->created_by_id : null);
        self::later('kin_person', function () use ($cid, $id, $branchId, $name, $actor) {
            $branch = DB::table('kin_branches')->where('company_id', $cid)->where('id', $branchId)->first(['name', 'coordinator_user_id']);
            if ($branch === null || ! $branch->coordinator_user_id) {
                return;
            }
            self::tell($cid, 'family-roots', 'kin_people', self::users([$branch->coordinator_user_id]), 'New in '.$branch->name,
                "{$name} was added to the {$branch->name} branch.", "kin/person/{$id}", "kin-person:{$id}", $actor);
        });
    }

    // ---- Membership ----

    /** Money received on a membership subscription (UnitySubscription saved with more paid than before). */
    public static function unityPaid(\App\Models\UnitySubscription $s): void
    {
        if (self::fromConsole()) {
            return;
        }
        $before = $s->wasRecentlyCreated ? 0 : (int) $s->getOriginal('paid_minor');
        $paid = (int) $s->paid_minor;
        if ($paid <= $before || (! $s->wasRecentlyCreated && ! $s->wasChanged('paid_minor'))) {
            return;
        }
        $cid = (int) $s->company_id;
        $sid = (int) $s->id;
        $memberId = (int) $s->member_id;
        $amount = $paid - $before;
        $actor = auth()->id() ? (int) auth()->id() : ($s->created_by_id ? (int) $s->created_by_id : null);
        self::later('unity_paid', function () use ($cid, $sid, $memberId, $amount, $paid, $actor) {
            $m = DB::table('sacco_members')->where('id', $memberId)->first(['first_name', 'last_name']);
            $who = $m ? trim($m->first_name.' '.$m->last_name) : 'A member';
            self::tell($cid, 'unitydesk', 'unity_paid', self::managers($cid), 'Payment received', "{$who} paid ".Minor::format($amount, $cid).' for their membership.',
                "member/{$memberId}", "unity-pay:{$sid}:{$paid}", $actor);
        });
    }

    // ---- Every app ----

    /** The person whose role changed hears it from the account. */
    public static function roleChanged(Company $company, User $member, ?string $before, string $role): void
    {
        if ($before === $role) {
            return;
        }
        $cid = (int) $company->id;
        $name = (string) $company->name;
        self::later('role_changed', function () use ($cid, $name, $member, $role) {
            $label = ucfirst(str_replace('_', ' ', $role));
            self::tell($cid, null, 'team', [$member], 'Your role changed', "You are now {$label} on {$name}.", 'notifications');
        });
    }

    // ---- Shop ----

    /**
     * A sale far above the usual (at least 5 times the 30-day average, with at least 20 sales of history): the managers hear it,
     * not the cashier who made it. The average is worked out once a day per shop.
     */
    public static function bigSale(int $companyId, \App\Models\SaleRecord $sale, int $userId): void
    {
        $sid = (int) $sale->id;
        $total = (float) $sale->total_amount;
        if ($total <= 0) {
            return;
        }
        self::later('big_sale', function () use ($companyId, $sid, $total, $userId) {
            $avg = \Illuminate\Support\Facades\Cache::remember("engage:avg-sale:{$companyId}:".now()->toDateString(), 86400, function () use ($companyId) {
                $row = DB::table('sale_records')->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('voided_at')
                    ->where('sale_date', '>=', now()->subDays(30)->toDateString())->where('sale_date', '<', now()->toDateString())
                    ->selectRaw('COUNT(*) AS n, AVG(total_amount) AS a')->first();

                return ['n' => (int) ($row->n ?? 0), 'a' => (float) ($row->a ?? 0)];
            });
            if ($avg['n'] < 20 || $avg['a'] <= 0 || $total < 5 * $avg['a']) {
                return;
            }
            $cashier = (string) (DB::table('admin_users')->where('id', $userId)->value('name') ?: 'the till');
            self::tell($companyId, null, 'big_sale', self::managers($companyId), 'A big sale', Money::format($total, null, $companyId)." by {$cashier}, about "
                .(int) floor($total / $avg['a']).' times a usual sale.', "sale/{$sid}", "big-sale:{$sid}", $userId);
        });
    }
}
