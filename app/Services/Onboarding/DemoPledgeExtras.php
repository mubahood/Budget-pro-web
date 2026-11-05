<?php

namespace App\Services\Onboarding;

use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventBook;
use App\Services\Budget\EventClosure;
use App\Services\Budget\PledgeFollowUps;
use App\Services\Budget\PledgeService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The demo wedding's data for the pledge features of roadmap 02 §6: a phone number on each pledge, the
 * payment history behind each "paid" figure, and the committee. Each part runs only once its table,
 * column and service exist (Schema::hasTable / hasColumn, class_exists), goes through the app's own
 * services, and never adds what is already there. So the public demo's hourly heal can call it every
 * time, and the data appears by itself the day the migrations run.
 *
 * Nothing here is fatal: a refusal is logged and listed in what apply() returns.
 */
class DemoPledgeExtras
{
    public const PAYMENTS_SERVICE = 'App\\Services\\Budget\\ContributionPayments';

    public const COMMITTEE_SERVICE = 'App\\Services\\Budget\\CommitteeService';

    /** @var list<string> what could not be added */
    private array $skipped = [];

    /**
     * Add what is missing to one event: phones on its pledges (unless $phones is false: the public demo leaves a
     * few pledges without a number on purpose), and the committee ($committee: user id => role).
     *
     * @param  array<int, string>  $committee
     * @return list<string> parts skipped because a rule or the schema refused them
     */
    public function apply(int $programId, int $byUserId, array $committee = [], bool $phones = true): array
    {
        $program = BudgetProgram::withoutGlobalScopes()->find($programId);
        if ($program === null) {
            return [];
        }
        if ($phones) {
            $this->phones($programId);
        }
        $this->committee($program, $byUserId, $committee);

        return $this->skipped;
    }

    /** Can a pledge's payments be recorded one by one (the payment history exists)? */
    public static function paymentsReady(): bool
    {
        return class_exists(self::PAYMENTS_SERVICE) && method_exists(self::PAYMENTS_SERVICE, 'record') && Schema::hasTable('contribution_payments');
    }

    /** Do pledges carry the pledger's phone? */
    public static function phonesReady(): bool
    {
        return Schema::hasColumn('contribution_records', 'phone');
    }

    /**
     * A pledge's paid amount as its payment history: one payment, or two for every third pledge (60%, then
     * the rest), dated between the pledge and now, by mobile money or cash. False when the history refused
     * it (the caller then sets the paid figure the older way).
     */
    public function pay(int $companyId, int $recordId, int|float $paid, int $k, int $userId, Carbon $pledgedAt, Carbon $now): bool
    {
        if ($paid <= 0) {
            return true;
        }
        $first = $k % 3 === 0 && $paid >= 10 ? round($paid * 0.6, 0) : $paid;
        $parts = $first < $paid ? [$first, round($paid - $first, 2)] : [$paid];
        $span = max(0, (int) $pledgedAt->diffInDays($now));
        $clock = Carbon::getTestNow();
        try {
            foreach ($parts as $j => $amount) {
                $when = $pledgedAt->copy()->addDays(count($parts) === 1 ? intdiv($span, 3) : ($j === 0 ? min($span, 2) : intdiv($span * 2, 3)))->setTime(18, 30);
                $when = $when->greaterThan($now) ? $now->copy() : $when;
                Carbon::setTestNow($when);
                app(self::PAYMENTS_SERVICE)->record($companyId, $recordId, [
                    'amount' => $amount, 'paid_on' => $when->toDateString(), 'method' => ($k + $j) % 3 === 0 ? 'cash' : 'mobile_money',
                    'notes' => $j === 0 ? null : 'The rest of the pledge',
                ], $userId);
            }

            return true;
        } catch (\Throwable $e) {
            $this->skip('payment history', $e);

            return false;
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    /** A Ugandan mobile number on every pledge that has none, through the model (so phones that synced see it). */
    private function phones(int $programId): void
    {
        if (! self::phonesReady()) {
            return;
        }
        $ids = DB::table('contribution_records')->where('budget_program_id', $programId)->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))->pluck('id');
        foreach ($ids as $id) {
            try {
                $p = ContributionRecord::withoutGlobalScopes()->find($id);
                if ($p !== null) {
                    $p->phone = self::phoneFor((string) $p->name);
                    $p->save();
                }
            } catch (\Throwable $e) {
                $this->skip('pledge phones', $e);
            }
        }
    }

    /** The committee: each person not on it yet is added with their role (CommitteeService). */
    private function committee(BudgetProgram $program, int $byUserId, array $committee): void
    {
        if ($committee === [] || ! class_exists(self::COMMITTEE_SERVICE) || ! Schema::hasTable('budget_program_members')) {
            return;
        }
        $by = User::withoutGlobalScopes()->find($byUserId);
        if ($by === null) {
            return;
        }
        foreach ($committee as $userId => $role) {
            $on = DB::table('budget_program_members')->where('budget_program_id', $program->id)->where('user_id', $userId)->where('is_deleted', 0)->exists();
            if ($on || ! DB::table('admin_users')->where('id', $userId)->where('company_id', $program->company_id)->exists()) {
                continue;
            }
            try {
                app(self::COMMITTEE_SERVICE)->add($by, $program, ['user_id' => (int) $userId, 'role' => $role]);
            } catch (\Throwable $e) {
                $this->skip('committee', $e);
            }
        }
    }

    /** The same E.164 mobile number for a name every time, in the 555 block the demo marks as made up (+256 7x 555 xxxx). */
    public static function phoneFor(string $name): string
    {
        $prefixes = ['70', '71', '72', '74', '75', '76', '77', '78'];
        $h = crc32($name);

        return '+256'.$prefixes[$h % 8].'555'.sprintf('%04d', intdiv($h, 8) % 10000);
    }

    /** Whether any of an account's events has paid out more than came in. */
    public static function inHandBelowZero(Company $company): bool
    {
        foreach (app(EventBook::class)->events($company, 'all') as $event) {
            if (Decimal::cmp((string) $event['in_hand'], '0') < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pledge & Budget's own account on top of the books (the review's PB-30): the days on Saturdays, money with someone else
     * holding it, a pledge paid past its figure (Extra), a possible double payment, a withdrawn pledge, an in-kind pledge, who
     * was reminded and who promised, and on the closed kukyala the thank-yous and the final account. Through the services, with
     * the clock moved. Nothing here is fatal.
     *
     * @param  array<string, int>  $events  event key => id
     * @param  array<string, User>  $staff  first name in lower case => member
     * @return list<string> what could not be added
     */
    public function story(Company $co, array $events, array $staff, User $by, Carbon $now, string $tz): array
    {
        $clock = Carbon::getTestNow();
        $today = $now->copy()->setTimezone($tz)->startOfDay();
        $story = (array) (PublicDemo::data('pledge-and-budget')['story'] ?? []);
        $days = (array) (require dirname(__DIR__, 3).'/database/data/demo_wedding.php')['events'];
        try {
            foreach ($events as $key => $id) {
                DB::table('budget_programs')->where('id', $id)->update(['deadline' => self::nearestSaturday($today->copy()->addDays((int) $days[$key]['day']))->toDateString()]);
            }
            $this->spendingHistory(array_values($events), $by, $today, $tz);
            if (! empty($events['wedding'])) {
                $this->weddingStory($co, (int) $events['wedding'], $story, $staff, $by, $now, $tz, $today);
            }
            if (! empty($events['kwanjula'])) {
                $this->chase((int) $co->id, (int) $events['kwanjula'], $by, $now, $today, 6, 2, 1);
            }
            if (! empty($events['kukyala'])) {
                $this->closedStory((int) $events['kukyala'], $story, $by, $now, $today);
            }
        } finally {
            Carbon::setTestNow($clock);
        }

        return $this->skipped;
    }

    public static function nearestSaturday(Carbon $day): Carbon
    {
        $ahead = (Carbon::SATURDAY - $day->dayOfWeek + 7) % 7;

        return $ahead > 3 ? $day->subDays(7 - $ahead) : $day->addDays($ahead);
    }

    /** @param array<string, mixed> $story @param array<string, User> $staff */
    private function weddingStory(Company $co, int $pid, array $story, array $staff, User $by, Carbon $now, string $tz, Carbon $today): void
    {
        $cid = (int) $co->id;
        $program = BudgetProgram::withoutGlobalScopes()->find($pid);
        $payments = new ContributionPayments();
        $service = new PledgeService();
        $allan = $staff['allan'] ?? $by;
        $owing = fn (bool $paidSome = false) => DB::table('contribution_records')->where('budget_program_id', $pid)->where('is_deleted', 0)->where('not_paid_amount', '>', 0)
            ->where('paid_amount', $paidSome ? '>' : '=', 0)->orderBy('id');
        $attempt = function (string $what, callable $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                $this->skip($what, $e);
            }
        };
        $moment = fn (int $daysAgo, int $hour) => Carbon::setTestNow(min($now->copy(), Carbon::parse($today->copy()->subDays($daysAgo)->toDateString().sprintf(' %02d:10:00', $hour), $tz)->utc()));

        // Withdrawn: one who has paid nothing, with the reason kept.
        $moment(12, 19);
        $attempt('withdrawn pledge', function () use ($service, $by, $owing, $story) {
            $row = $owing()->first(['id']);
            if ($row !== null) {
                $service->withdraw($by, ContributionRecord::withoutGlobalScopes()->findOrFail($row->id), (string) $story['withdrawn_reason']);
            }
        });

        // In kind: never in the money figures.
        $moment(10, 18);
        $attempt('in-kind pledge', fn () => $service->saveInKind($by, $program, (array) $story['in_kind'] + ['phone' => self::phoneFor((string) $story['in_kind']['name'])]));

        // Cash the secretary took at a meeting: who holds the money is not always the treasurer.
        foreach ($owing(true)->limit(3)->get(['id', 'not_paid_amount']) as $i => $row) {
            $moment(6 + $i, 19);
            $attempt('received by', fn () => $payments->record($cid, (int) $row->id, ['amount' => min((float) $row->not_paid_amount, 50000), 'paid_on' => now($tz)->toDateString(), 'method' => 'cash',
                'notes' => 'Given to Allan at the committee meeting', 'received_by_id' => $allan->id], (int) $by->id));
        }

        // Extra: a phone's offline payment past the pledge, shown and never refused.
        $moment(5, 17);
        $attempt('extra', function () use ($payments, $cid, $by, $owing, $tz) {
            $row = $owing(true)->offset(3)->first(['id', 'not_paid_amount']);
            if ($row !== null) {
                $payments->record($cid, (int) $row->id, ['amount' => Decimal::add((string) $row->not_paid_amount, '50000'), 'paid_on' => now($tz)->toDateString(), 'method' => 'cash', 'notes' => 'Paid more than the pledge'], (int) $by->id, true);
            }
        });

        // One reference on two pledges: Worth a look asks about it.
        $moment(3, 11);
        $attempt('double payment', function () use ($payments, $cid, $by, $owing, $story, $tz) {
            foreach ($owing(true)->where('not_paid_amount', '>=', 50000)->offset(4)->limit(2)->get(['id']) as $row) {
                $payments->record($cid, (int) $row->id, ['amount' => 50000, 'paid_on' => now($tz)->toDateString(), 'method' => 'mobile_money', 'reference' => (string) $story['double_reference'], 'confirm_duplicate' => true], (int) $by->id);
            }
        });

        $this->chase($cid, $pid, $by, $now, $today, 12, 4, 3);
    }

    /** What the budgets paid out so far, as a ledger with made-up suppliers, on days across the weeks gone by; each figure as it was. @param list<int> $events */
    private function spendingHistory(array $events, User $by, Carbon $today, string $tz): void
    {
        if (! Schema::hasTable('budget_payments')) {
            return;
        }
        // A line's figure becomes its first row of history here, as the ledger itself would do it the first time it is touched.
        $ledger = new \App\Services\Budget\BudgetPayments();
        foreach (DB::table('budget_items')->whereIn('budget_program_id', $events)->where('is_deleted', 0)->where('invested_amount', '>', 0)->pluck('id') as $itemId) {
            $ledger->adopt((int) $itemId);
        }
        $suppliers = ['Kisaasi Tent Hire', 'Zawadi Caterers', 'Nakawa Photo Works', 'Ntinda Print House', 'Mirembe Car Hire', 'Bella Decor', 'Kato Tailors', 'Sunrise Sound'];
        foreach (DB::table('budget_payments as p')->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')->whereIn('p.budget_program_id', $events)->whereIn('p.source', ['opening', 'item_edit'])->get(['p.id', 'p.paid_on', 'i.name']) as $row) {
            $h = crc32((string) $row->name);
            $on = Carbon::parse((string) $row->paid_on, $tz)->addDays($h % 30);
            DB::table('budget_payments')->where('id', $row->id)->update(['paid_to' => $suppliers[$h % count($suppliers)], 'method' => ['cash', 'mobile_money', 'bank'][$h % 3], 'source' => 'payment', 'notes' => null,
                'paid_on' => $on->greaterThan($today) ? $today->toDateString() : $on->toDateString(), 'paid_by_id' => $by->id]);
        }
    }

    /** Who was reminded lately: $reminded reminders, $promised who said a day still to come, $overdue who said a day now passed. */
    private function chase(int $cid, int $pid, User $by, Carbon $now, Carbon $today, int $reminded, int $promised, int $overdue): void
    {
        $rows = DB::table('contribution_records')->where('budget_program_id', $pid)->where('is_deleted', 0)->where('not_paid_amount', '>', 0)->whereNotNull('phone')->orderByDesc('not_paid_amount')->orderBy('id')->limit($reminded + $promised + $overdue)->pluck('id');
        $follow = new PledgeFollowUps();
        foreach ($rows as $i => $id) {
            $daysAgo = 2 + $i % 9;
            Carbon::setTestNow(min($now->copy(), $today->copy()->subDays($daysAgo)->setTime(18, 20)->utc()));
            $data = ['kind' => 'reminder', 'channel' => 'whatsapp'];
            if ($i < $overdue) {
                $data = ['kind' => 'note', 'channel' => 'call', 'outcome' => 'will_pay', 'promised_on' => $today->copy()->subDays(1 + $i)->toDateString(), 'note' => 'Said by the end of the week.'];
            } elseif ($i < $overdue + $promised) {
                $data = ['kind' => 'note', 'channel' => 'whatsapp', 'outcome' => 'will_pay', 'promised_on' => $today->copy()->addDays(5 + $i)->toDateString(), 'note' => 'Will pay on salary day.'];
            }
            try {
                $follow->record($by, ContributionRecord::withoutGlobalScopes()->findOrFail($id), $data);
            } catch (\Throwable $e) {
                $this->skip('follow-ups', $e);
            }
        }
    }

    /** The closed kukyala: a thank-you to everyone who paid, and what its final account says. @param array<string, mixed> $story */
    private function closedStory(int $pid, array $story, User $by, Carbon $now, Carbon $today): void
    {
        $day = (string) DB::table('budget_programs')->where('id', $pid)->value('deadline');
        $follow = new PledgeFollowUps();
        Carbon::setTestNow(Carbon::parse($day)->addDays(2)->setTime(19, 0));
        EventClosure::allowing(function () use ($pid, $follow, $by) {
            foreach (DB::table('contribution_records')->where('budget_program_id', $pid)->where('is_deleted', 0)->whereNotNull('phone')->pluck('id') as $id) {
                try {
                    $follow->record($by, ContributionRecord::withoutGlobalScopes()->findOrFail($id), ['kind' => 'thanks', 'channel' => 'whatsapp']);
                } catch (\Throwable $e) {
                    $this->skip('thank-yous', $e);
                }
            }
        });
        $settings = json_decode((string) DB::table('budget_programs')->where('id', $pid)->value('settings'), true) ?: [];
        $settings['closing'] = (array) $story['closing'];
        DB::table('budget_programs')->where('id', $pid)->update(['settings' => json_encode($settings), 'closed_at' => Carbon::parse($day)->addDays(3)->setTime(12, 0)]);
    }

    /**
     * Today on the open events, for the hourly upkeep: one reminder for each of the day's rounds so far, counted from the
     * books, so a heal never adds one twice. A payment out joins it once the spending ledger is in (PB-13).
     *
     * @param  array<string, int>  $events
     */
    public function remindToday(Company $co, array $events, User $by, Carbon $now, string $tz): int
    {
        $open = array_values(array_filter(array_map('intval', array_intersect_key($events, array_flip(['kwanjula', 'wedding'])))));
        if ($open === []) {
            return 0;
        }
        $local = $now->copy()->setTimezone($tz);
        $due = max(1, count(array_filter([8, 11, 14, 17, 20], fn ($h) => $h <= (int) $local->format('G'))));
        $from = $local->copy()->startOfDay()->utc();
        $have = DB::table('pledge_follow_ups')->whereIn('budget_program_id', $open)->where('created_at', '>=', $from)->count();
        $seen = DB::table('pledge_follow_ups')->whereIn('budget_program_id', $open)->where('created_at', '>=', $from)->pluck('contribution_record_id');
        $rows = DB::table('contribution_records')->whereIn('budget_program_id', $open)->where('is_deleted', 0)->where('not_paid_amount', '>', 0)->whereNotNull('phone')->whereNotIn('id', $seen)
            ->orderBy('updated_at')->limit(max(0, $due - $have))->pluck('id');
        $follow = new PledgeFollowUps();
        $added = 0;
        foreach ($rows as $id) {
            try {
                $follow->record($by, ContributionRecord::withoutGlobalScopes()->findOrFail($id), ['kind' => 'reminder', 'channel' => 'whatsapp']);
                $added++;
            } catch (\Throwable $e) {
                $this->skip('follow-ups', $e);
            }
        }

        $this->payOutToday((int) $co->id, $open, $by, $now, $tz);

        return $added;
    }

    /** One small payment out a day on the wedding's next open line, and only while plenty stays in hand. @param list<int> $open */
    private function payOutToday(int $cid, array $open, User $by, Carbon $now, string $tz): void
    {
        $pid = (int) end($open);
        if (! class_exists(\App\Services\Budget\BudgetPayments::class) || ! Schema::hasTable('budget_payments')) {
            return;
        }
        $date = $now->copy()->setTimezone($tz)->toDateString();
        if (DB::table('budget_payments')->where('budget_program_id', $pid)->where('paid_on', $date)->where('is_deleted', 0)->exists()) {
            return;
        }
        $company = Company::withoutGlobalScopes()->find($cid);
        $event = $company ? app(EventBook::class)->event($company, $pid) : null;
        $item = DB::table('budget_items')->where('budget_program_id', $pid)->where('is_deleted', 0)->whereRaw('target_amount - invested_amount >= 50000')->orderBy('id')->first(['id']);
        if ($event === null || $item === null || Decimal::cmp((string) $event['in_hand'], '500000') < 0) {
            return;
        }
        try {
            (new \App\Services\Budget\BudgetPayments())->record($cid, (int) $item->id, ['amount' => 50000, 'paid_on' => $date, 'method' => 'cash', 'paid_to' => 'Mirembe Car Hire', 'notes' => 'Part payment'], (int) $by->id);
        } catch (\Throwable $e) {
            $this->skip('payment out', $e);
        }
    }

    private function skip(string $what, \Throwable $e): void
    {
        if (! in_array($what, $this->skipped, true)) {
            $this->skipped[] = $what;
            Log::info("[demo] {$what} not added: ".$e->getMessage());
        }
    }
}
