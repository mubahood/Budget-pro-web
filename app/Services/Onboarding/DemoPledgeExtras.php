<?php

namespace App\Services\Onboarding;

use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\User;
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

    /** The same E.164 mobile number for a name every time (+256 7x xxx xxxx). */
    public static function phoneFor(string $name): string
    {
        $prefixes = ['70', '71', '72', '74', '75', '76', '77', '78'];
        $h = crc32($name);

        return '+256'.$prefixes[$h % 8].sprintf('%07d', intdiv($h, 8) % 10000000);
    }

    private function skip(string $what, \Throwable $e): void
    {
        if (! in_array($what, $this->skipped, true)) {
            $this->skipped[] = $what;
            Log::info("[demo] {$what} not added: ".$e->getMessage());
        }
    }
}
