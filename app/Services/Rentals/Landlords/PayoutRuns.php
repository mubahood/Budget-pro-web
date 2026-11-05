<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Models\RentalPayoutRun;
use App\Models\RentalPayoutRunLine;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Many landlords paid at once (LC-09), on any day and for part of a balance: a draft to print and take to the bank, then posted under one
 * lock as one normal payout per line (Payouts::record, one journal group each, so each reverses alone). An advance is never part of a run.
 * Posting re-reads every balance and account and refuses the whole run, naming the rows, if anything has changed. A posted run cannot be
 * posted again, and a cancelled draft leaves nothing. Agency staff with `rentals_landlords` only: a caretaker never reads this (EL-20).
 */
final class PayoutRuns
{
    /**
     * Every active landlord owed more than $above: what the first step lists.
     *
     * @return list<array{landlord_id: int, name: string, code: ?string, phone: ?string, phone_e164: ?string, phone_2: ?string, balance_minor: int, method: string, details: ?string, last_paid_on: ?string, last_paid_minor: ?int}>
     */
    public function candidates(User $by, int $above = 0): array
    {
        $cid = self::guard($by);
        $balances = LandlordAccounts::balances($cid);
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('is_self', false)->where('status', 'active')
            ->whereIn('id', array_keys(array_filter($balances, fn (int $b) => $b > max(0, $above))) ?: [0])->orderBy('name')->get();
        $last = [];
        foreach (RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->whereIn('landlord_id', $landlords->pluck('id')->all() ?: [0])->whereNull('reversed_at')
            ->orderBy('paid_on')->orderBy('id')->get(['landlord_id', 'paid_on', 'amount_minor']) as $p) {
            $last[(int) $p->landlord_id] = $p;
        }

        return $landlords->map(fn (RentalLandlord $l) => ['landlord_id' => (int) $l->id, 'name' => (string) $l->name, 'code' => $l->code, 'phone' => $l->phone, 'phone_e164' => $l->phone_e164, 'phone_2' => $l->phone_2, 'balance_minor' => (int) $balances[(int) $l->id],
            'method' => (string) ($l->payout_method ?: 'bank'), 'details' => $l->payout_details, 'last_paid_on' => isset($last[(int) $l->id]) ? $last[(int) $l->id]->paid_on->toDateString() : null,
            'last_paid_minor' => isset($last[(int) $l->id]) ? (int) $last[(int) $l->id]->amount_minor : null])->values()->all();
    }

    /**
     * Save the chosen landlords as a draft, or replace an open draft's lines ($run) with today's balances.
     *
     * @param  list<array{landlord_id: mixed, amount_minor: mixed, method: mixed}>  $lines
     */
    public function save(User $by, array $lines, ?RentalPayoutRun $run = null): RentalPayoutRun
    {
        $cid = self::guard($by);
        if ($lines === []) {
            throw BusinessRuleException::make('run_empty', 'Tick at least one landlord to pay.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lines, $run, $cid) {
            $checked = $this->checkLines($cid, $lines);
            $draft = $run !== null ? $this->lockedRun($cid, (int) $run->id) : null;
            if ($draft !== null && $draft->status !== 'draft') {
                throw BusinessRuleException::make('run_not_draft', "Payout run {$draft->number} is no longer a draft.");
            }
            $draft ??= RentalPayoutRun::create(['company_id' => $cid, 'number' => RentalsModule::nextNumber($cid, 'payout_run'), 'status' => 'draft', 'created_by_id' => $by->id]);
            RentalPayoutRunLine::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $draft->id)->delete();
            foreach ($checked as $l) {
                RentalPayoutRunLine::create($l + ['company_id' => $cid, 'run_id' => $draft->id]);
            }
            RentalAudit::record($cid, 'payout_run.saved', $draft, "Saved payout run {$draft->number}", ['lines' => count($checked), 'amount_minor' => array_sum(array_column($checked, 'amount_minor'))], 'staff', $by->id);

            return $draft->fresh();
        });
    }

    public function cancel(User $by, RentalPayoutRun $run): RentalPayoutRun
    {
        $cid = self::guard($by);
        self::assertOwn($run, $cid);

        return RentalLedger::locked($cid, function () use ($by, $run, $cid) {
            $r = $this->lockedRun($cid, (int) $run->id);
            if ($r->status !== 'draft') {
                throw BusinessRuleException::make('run_not_draft', $r->status === 'posted' ? "Payout run {$r->number} is paid. Reverse each payout, with a reason." : "Payout run {$r->number} was already cancelled.");
            }
            $r->forceFill(['status' => 'cancelled'])->save();
            RentalAudit::record($cid, 'payout_run.cancelled', $r, "Cancelled payout run {$r->number}", [], 'staff', $by->id);

            return $r->fresh();
        });
    }

    /**
     * Mark a draft as paid: every line becomes a payout, or none does. $data: paid_on (not closed, not future), reference (one for all),
     * references (line id => its own).
     *
     * @param  array{paid_on?: ?string, reference?: ?string, references?: array<int|string, ?string>}  $data
     */
    public function post(User $by, RentalPayoutRun $run, array $data = []): RentalPayoutRun
    {
        $cid = self::guard($by);
        self::assertOwn($run, $cid);
        $on = LocalDate::date($cid, $data['paid_on'] ?? null);
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A payout cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $run, $data, $cid, $on) {
            $r = $this->lockedRun($cid, (int) $run->id);
            if ($r->status !== 'draft') {
                throw BusinessRuleException::make('run_not_draft', $r->status === 'posted' ? "Payout run {$r->number} is already paid." : "Payout run {$r->number} was cancelled.");
            }
            $lines = RentalPayoutRunLine::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $r->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw BusinessRuleException::make('run_empty', 'This run has nobody on it.');
            }
            $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $lines->pluck('landlord_id')->all())->get()->keyBy('id');
            $this->assertUnchanged($cid, $lines, $landlords);
            $paidOn = $on->toDateString();
            foreach ($lines as $line) {
                $reference = trim((string) (($data['references'][$line->id] ?? null) ?: ($data['reference'] ?? '')));
                $payout = app(Payouts::class)->record($by, $landlords[$line->landlord_id], ['amount_minor' => (int) $line->amount_minor, 'method' => (string) $line->method, 'paid_on' => $paidOn,
                    'reference' => $reference !== '' ? $reference : null], (int) $r->id);
                $line->forceFill(['payout_id' => $payout->id, 'reference' => $payout->reference])->save();
            }
            $r->forceFill(['status' => 'posted', 'paid_on' => $paidOn, 'posted_by_id' => $by->id, 'posted_at' => now()])->save();
            RentalAudit::record($cid, 'payout_run.posted', $r, "Paid payout run {$r->number}", ['lines' => $lines->count(), 'amount_minor' => (int) $lines->sum('amount_minor')], 'staff', $by->id);

            return $r->fresh();
        });
    }

    /**
     * What the run would do to each account, and the agency's own money after it (a payout of what is owed leaves that as it was). A short
     * account carries CashGuard's own words.
     *
     * @param  iterable<array{amount_minor: mixed, method: mixed}|RentalPayoutRunLine>  $lines
     * @return array{accounts: list<array{method: string, label: string, paying_minor: int, recorded_minor: int, left_minor: int, short: ?string}>, total_minor: int, agency_own_minor: int, short: bool}
     */
    public static function check(int $companyId, iterable $lines): array
    {
        $paying = [];
        foreach ($lines as $l) {
            $method = (string) $l['method'];
            $paying[$method] = ($paying[$method] ?? 0) + max(0, (int) $l['amount_minor']);
        }
        $cash = RentalLedger::cash($companyId);
        $accounts = [];
        foreach (Payouts::METHODS as $method) {
            if (! isset($paying[$method])) {
                continue;
            }
            $short = null;
            try {
                CashGuard::assertCovers($companyId, $method, $paying[$method]);
            } catch (BusinessRuleException $e) {
                $short = $e->getMessage();
            }
            $accounts[] = ['method' => $method, 'label' => CashGuard::LABELS[$method], 'paying_minor' => $paying[$method], 'recorded_minor' => (int) ($cash[$method] ?? 0),
                'left_minor' => (int) ($cash[$method] ?? 0) - $paying[$method], 'short' => $short];
        }

        return ['accounts' => $accounts, 'total_minor' => array_sum($paying), 'agency_own_minor' => MoneyPosition::asOf($companyId, LocalDate::today($companyId)->toDateString())['agency_own_minor'],
            'short' => array_filter($accounts, fn (array $a) => $a['short'] !== null) !== []];
    }

    /**
     * The run as a report, for the sheet to take to the bank: PDF, Excel or CSV through RentalExport. A cash row has room for a signature.
     *
     * @return array<string, mixed>
     */
    public static function sheet(RentalPayoutRun $run): array
    {
        $cid = (int) $run->company_id;
        $lines = RentalPayoutRunLine::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $run->id)->orderBy('id')->get();
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $lines->pluck('landlord_id')->all() ?: [0])->get()->keyBy('id');
        $payouts = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $lines->pluck('payout_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $rows = [];
        foreach ($lines->sortBy(fn ($l) => mb_strtolower((string) ($landlords[$l->landlord_id]->name ?? '')))->values() as $l) {
            $who = $landlords[$l->landlord_id] ?? null;
            $payout = $l->payout_id !== null ? $payouts->get($l->payout_id) : null;
            $rows[] = ['landlord' => (string) ($who->name ?? ''), 'method' => CashGuard::LABELS[$l->method] ?? (string) $l->method,
                'details' => (string) ($payout->paid_to_snapshot ?? $who->payout_details ?? ''), 'amount_minor' => (int) $l->amount_minor,
                'reference' => (string) ($l->reference ?? ''), 'signature' => $l->method === 'cash' ? '______________' : 'Not needed'];
        }
        $company = DB::table('companies')->where('id', $cid)->value('name');
        $checks = self::check($cid, $lines);
        $paid = $run->status === 'posted' && $run->paid_on !== null;

        return ['key' => 'payout_run_sheet', 'title' => 'Payout sheet', 'subtitle' => $run->number.' · '.($paid ? 'Paid on '.$run->paid_on->format('j M Y') : ($run->status === 'draft' ? 'Draft, not paid yet' : 'Cancelled')),
            'columns' => [['key' => 'landlord', 'label' => 'Landlord', 'type' => 'text'], ['key' => 'method', 'label' => 'Paid from', 'type' => 'text'], ['key' => 'details', 'label' => 'Payment details', 'type' => 'text'],
                ['key' => 'amount_minor', 'label' => 'Amount', 'type' => 'money'], ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'], ['key' => 'signature', 'label' => 'Signature', 'type' => 'text']],
            'rows' => $rows, 'totals' => ['amount_minor' => (int) $lines->sum('amount_minor')],
            'sections' => [['title' => 'By account', 'columns' => [['key' => 'label', 'label' => 'Account', 'type' => 'text'], ['key' => 'paying_minor', 'label' => 'Paying', 'type' => 'money']],
                'rows' => array_map(fn ($a) => ['label' => $a['label'], 'paying_minor' => $a['paying_minor']], $checks['accounts']), 'totals' => ['paying_minor' => $checks['total_minor']]]],
            'currency' => (string) RentalsModule::settings($cid)->currency, 'decimals' => Minor::decimals($cid), 'from' => null, 'to' => null,
            'account' => ['id' => $cid, 'name' => (string) $company], 'generated_at' => now()->toIso8601String(), 'paper' => ['portrait' => true]];
    }

    /**
     * Rows that may go on a draft: the account's own active landlords, once each, for what they are owed at most.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{landlord_id: int, amount_minor: int, method: string, balance_at_draft: int}>
     */
    private function checkLines(int $cid, array $lines): array
    {
        $ids = array_map(fn ($l) => (int) ($l['landlord_id'] ?? 0), $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw BusinessRuleException::make('run_duplicate', 'A landlord can be on a run only once.');
        }
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('is_self', false)->whereIn('id', $ids)->get()->keyBy('id');
        $balances = LandlordAccounts::balances($cid);
        $rows = [];
        $out = [];
        foreach ($lines as $l) {
            $id = (int) ($l['landlord_id'] ?? 0);
            $who = $landlords->get($id) ?? throw BusinessRuleException::make('not_found', 'That landlord was not found.');
            $amount = $l['amount_minor'] ?? null;
            $method = (string) ($l['method'] ?? '');
            $balance = (int) ($balances[$id] ?? 0);
            if ($who->status !== 'active') {
                $rows[$id] = "{$who->name} is no longer with you.";
            } elseif (! is_int($amount) || $amount <= 0) {
                $rows[$id] = "Type an amount for {$who->name}.";
            } elseif ($amount > $balance) {
                $rows[$id] = "{$who->name} is owed ".Minor::format(max(0, $balance), $cid).'. Paying more is a single, deliberate act: use Pay out on their page.';
            } elseif (! in_array($method, Payouts::METHODS, true)) {
                $rows[$id] = "Choose the account {$who->name} is paid from.";
            } else {
                $out[] = ['landlord_id' => $id, 'amount_minor' => $amount, 'method' => $method, 'balance_at_draft' => $balance];
            }
        }
        if ($rows !== []) {
            throw BusinessRuleException::make('run_lines_invalid', implode(' ', $rows), ['rows' => $rows]);
        }

        return $out;
    }

    /**
     * Under the lock: every balance as it was when the draft was saved, every landlord still active, every account covering its rows.
     *
     * @param  Collection<int, RentalPayoutRunLine>  $lines
     * @param  Collection<int, RentalLandlord>  $landlords
     */
    private function assertUnchanged(int $cid, Collection $lines, Collection $landlords): void
    {
        $balances = LandlordAccounts::balances($cid);
        $rows = [];
        foreach ($lines as $line) {
            $id = (int) $line->landlord_id;
            $who = $landlords->get($id);
            $now = (int) ($balances[$id] ?? 0);
            if ($who === null || $who->status !== 'active') {
                $rows[$id] = ($who->name ?? 'A landlord').' is no longer with you.';
            } elseif ($now !== (int) $line->balance_at_draft) {
                $rows[$id] = "{$who->name} was owed ".Minor::format((int) $line->balance_at_draft, $cid).' when this was saved, and is owed '.Minor::format($now, $cid).' now.';
            }
        }
        $check = self::check($cid, $lines);
        foreach ($check['accounts'] as $a) {
            if ($a['short'] === null) {
                continue;
            }
            foreach ($lines->where('method', $a['method']) as $line) {
                $id = (int) $line->landlord_id;
                $rows[$id] = trim(($rows[$id] ?? '').' '.($landlords->get($id)->name ?? 'A landlord').' is paid from '.$a['label'].': '.$a['short']);
            }
        }
        if ($rows !== []) {
            throw BusinessRuleException::make('run_changed', 'Nothing was paid. '.implode(' ', $rows), ['rows' => $rows]);
        }
    }

    private function lockedRun(int $cid, int $id): RentalPayoutRun
    {
        return RentalPayoutRun::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That payout run was not found.');
    }

    private static function assertOwn(RentalPayoutRun $run, int $cid): void
    {
        if ((int) $run->company_id !== $cid) {
            throw BusinessRuleException::make('not_found', 'That payout run was not found.');
        }
    }

    /** Who may run this: agency staff with rentals_landlords, tied to no one property. @return int the account */
    private static function guard(User $by): int
    {
        RentalAccess::authorize($by, 'rentals_landlords');
        $cid = (int) $by->company_id;
        if (RentalAccess::propertyIds($by) !== null) {
            throw BusinessRuleException::make('forbidden', 'Paying landlords covers the whole account\'s money. Ask your property manager.');
        }
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('agency_only', 'This is for agencies that pay landlords.');
        }

        return $cid;
    }
}
