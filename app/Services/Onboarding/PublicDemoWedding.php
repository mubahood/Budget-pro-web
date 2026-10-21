<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Services\Budget\BudgetTemplates;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventSettings;
use App\Services\Budget\TemplateApplier;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brian and Grace's pledge books in the public demo (database/data/demo_wedding.php), in Uganda shillings:
 *
 *  - Brian & Grace Kukyala: the visit six weeks ago, closed, every pledge paid and every item paid out;
 *  - Brian & Grace Kwanjula: in five weeks, about 50 pledgers, messages in Luganda;
 *  - Brian & Grace Wedding: in eleven weeks, about 115 pledgers in eight groups.
 *
 * Each budget comes from its template (TemplateApplier, the same rules as the phone and the web), with what has
 * been paid out so far (one item over budget, so "Worth a look" has something to say). Each pledge's paid figure
 * is the sum of its payments (ContributionPayments), recorded on their own days over the past weeks by cash, MTN
 * or Airtel mobile money or bank, some in parts, and one payment recorded on the wrong pledge and reversed with
 * a reason. The settings (venue, groups, message language, a custom reminder, presets, where to pay, the committee
 * meeting) are the event's own. Dates count from today, so the countdowns always lie ahead.
 *
 * Everything goes through the models and services with the clock moved to each moment, and nothing is dated
 * after the real "now". The committee comes from DemoPledgeExtras (team members only).
 */
class PublicDemoWedding
{
    public const WEDDING = 'wedding';

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    private array $data;

    private Company $co;

    private User $by;

    private string $tz;

    private Carbon $now;

    private Carbon $today;

    public function __construct()
    {
        $this->data = require dirname(__DIR__, 3).'/database/data/demo_wedding.php';
    }

    /** @return array<string, string> event key => name */
    public static function names(): array
    {
        $data = require dirname(__DIR__, 3).'/database/data/demo_wedding.php';

        return array_map(fn ($e) => (string) $e['name'], $data['events']);
    }

    /**
     * The three events, built from nothing into the company, as $by (the shop's manager: the treasurer).
     *
     * @param  array<int, string>  $committee  user id => role
     * @return array{events: array<string, int>, wedding: int}
     */
    public function seed(Company $co, User $by, array $committee = [], ?Carbon $now = null, ?string $tz = null): array
    {
        $this->co = $co;
        $this->by = $by;
        $this->tz = (string) ($tz ?: $co->timezone ?: 'Africa/Kampala');
        $this->now = ($now ?? now())->copy();
        $this->today = $this->now->copy()->setTimezone($this->tz)->startOfDay();
        $clock = Carbon::getTestNow();
        $guards = $this->actAs($by);
        $notify = BudgetItem::$notify;
        BudgetItem::$notify = false;
        $events = [];
        try {
            OnboardingEvents::muted(function () use (&$events, $committee) {
                foreach ($this->data['events'] as $key => $event) {
                    mt_srand(crc32('demo-pledges-'.$key)); // the same people and amounts on every build
                    $events[$key] = $this->event($key, $event);
                    if ($events[$key] > 0 && $committee !== []) {
                        $this->skipped = array_merge($this->skipped, (new DemoPledgeExtras())->apply($events[$key], (int) $this->by->id, $committee, false));
                    }
                }
            });
        } finally {
            BudgetItem::$notify = $notify;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['events' => $events, 'wedding' => (int) ($events[self::WEDDING] ?? 0)];
    }

    /** @return int the event's id (0 when it could not be made) */
    private function event(string $key, array $e): int
    {
        $cid = (int) $this->co->id;
        $template = BudgetTemplates::find($e['template']);
        $day = $this->today->copy()->addDays((int) $e['day']);
        $this->at((int) $e['recorded_from'] + 2, 20);

        $program = new BudgetProgram();
        $program->company_id = $cid;
        $program->name = $e['name'];
        $program->title = $e['name'];
        $program->status = 'Active';
        $program->is_active = 'Yes';
        $program->is_default = ! empty($e['default']) ? 'Yes' : 'No';
        $program->deadline = $day->toDateString();
        $program->bottom = $e['bottom'] ?? null;
        $program->venue = $e['venue'] ?? null;
        $program->event_type = $template['event_type'] ?? null;
        if ($this->quietly(fn () => $program->save()) === null) {
            return 0;
        }
        $pid = (int) $program->id;

        // The budget, from its template, then what has been paid out so far.
        if ($template !== null) {
            $this->at((int) $e['recorded_from'] + 1, 20);
            $this->quietly(fn () => (new TemplateApplier())->applyTemplate($this->by, $program, $template['key'], (int) $e['guests'], false));
            $this->spend($pid, (array) ($e['spent'] ?? []));
        }

        // Its settings.
        $program = $program->fresh();
        $program->settings = EventSettings::settingsJson(array_filter([
            'messages' => ! empty($e['reminder']) ? ['reminder' => $e['reminder']] : null,
            'message_lang' => $e['message_lang'] ?? null,
            'presets' => [50000, 100000, 200000, 500000, 1000000],
            'guests' => (int) $e['guests'],
            'meeting' => $e['meeting'] ?? null,
        ] + EventSettings::settings($program->settings)));
        $program->payment_details = EventSettings::paymentDetailsJson([
            'mtn' => '+256772555210', 'mtn_name' => $this->by->name, 'airtel' => '+256701555210', 'airtel_name' => $this->by->name,
            'bank' => 'Stanbic Bank, Mukono branch', 'bank_account' => '9030012345678', 'bank_account_name' => $this->data['couple']['groom'],
            'treasurer' => $this->by->name, 'treasurer_phone' => '+256772555210', 'note' => 'Use your name as the reference, please.',
        ]);
        $this->quietly(fn () => $program->save());

        // The pledges and their payments.
        $this->pledges($pid, $e);
        if ($key === self::WEDDING) {
            $this->reversal($pid);
        }
        if (empty($e['open'])) {
            DB::table('budget_programs')->where('id', $pid)->update(['status' => 'Inactive', 'is_active' => 'No']); // closed after the day
        }
        BudgetProgram::recalculateFromChildren($pid);

        return $pid;
    }

    /** Paid out so far: a share of each named item's budget ('*' = every item). */
    private function spend(int $pid, array $shares): void
    {
        if ($shares === []) {
            return;
        }
        foreach (BudgetItem::withoutGlobalScopes()->where('budget_program_id', $pid)->where('is_deleted', 0)->orderBy('id')->get() as $item) {
            $share = $shares[$item->name] ?? $shares['*'] ?? null;
            if ($share === null || (float) $share <= 0) {
                continue;
            }
            $item->invested_amount = Decimal::of((string) (round((float) $item->target_amount * (float) $share / 1000) * 1000));
            $item->approved = 'Yes';
            $this->quietly(fn () => $item->save());
        }
    }

    /** The event's pledgers, group by group, each with their payments. */
    private function pledges(int $pid, array $e): void
    {
        $cid = (int) $this->co->id;
        $people = $this->people($e);
        $n = count($people);
        $from = (int) $e['recorded_from'];
        $to = (int) $e['recorded_to'];
        $payments = new ContributionPayments();
        foreach ($people as $k => [$group, $name, $amount]) {
            $daysAgo = $from - intdiv($k * max(0, $from - $to), max(1, $n - 1));
            $this->at($daysAgo, 19 + $k % 3, ($k * 13) % 60);
            $p = new ContributionRecord();
            $p->budget_program_id = $pid;
            $p->company_id = $cid;
            $p->treasurer_id = $this->by->id;
            $p->name = $name;
            $p->category_id = \App\Services\Budget\EventBook::groupKey($group);
            $p->amount = $amount;
            $p->paid_amount = 0;
            $p->fully_paid = 'No';
            $p->phone = $k % 17 === 9 ? null : DemoPledgeExtras::phoneFor($name); // a few without a number, as in real books
            if ($this->quietly(fn () => $p->save()) === null || ! $p->exists) {
                continue;
            }
            $roll = mt_rand(1, 100);
            [$paidShare, $partShare] = [(int) $e['status']['paid'], (int) $e['status']['part']];
            $status = $roll <= $paidShare ? 'paid' : ($roll <= $paidShare + $partShare ? 'part' : 'none');
            if ($status === 'none') {
                continue;
            }
            $paid = $status === 'paid' ? $amount : max(10000, (int) (round($amount * mt_rand(25, 75) / 100 / 10000) * 10000));
            $paid = min($paid, $amount);
            $parts = $status === 'paid' && $paid >= 200000 && mt_rand(1, 100) <= 45 ? mt_rand(2, 3) : ($status === 'part' && $paid >= 100000 && mt_rand(0, 1) ? 2 : 1);
            $this->pay($payments, $cid, (int) $p->id, $paid, $parts, $daysAgo, $k);
        }
    }

    /** One pledge's payments: $parts instalments between the day it was pledged and today. */
    private function pay(ContributionPayments $payments, int $cid, int $recordId, int $paid, int $parts, int $pledgedDaysAgo, int $k): void
    {
        $left = $paid;
        for ($j = 0; $j < $parts && $left > 0; $j++) {
            $amount = $j === $parts - 1 ? $left : (int) (round($paid / $parts / 10000) * 10000);
            $amount = max(1, min($left, $amount));
            $daysAgo = max(0, $pledgedDaysAgo - (int) round(($j + 1) * $pledgedDaysAgo / ($parts + 1)) - mt_rand(0, 2));
            $when = $this->at($daysAgo, mt_rand(8, 20), mt_rand(0, 59));
            $how = mt_rand(1, 100);
            [$method, $reference, $notes] = match (true) {
                $how <= 30 => ['mobile_money', 'MTN MoMo '.mt_rand(10000000, 99999999).mt_rand(10, 99), null],
                $how <= 45 => ['mobile_money', 'Airtel Money '.mt_rand(10000000, 99999999).mt_rand(10, 99), null],
                $how <= 82 => ['cash', null, mt_rand(0, 1) ? 'Given at the committee meeting' : null],
                default => ['bank', 'Stanbic deposit '.mt_rand(100000, 999999), null],
            };
            $ok = $this->quietly(fn () => $payments->record($cid, $recordId, ['amount' => $amount, 'paid_on' => $when->copy()->setTimezone($this->tz)->toDateString(), 'method' => $method,
                'reference' => $reference, 'notes' => $notes ?? ($j > 0 && $j === $parts - 1 ? 'The rest of the pledge' : null)], (int) $this->by->id));
            if ($ok === null) {
                return;
            }
            $left -= $amount;
        }
    }

    /** A payment written on the wrong pledge, reversed with a reason and recorded on the right one. */
    private function reversal(int $pid): void
    {
        $owing = DB::table('contribution_records')->where('budget_program_id', $pid)->where('is_deleted', 0)->where('not_paid_amount', '>=', 100000)
            ->orderBy('id')->limit(12)->get(['id', 'name']);
        if ($owing->count() < 2) {
            return;
        }
        [$wrong, $right] = [$owing[3] ?? $owing[0], $owing[8] ?? $owing[1]];
        $cid = (int) $this->co->id;
        $payments = new ContributionPayments();
        $this->at(9, 18, 10);
        $made = $this->quietly(fn () => $payments->record($cid, (int) $wrong->id, ['amount' => 100000, 'paid_on' => $this->today->copy()->subDays(9)->toDateString(),
            'method' => 'mobile_money', 'reference' => 'MTN MoMo 7712045531'], (int) $this->by->id));
        if ($made === null) {
            return;
        }
        $this->at(8, 9, 40);
        $this->quietly(fn () => $payments->reverse($cid, (int) $made['payment']->id, "Recorded on the wrong pledge: the money came from {$right->name}.", (int) $this->by->id));
        $this->quietly(fn () => $payments->record($cid, (int) $right->id, ['amount' => 100000, 'paid_on' => $this->today->copy()->subDays(9)->toDateString(),
            'method' => 'mobile_money', 'reference' => 'MTN MoMo 7712045531', 'notes' => "Moved from {$wrong->name}'s pledge"], (int) $this->by->id));
    }

    /** @return list<array{0: string, 1: string, 2: int}> [group, name, amount], the named people first in their groups */
    private function people(array $e): array
    {
        $taken = [];
        foreach ((array) $this->data['named'] as $names) {
            foreach ($names as $named) {
                $taken[mb_strtolower($named)] = true; // never made up twice
            }
        }
        $out = [];
        foreach ($e['groups'] as $spec) {
            [$group, $count, $amounts] = $spec;
            $lead = isset($spec[3]) ? (int) $spec[3] : null;
            $named = array_slice((array) ($this->data['named'][$group] ?? []), 0, $count);
            for ($i = 0; $i < $count; $i++) {
                $name = $named[$i] ?? $this->name($taken);
                $taken[mb_strtolower($name)] = true;
                // The first named person of a group (the parents, the office, the old class) pledges its lead amount.
                $amount = $i === 0 && $lead !== null ? $lead : $amounts[mt_rand(0, count($amounts) - 1)];
                $out[] = [$group, $name, (int) $amount];
            }
        }
        // Mixed, as they came in (not group by group), with the same order on every build.
        usort($out, fn ($a, $b) => crc32($a[1]) <=> crc32($b[1]));

        return $out;
    }

    private function name(array $taken): string
    {
        for ($try = 0; $try < 200; $try++) {
            $title = $this->data['titles'][mt_rand(0, count($this->data['titles']) - 1)];
            $name = $title.$this->data['surnames'][mt_rand(0, count($this->data['surnames']) - 1)].' '.$this->data['first_names'][mt_rand(0, count($this->data['first_names']) - 1)];
            if (! isset($taken[mb_strtolower($name)])) {
                return $name;
            }
        }

        return 'Well-wisher '.(count($taken) + 1);
    }

    /** Move the clock to $daysAgo days before today at $hour:$minute (never after the real now). */
    private function at(int $daysAgo, int $hour, int $minute = 0): Carbon
    {
        $t = Carbon::parse($this->today->copy()->subDays($daysAgo)->toDateString().sprintf(' %02d:%02d:00', $hour, $minute), $this->tz)->utc();
        if ($t->greaterThan($this->now)) {
            $t = $this->now->copy();
        }
        Carbon::setTestNow($t);

        return $t;
    }

    private function quietly(callable $fn): mixed
    {
        try {
            return $fn() ?? true;
        } catch (BusinessRuleException|\DomainException|\InvalidArgumentException $e) {
            $this->skipped[] = $e->getMessage();
            Log::info('[public-demo] pledge sample skipped: '.$e->getMessage());

            return null;
        }
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
