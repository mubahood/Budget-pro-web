<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalClose;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalSetting;
use App\Models\RentalStatement;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalDocument;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The statements a landlord can hold on to (LC-03): written once when a month closes, numbered per account, and read from here for that month
 * ever after, by the landlord's page, the portal, the signed link and the bulk PDF alike. `body` is the truth; a correction never edits it. Closing
 * again leaves alone a landlord whose figures did not change, and gives the others a new version that says which it replaces and what differs.
 *
 * A month not closed reads live from the journal (compose) and is stamped. Nothing here posts or moves money.
 */
final class StatementBook
{
    public const STAMP_OPEN = 'Not closed yet: figures may still change.';

    public const STAMP_UNOFFICIAL = 'Unofficial period: not a closed statement.';

    public const STAMP_NOT_ISSUED = 'No statement was issued for this month when it closed: figures as recorded.';

    /** The figures of a statement whose change makes a new version. */
    private const DIFFERENCE = ['opening_minor' => 'Balance at the start', 'collected_minor' => 'Rent and fees collected', 'commission_minor' => 'Commission',
        'deposits_minor' => 'Deposits passed to the landlord', 'expenses_minor' => 'Expenses', 'payouts_minor' => 'Paid to the landlord', 'other_minor' => 'Other', 'direct_minor' => 'Tenants paid you directly', 'closing_minor' => 'Balance at the end'];

    /**
     * A landlord's statement for a month as it stands: `issued`, with its number and day, once the month is closed and a copy exists; else `open`.
     * The latest copy is never `replaced`: a replaced copy has a newer one, and that is what this answers with. A reopened month answers `open`,
     * because its figures may change again until it closes.
     *
     * @param  string  $month  Y-m
     * @return array{status: 'open'|'issued'|'replaced', number: ?string, issued_on: ?string}
     */
    public static function statusFor(RentalLandlord $landlord, string $month): array
    {
        $s = self::current($landlord, $month);

        return $s === null ? ['status' => 'open', 'number' => null, 'issued_on' => null]
            : ['status' => 'issued', 'number' => (string) $s->number, 'issued_on' => self::issuedDay($s)];
    }

    /** The copy that stands for a closed month, or null (the month is open, or nothing was issued for this landlord). */
    public static function current(RentalLandlord $landlord, string $month): ?RentalStatement
    {
        $cid = (int) $landlord->company_id;
        if (! MonthClose::isClosedMonth($cid, $month)) {
            return null;
        }

        return RentalStatement::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)->where('month', $month.'-01')->where('status', 'issued')->first();
    }

    /**
     * Every copy that stands for a closed month, by landlord (the month-end screen's send list).
     *
     * @return array<int, RentalStatement>
     */
    public static function forMonth(int $companyId, string $month): array
    {
        return RentalStatement::withoutGlobalScopes()->where('company_id', $companyId)->where('month', $month.'-01')->where('status', 'issued')
            ->orderBy('number')->get()->keyBy(fn (RentalStatement $s) => (int) $s->landlord_id)->all();
    }

    /**
     * A landlord's closed months with a copy, for a picker to say "Issued 3 Nov, ST-…" beside them, in one read.
     *
     * @return array<string, array{number: string, issued_on: ?string}> Y-m => what stands for it
     */
    public static function issuedMonths(RentalLandlord $landlord): array
    {
        $cid = (int) $landlord->company_id;
        $through = MonthClose::closedThrough($cid);
        if ($through === null) {
            return [];
        }
        $out = [];
        foreach (RentalStatement::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)->where('status', 'issued')->where('month', '<=', $through)->get(['id', 'company_id', 'month', 'number', 'issued_at']) as $s) {
            $out[Carbon::parse($s->month)->format('Y-m')] = ['number' => (string) $s->number, 'issued_on' => self::issuedDay($s)];
        }

        return $out;
    }

    /** One of a landlord's copies by id (a link names the copy it was made for), or null. */
    public static function byId(RentalLandlord $landlord, int $id): ?RentalStatement
    {
        return RentalStatement::withoutGlobalScopes()->where('company_id', $landlord->company_id)->where('landlord_id', $landlord->id)->find($id);
    }

    /**
     * What a landlord reads for a period: the issued copy of a closed month, else the journal as it stands now, with the stamp that says which.
     * `issued` carries the copy's number, day and proof, and what it replaces or what replaced it.
     *
     * @return array<string, mixed>
     */
    public static function read(RentalLandlord $landlord, string $from, string $to, ?int $statementId = null): array
    {
        $cid = (int) $landlord->company_id;
        $copy = null;
        if ($statementId !== null) {
            $copy = self::byId($landlord, $statementId) ?? throw BusinessRuleException::make('not_found', 'That statement was not found.');
        } elseif (($month = self::wholeMonth($from, $to)) !== null) {
            $copy = self::current($landlord, $month);
        }
        if ($copy !== null) {
            return (array) $copy->body + ['stamp' => self::stampOf($copy), 'issued' => self::issuedFacts($copy)];
        }

        return self::compose($landlord, $from, $to) + ['stamp' => self::stampFor($cid, $from, $to), 'issued' => null];
    }

    /** "2026-10" when the period is exactly that calendar month, else null. */
    public static function wholeMonth(string $from, string $to): ?string
    {
        return substr($from, 8) === '01' && $to === Carbon::parse($from)->endOfMonth()->toDateString() ? substr($from, 0, 7) : null;
    }

    /**
     * Today's reading of a period, everything a statement says: the journal's lines (LandlordAccounts::statement) and the sections around them
     * (LC-08). What is issued is this with the copy's facts added.
     *
     * @return array<string, mixed>
     */
    public static function compose(RentalLandlord $landlord, string $from, string $to): array
    {
        $cid = (int) $landlord->company_id;
        $owned = Handover::propertyIdsOn($cid, (int) $landlord->id, $to); // the occupancy and the unit table count the same properties
        $st = LandlordAccounts::statement($landlord, $from, $to, $owned);
        $sameMonth = substr($from, 0, 7) === substr($to, 0, 7);

        return $st + [
            'owner' => RentalsModule::isOwnerMode($cid),
            'commission_rule' => self::commissionRule($landlord, $from, $to),
            'units' => $sameMonth ? UnitSheet::rows($landlord, $to, $owned) : [], // its billed and paid are one month's
            'expenses' => self::expenses($cid, (int) $landlord->id, $from, $to),
            'payouts' => self::payouts($cid, (int) $landlord->id, $from, $to),
            'held' => self::held($cid, (int) $landlord->id, $to),
            'corrections' => self::corrections($cid, (int) $landlord->id, $from, $to),
        ];
    }

    /**
     * Write the month's statements (MonthClose, under its lock, in its transaction). A landlord with a copy whose figures still agree keeps it. Numbers
     * come from one reservation, so 200 landlords are not 200 writes of the settings row.
     *
     * @param  list<int>  $landlordIds  those with any balance, line or unit this month
     * @return array{issued: int, kept: int, replaced: int}
     */
    public static function issueMonth(RentalClose $close, User $by, string $month, array $landlordIds): array
    {
        $cid = (int) $close->company_id;
        if (! RentalLedger::isLocked($cid)) {
            throw new \LogicException('Statements are issued only inside the close.');
        }
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $standing = self::forMonth($cid, $month);
        $ids = array_values(array_unique(array_merge($landlordIds, array_keys($standing))));
        $settings = RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->firstOrFail();
        $seq = (int) $settings->getAttribute('seq_statement');
        $digits = (int) RentalsModule::NUMBERS['statement'][1];
        $letterhead = self::letterhead($cid);
        $issuedOn = LocalDate::today($cid)->toDateString();
        $counts = ['issued' => 0, 'kept' => 0, 'replaced' => 0];
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach (RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $chunk)->orderBy('id')->get() as $landlord) {
                $id = (int) $landlord->id;
                $body = self::compose($landlord, $from, $to);
                $prev = $standing[$id] ?? null;
                if ($prev !== null && self::figures((array) $prev->body) === self::figures($body)) {
                    $counts['kept']++;

                    continue;
                }
                $number = 'ST-'.$month.'-'.str_pad((string) ++$seq, $digits, '0', STR_PAD_LEFT);
                $version = $prev !== null ? (int) $prev->version + 1 : 1;
                $body += ['number' => $number, 'month' => $month, 'version' => $version, 'issued_on' => $issuedOn, 'issued_by' => (string) $by->name, 'letterhead' => $letterhead,
                    'decimals' => Minor::decimals($cid), 'landlord_code' => (string) ($landlord->code ?? ''),
                    'replaces' => $prev !== null ? ['number' => (string) $prev->number, 'difference' => self::difference((array) $prev->body, $body)] : null];
                $t = (array) $body['totals'];
                $new = RentalStatement::create(['company_id' => $cid, 'landlord_id' => $id, 'number' => $number, 'month' => $from, 'version' => $version, 'replaces_id' => $prev?->id,
                    'close_id' => $close->id, 'status' => 'issued', 'opening_minor' => (int) $body['opening_minor'], 'collected_minor' => (int) $t['collected_minor'],
                    'commission_minor' => (int) $t['commission_minor'], 'expenses_minor' => (int) $t['expenses_minor'], 'payouts_minor' => (int) $t['payouts_minor'],
                    'deposits_minor' => (int) $t['deposits_minor'], 'other_minor' => (int) $t['other_minor'], 'closing_minor' => (int) $body['closing_minor'],
                    'body' => $body, 'proof' => StatementProof::of($body), 'issued_at' => now(), 'issued_by_id' => $by->id]);
                if ($prev !== null) {
                    $prev->forceFill(['status' => 'replaced', 'replaced_by_id' => $new->id])->save();
                    $counts['replaced']++;
                }
                $counts['issued']++;
            }
        }
        if ($seq !== (int) $settings->getAttribute('seq_statement')) {
            $settings->forceFill(['seq_statement' => $seq])->save();
        }

        return $counts;
    }

    /**
     * Someone on the staff opened a statement's link on their own phone, or copied it (EL-23): written down as prepared, never as delivered. The first
     * person to do it stays until someone does it again.
     */
    public static function markPrepared(RentalStatement $statement, string $via, int $userId): void
    {
        $statement->forceFill(['prepared_at' => now(), 'prepared_via' => mb_substr($via, 0, 10), 'prepared_by_id' => $userId])->save();
    }

    /** A landlord opened it: a signed link with no staff session, or their own sign-in. */
    public static function markOpened(RentalStatement $statement): void
    {
        DB::table('rental_statements')->where('id', $statement->id)->update([
            'first_opened_at' => $statement->first_opened_at ?? now(), 'open_count' => DB::raw('open_count + 1'), 'updated_at' => now()]);
    }

    /**
     * How a copy's being sent reads: Not sent, Link prepared by a person on a day, Opened by the landlord on a day. Never "delivered": nothing here
     * can know (EL-09, EL-23).
     *
     * @param  array<int, string>  $staff  user id => name
     */
    public static function sentWords(RentalStatement $s, array $staff = []): string
    {
        if ($s->first_opened_at !== null) {
            return 'Opened by landlord, '.$s->first_opened_at->copy()->setTimezone(LocalDate::timezone((int) $s->company_id))->format('j M');
        }
        if ($s->prepared_at !== null) {
            $who = $staff[(int) $s->prepared_by_id] ?? null;

            return 'Link prepared'.($who !== null ? ' by '.$who : '').', '.$s->prepared_at->copy()->setTimezone(LocalDate::timezone((int) $s->company_id))->format('j M');
        }

        return 'Not sent';
    }

    /** What a copy's own stamp says today: replaced, its month reopened, or nothing. */
    public static function stampOf(RentalStatement $s): ?string
    {
        if ($s->status === 'replaced') {
            $by = RentalStatement::withoutGlobalScopes()->where('company_id', $s->company_id)->whereKey($s->replaced_by_id)->value('number');

            return 'Replaced by '.$by.'.';
        }
        if (! MonthClose::isClosedMonth((int) $s->company_id, Carbon::parse($s->month)->format('Y-m'))) {
            return 'This month was reopened: a corrected statement may follow.';
        }

        return null;
    }

    /** @return array{id: int, number: string, version: int, status: string, issued_on: ?string, issued_at_words: string, proof: string, proof_short: string, replaces: mixed} */
    private static function issuedFacts(RentalStatement $s): array
    {
        return ['id' => (int) $s->id, 'number' => (string) $s->number, 'version' => (int) $s->version, 'status' => (string) $s->status, 'issued_on' => self::issuedDay($s),
            'issued_at_words' => $s->issued_at?->copy()->setTimezone(LocalDate::timezone((int) $s->company_id))->format('j M Y') ?? '',
            'proof' => (string) $s->proof, 'proof_short' => StatementProof::short((string) $s->proof), 'replaces' => ((array) $s->body)['replaces'] ?? null];
    }

    private static function issuedDay(RentalStatement $s): ?string
    {
        return $s->issued_at?->copy()->setTimezone(LocalDate::timezone((int) $s->company_id))->toDateString();
    }

    private static function stampFor(int $cid, string $from, string $to): string
    {
        $today = LocalDate::today($cid)->toDateString();
        $first = substr($from, 8) === '01';
        if ($first && ($month = self::wholeMonth($from, $to)) !== null) {
            return MonthClose::isClosedMonth($cid, $month) ? self::STAMP_NOT_ISSUED : self::STAMP_OPEN;
        }
        if ($first && substr($from, 0, 7) === substr($today, 0, 7) && substr($to, 0, 7) === substr($today, 0, 7)) {
            return self::STAMP_OPEN; // this month so far
        }

        return self::STAMP_UNOFFICIAL;
    }

    /**
     * The figures that decide a new version: the money, line by line, and what tenants owed. A name corrected, or a unit switched off since, is no new
     * statement; those read as issued.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function figures(array $body): array
    {
        $t = (array) ($body['totals'] ?? []);
        $totals = []; // in one order: a stored body comes back with its keys as the database keeps them
        foreach (array_keys(self::DIFFERENCE) as $k) {
            $totals[$k] = (int) ($t[$k] ?? 0);
        }

        return ['opening' => (int) $body['opening_minor'], 'closing' => (int) $body['closing_minor'], 'totals' => $totals,
            'lines' => array_map(fn ($l) => [(string) $l['date'], (string) $l['type'], (int) $l['gross_minor'], (int) $l['commission_minor'], (int) $l['amount_minor']], (array) ($body['lines'] ?? [])),
            'owed' => array_sum(array_map(fn ($a) => (int) $a['owed_minor'], (array) ($body['arrears'] ?? [])))];
    }

    /**
     * What differs between a copy and the one that replaces it: every figure the statement adds up, before and after.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{what: string, before_minor: int, after_minor: int}>
     */
    private static function difference(array $old, array $new): array
    {
        $at = fn (array $b, string $k) => (int) (in_array($k, ['opening_minor', 'closing_minor'], true) ? $b[$k] : ($b['totals'][$k] ?? 0));
        $out = [];
        foreach (self::DIFFERENCE as $k => $what) {
            if ($at($old, $k) !== $at($new, $k)) {
                $out[] = ['what' => $what, 'before_minor' => $at($old, $k), 'after_minor' => $at($new, $k)];
            }
        }

        return $out;
    }

    /** The account as it stands on the paper, so a later change of name or address leaves an issued statement as it was. @return array{name: string, lines: list<string>, logo_path: ?string} */
    private static function letterhead(int $cid): array
    {
        $head = RentalDocument::letterhead($cid);

        return ['name' => (string) $head['name'], 'lines' => array_values((array) $head['lines']), 'logo_path' => Company::withoutGlobalScopes()->whereKey($cid)->value('logo')];
    }

    /** "Commission: 10% of rent collected", or by unit when the leases differ; null when the landlord has no leases in the period. */
    private static function commissionRule(RentalLandlord $landlord, string $from, string $to): ?string
    {
        $cid = (int) $landlord->company_id;
        $rules = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)->where('status', '!=', 'cancelled')
            ->where('start_date', '<=', $to)->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $from))
            ->get(['commission_type', 'commission_bp', 'commission_minor'])->map(fn ($l) => (string) $l->commission_type.':'.(int) $l->commission_bp.':'.(int) $l->commission_minor)->unique()->values();
        if ($rules->isEmpty()) {
            return null;
        }
        if ($rules->count() > 1) {
            return 'Commission: by unit, see below';
        }
        [$type, $bp, $flat] = explode(':', (string) $rules[0]);

        return match ($type) {
            'percent' => 'Commission: '.rtrim(rtrim(Minor::toMajor((int) $bp, 2), '0'), '.').'% of rent collected',
            'flat' => 'Commission: '.Minor::format((int) $flat, $cid).' a month for each unit',
            default => 'No commission on this rent',
        };
    }

    /**
     * Expenses charged to the landlord in the period and not taken back in it: date, what, who was paid, amount, and whether a receipt is held.
     *
     * @return list<array{date: string, number: string, what: string, vendor: ?string, property: ?string, amount_minor: int, receipt: string}>
     */
    private static function expenses(int $cid, int $landlordId, string $from, string $to): array
    {
        $rows = DB::table('rental_entries as e')->join('rental_expenses as x', 'x.id', '=', 'e.expense_id')->leftJoin('rental_properties as p', 'p.id', '=', 'x.property_id')
            ->where('e.company_id', $cid)->where('e.landlord_id', $landlordId)->where('e.type', 'expense_landlord')->whereBetween('e.occurred_on', [$from, $to])
            ->orderBy('e.occurred_on')->orderBy('e.id')->get(['e.id', 'e.occurred_on', 'e.reversed_at', 'e.landlord_minor', 'x.number', 'x.category', 'x.description', 'x.vendor', 'x.receipt_uuid', 'p.name as property']);
        $reversed = $rows->whereNotNull('reversed_at')->isEmpty() ? [] : self::reversedBy($cid, $landlordId, $to);
        $out = [];
        foreach ($rows as $r) {
            if (! isset($reversed[(int) $r->id])) {
                $out[] = ['date' => (string) $r->occurred_on, 'number' => (string) $r->number, 'what' => trim((string) $r->category.($r->description ? ': '.$r->description : '')), 'vendor' => $r->vendor,
                    'property' => $r->property, 'amount_minor' => -(int) $r->landlord_minor, 'receipt' => $r->receipt_uuid ? 'Yes' : 'No'];
            }
        }

        return $out;
    }

    /**
     * Paid to the landlord in the period and not taken back in it: number, date, how, reference.
     *
     * @return list<array{date: string, number: string, how: string, reference: ?string, amount_minor: int, advance_minor: int}>
     */
    private static function payouts(int $cid, int $landlordId, string $from, string $to): array
    {
        $methods = (array) config('rentals.methods');
        $rows = DB::table('rental_entries as e')->join('rental_payouts as p', 'p.id', '=', 'e.payout_id')
            ->where('e.company_id', $cid)->where('e.landlord_id', $landlordId)->where('e.type', 'payout')->whereBetween('e.occurred_on', [$from, $to])
            ->orderBy('e.occurred_on')->orderBy('e.id')->selectRaw('p.*, e.id AS entry_id, e.occurred_on, e.reversed_at AS entry_reversed_at, e.landlord_minor, e.method AS how')->get();
        $reversed = $rows->whereNotNull('entry_reversed_at')->isEmpty() ? [] : self::reversedBy($cid, $landlordId, $to);
        $out = [];
        foreach ($rows as $r) {
            if (! isset($reversed[(int) $r->entry_id])) {
                $out[] = ['date' => (string) $r->occurred_on, 'number' => (string) $r->number, 'how' => (string) ($methods[$r->how] ?? $r->how), 'reference' => $r->reference,
                    'amount_minor' => -(int) $r->landlord_minor, 'advance_minor' => (int) ($r->advance_minor ?? 0)];
            }
        }

        return $out;
    }

    /** Journal rows of this landlord reversed on or before $to: entry id => true. @return array<int, true> */
    private static function reversedBy(int $cid, int $landlordId, string $to): array
    {
        $ids = DB::table('rental_entries')->where('company_id', $cid)->where('landlord_id', $landlordId)->where('type', 'reversal')->where('occurred_on', '<=', $to)->pluck('reverses_id');

        return array_fill_keys(array_map('intval', array_filter($ids->all())), true);
    }

    /**
     * Money the agency holds for this landlord's tenants on the day, not in the landlord's balance: deposits and rent paid ahead, per lease, from the
     * journal. It stays with the lease, so it follows the lease's landlord.
     *
     * @return array{rows: list<array{unit: ?string, property: ?string, tenant: ?string, deposit_minor: int, credit_minor: int}>, deposit_minor: int, credit_minor: int}
     */
    private static function held(int $cid, int $landlordId, string $to): array
    {
        $rows = [];
        foreach (DB::table('rental_entries as e')->join('rental_leases as l', 'l.id', '=', 'e.lease_id')->leftJoin('rental_units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('rental_properties as p', 'p.id', '=', 'l.property_id')->leftJoin('rental_tenants as t', 't.id', '=', 'l.tenant_id')
            ->where('e.company_id', $cid)->where('l.landlord_id', $landlordId)->where('e.occurred_on', '<=', $to)
            ->groupBy('e.lease_id', 'u.code', 'p.name', 't.name')->selectRaw('u.code AS unit, p.name AS property, t.name AS tenant, SUM(e.deposit_minor) AS deposit, SUM(e.credit_minor) AS credit')
            ->havingRaw('SUM(e.deposit_minor) <> 0 OR SUM(e.credit_minor) <> 0')->orderBy('p.name')->orderByRaw('LENGTH(u.code), u.code')->get() as $r) {
            $rows[] = ['unit' => $r->unit, 'property' => $r->property, 'tenant' => $r->tenant, 'deposit_minor' => (int) $r->deposit, 'credit_minor' => (int) $r->credit];
        }

        return ['rows' => $rows, 'deposit_minor' => array_sum(array_column($rows, 'deposit_minor')), 'credit_minor' => array_sum(array_column($rows, 'credit_minor'))];
    }

    /**
     * Reversals in the period of money from before it: the reason, the day of what it corrects, and the amount.
     *
     * @return list<array{date: string, what: string, corrects: string, amount_minor: int}>
     */
    private static function corrections(int $cid, int $landlordId, string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('rental_entries as r')->join('rental_entries as o', 'o.id', '=', 'r.reverses_id')
            ->where('r.company_id', $cid)->where('r.landlord_id', $landlordId)->where('r.type', 'reversal')->whereBetween('r.occurred_on', [$from, $to])->where('o.occurred_on', '<', $from)->where('r.landlord_minor', '!=', 0)
            ->orderBy('r.occurred_on')->orderBy('r.id')->get(['r.occurred_on', 'r.landlord_minor', 'r.description as why', 'o.occurred_on as corrects', 'o.description as what']) as $r) {
            $why = preg_replace('/^Reversal:\s*/', '', (string) $r->why);
            $out[] = ['date' => (string) $r->occurred_on, 'what' => trim((string) $r->what.($why !== '' ? ' ('.$why.')' : '')), 'corrects' => (string) $r->corrects, 'amount_minor' => (int) $r->landlord_minor];
        }

        return $out;
    }
}
