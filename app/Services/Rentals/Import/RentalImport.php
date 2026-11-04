<?php

namespace App\Services\Rentals\Import;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Phone;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/**
 * Moving in from another system (KR-22, docs/estate-ledger/04-architecture.md §5.10): landlords, properties, units, the
 * tenancies running today with what each tenant owes, has paid ahead and left as a deposit, and the cash in hand, from
 * one CSV file per sheet. A dry run checks every row and writes nothing; the import checks again and then writes
 * everything through the services in one transaction under the account's lock: all of it, or nothing.
 *
 * The sheets and their columns (the first row of each file; `templateCsv()` gives an empty one to fill):
 *
 *  landlords     name, phone, email, address, payout_method (bank | mobile_money | cash), payout_details,
 *                commission_percent (blank: the account's), opening_balance (what you owe the landlord on the as-of day;
 *                negative when they owe you. Deposits a landlord holds are not part of it), commission_flat (instead of a
 *                percent: an amount per unit per month of rent)
 *  properties    name, landlord_name (a landlord above or already in Estate Ledger; left out in owner mode), kind
 *                (residential | commercial | mixed | land), area, district, address, commission_percent,
 *                deposit_held_by (agency | landlord; blank: the account's), water_rate, power_rate (per meter unit),
 *                commission_flat
 *  units         property_name, code (unique in the property), kind (single_room, double_room, self_contained,
 *                studio, apartment, house, shop, office, stall, warehouse, other), floor, rent (a month), deposit,
 *                cycle_months (1, 3, 6 or 12; monthly, quarterly, half-yearly, yearly), water_meter, power_meter
 *  tenancies     property_name, unit_code, tenant_name, phone, email, id_number, start_date (when they moved in),
 *                rent (blank: the unit's), cycle_months (blank: the unit's), deposit_held (the deposit held today),
 *                owed_now (what they owe on the as-of day), credit_now (what they have paid ahead), items (fees beside
 *                rent: "Garbage:10000;Security:20000", add ":agency" when the agency keeps a fee), end_date (the last
 *                day of a fixed term; blank: month to month)
 *  opening_cash  method (cash | bank | mobile_money | cheque), amount (in hand on the as-of day)
 *
 * Amounts are typed in the currency's main unit ("1,250,000"); dates as d/m/Y or Y-m-d. Everything up to the period
 * running on the as-of day was billed by the other system: Estate Ledger bills from the next period, and brings in what was
 * owed, paid ahead and held as opening figures (Leases::moveIn's `import`).
 */
final class RentalImport
{
    /** The sheets in the order they are read, with their columns. */
    public const SHEETS = [
        'landlords' => ['name', 'phone', 'email', 'address', 'payout_method', 'payout_details', 'commission_percent', 'opening_balance', 'commission_flat'],
        'properties' => ['name', 'landlord_name', 'kind', 'area', 'district', 'address', 'commission_percent', 'deposit_held_by', 'water_rate', 'power_rate',
            'commission_flat'],
        'units' => ['property_name', 'code', 'kind', 'floor', 'rent', 'deposit', 'cycle_months', 'water_meter', 'power_meter'],
        'tenancies' => ['property_name', 'unit_code', 'tenant_name', 'phone', 'email', 'id_number', 'start_date', 'rent', 'cycle_months', 'deposit_held',
            'owed_now', 'credit_now', 'items', 'end_date'],
        'opening_cash' => ['method', 'amount'],
    ];

    /** The columns a sheet cannot do without. */
    public const REQUIRED = ['landlords' => ['name'], 'properties' => ['name'], 'units' => ['property_name', 'code', 'rent'],
        'tenancies' => ['property_name', 'unit_code', 'tenant_name', 'start_date'], 'opening_cash' => ['method', 'amount']];

    private const PAYOUT = ['bank' => 'bank', 'mobile_money' => 'mobile_money', 'mobile' => 'mobile_money', 'momo' => 'mobile_money', 'mm' => 'mobile_money', 'cash' => 'cash'];

    private const CASH = ['cash' => 'cash', 'bank' => 'bank', 'mobile_money' => 'mobile_money', 'mobile' => 'mobile_money', 'momo' => 'mobile_money', 'mm' => 'mobile_money', 'cheque' => 'cheque', 'cheques' => 'cheque'];

    private const CYCLES = ['1' => 1, '3' => 3, '6' => 6, '12' => 12, 'monthly' => 1, 'month' => 1, 'quarterly' => 3, 'quarter' => 3, 'every_3_months' => 3,
        'half_yearly' => 6, 'half-yearly' => 6, 'every_6_months' => 6, 'yearly' => 12, 'annually' => 12, 'annual' => 12, 'year' => 12];

    private const UNIT_WORDS = ['single' => 'single_room', 'room' => 'single_room', 'double' => 'double_room', 'self-contained' => 'self_contained',
        'flat' => 'apartment', 'apartments' => 'apartment', 'bedsitter' => 'studio', 'shop' => 'shop', 'store' => 'warehouse', 'kiosk' => 'stall'];

    /** The columns an owner-run account has no use for: its property has no landlord and no commission (KR-06). */
    private const NOT_FOR_OWNERS = ['landlord_name', 'commission_percent', 'commission_flat', 'deposit_held_by'];

    /** @return array<string, list<string>> sheet => its columns; an owner-run account has no landlords sheet */
    public static function templates(bool $owner = false): array
    {
        if (! $owner) {
            return self::SHEETS;
        }
        $sheets = array_diff_key(self::SHEETS, ['landlords' => 1]);

        return array_map(fn (array $cols) => array_values(array_diff($cols, self::NOT_FOR_OWNERS)), $sheets);
    }

    /** An empty template for one sheet: its header row, as a CSV file (with a BOM, so a spreadsheet reads it right). */
    public static function templateCsv(string $sheet, bool $owner = false): string
    {
        $cols = self::templates($owner)[$sheet] ?? throw BusinessRuleException::make('unknown_sheet', 'Choose one of: '.implode(', ', array_keys(self::SHEETS)).'.');
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $cols);
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$csv;
    }

    /**
     * A file as UTF-8, whatever a spreadsheet saved it as: UTF-8 with or without a BOM, UTF-16 ("Unicode text"), or
     * Windows-1252 ("CSV" on most Windows machines), so "Müller" reaches the database whole.
     */
    public static function utf8(string $csv): string
    {
        if (str_starts_with($csv, "\xFF\xFE") || str_starts_with($csv, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($csv, 2), 'UTF-8', str_starts_with($csv, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        return mb_check_encoding($csv, 'UTF-8') ? $csv : (string) mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
    }

    /**
     * A CSV file's rows, keyed by its header (lower case; spaces become _). Blank lines are skipped; each row keeps its
     * line number in the file as `_row` (the header is row 1).
     *
     * @return list<array<string, string>>
     */
    public static function parse(string $csv): array
    {
        $csv = self::utf8($csv);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = null;
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($h)) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn ($c) => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $c))), $cells);

                continue;
            }
            $row = ['_row' => (string) $line];
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $row[$name] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $rows[] = $row;
        }
        fclose($h);
        if ($header !== null && $rows === []) {
            return [['_row' => '0', '_header' => implode(',', $header)]];
        }

        return array_values(array_filter($rows, fn ($r) => ! isset($r['_header'])));
    }

    /**
     * Check every row and write nothing. $sheets: sheet => CSV text or rows (as parse() gives them).
     *
     * @return array{ok: int, errors: list<array{sheet: string, row: int, message: string}>, summary: array<string, mixed>}
     */
    public function dryRun(int $companyId, User $by, array $sheets, string $asOf, array $options = []): array
    {
        RentalAccess::authorize($by, 'rentals_settings');
        if ((int) $by->company_id !== $companyId) {
            throw BusinessRuleException::make('not_found', 'That account was not found.');
        }
        $plan = $this->plan($companyId, $by, $sheets, $asOf, ! empty($options['allow_existing']));

        return ['ok' => $plan['ok'], 'errors' => $plan['errors'], 'summary' => $plan['summary']];
    }

    /**
     * Check every row, then write it all through the services, all or nothing. $options: allow_existing (bring more
     * tenancies into an account that already has some).
     *
     * @return array{ok: int, errors: list<array<string, mixed>>, summary: array<string, mixed>, created: array<string, int>}
     */
    public function run(int $companyId, User $by, array $sheets, string $asOf, array $options = []): array
    {
        RentalAccess::authorize($by, 'rentals_settings');
        if ((int) $by->company_id !== $companyId) {
            throw BusinessRuleException::make('not_found', 'That account was not found.');
        }
        if (class_exists(RentalsDemo::class)) {
            RentalsDemo::guard($companyId, 'importing data');
        }
        $plan = $this->plan($companyId, $by, $sheets, $asOf, ! empty($options['allow_existing']));
        if ($plan['errors'] !== []) {
            $first = $plan['errors'][0];
            throw BusinessRuleException::make('import_invalid', count($plan['errors']).' row(s) need fixing first. '.self::where($first).': '.$first['message'],
                ['errors' => array_slice($plan['errors'], 0, 500)]);
        }
        $on = Carbon::parse($plan['as_of']);

        $created = RentalLedger::locked($companyId, function () use ($companyId, $by, $plan, $on) {
            $n = ['landlords' => 0, 'properties' => 0, 'units' => 0, 'tenants' => 0, 'tenancies' => 0, 'opening_cash' => 0];
            $landlordIds = $plan['existing']['landlords'];
            $propertyIds = $plan['existing']['properties'];
            $unitIds = $plan['existing']['units'];
            foreach ($plan['landlords'] as $k => $l) {
                $landlordIds[$k] = $this->step('landlords', $l['_row'], fn () => (int) app(Landlords::class)->create($by, $l['data'])->id);
                $n['landlords']++;
            }
            foreach ($plan['properties'] as $k => $p) {
                $data = $p['data'] + ['landlord_id' => $p['landlord'] !== null ? ($landlordIds[$p['landlord']] ?? null) : null];
                $propertyIds[$k] = $this->step('properties', $p['_row'], fn () => (int) app(Properties::class)->create($by, $data)->id);
                $n['properties']++;
            }
            foreach ($plan['units'] as $k => $u) {
                $data = $u['data'] + ['property_id' => $propertyIds[$u['property']]];
                $unitIds[$k] = $this->step('units', $u['_row'], fn () => (int) app(Units::class)->create($by, $data)->id);
                $n['units']++;
            }
            $tenants = $plan['existing']['tenants'];
            foreach ($plan['tenancies'] as $t) {
                $data = $t['data'] + ['unit_id' => $unitIds[$t['unit']]];
                if ($t['person'] !== null && isset($tenants[$t['person']])) {
                    $data['tenant_id'] = $tenants[$t['person']];
                } else {
                    $data['tenant'] = $t['tenant'];
                }
                $lease = $this->step('tenancies', $t['_row'], fn () => app(Leases::class)->moveIn($by, $data));
                if (! isset($data['tenant_id'])) {
                    $n['tenants']++;
                    if ($t['person'] !== null) {
                        $tenants[$t['person']] = (int) $lease->tenant_id;
                    }
                }
                $n['tenancies']++;
            }
            foreach ($plan['landlords'] as $k => $l) {
                // What the agency owes the landlord. Deposits they hold were added to their account with each tenancy
                // (the landlord has that money), so the opening entry is what is left.
                $amount = $l['opening'] - ($plan['landlord_deposits'][$k] ?? 0);
                if ($amount !== 0) {
                    $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($landlordIds[$k]);
                    $this->step('landlords', $l['_row'], fn () => LandlordAccounts::opening($by, $landlord, $amount, $on));
                }
            }
            foreach ($plan['opening_cash'] as $c) {
                $this->step('opening_cash', $c['_row'], fn () => app(CashBook::class)->opening($by, $c['method'], $c['amount'], $on));
                $n['opening_cash']++;
            }
            RentalAudit::record($companyId, 'import.done', null, 'Brought the books in from another system (as of '.$on->format('j M Y').')', $n, 'staff', (int) $by->id);

            return $n;
        });

        return ['ok' => $plan['ok'], 'errors' => [], 'summary' => $plan['summary'], 'created' => $created];
    }

    // ── Checking ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function plan(int $cid, User $by, array $sheets, string $asOfRaw, bool $allowExisting): array
    {
        $errors = [];
        $err = function (string $sheet, int|string $row, string $message) use (&$errors) {
            $errors[] = ['sheet' => $sheet, 'row' => (int) $row, 'message' => $message];
        };
        $owner = RentalsModule::isOwnerMode($cid);
        $settings = RentalsModule::settings($cid);
        $decimals = Minor::decimals($cid);
        $country = (string) (Company::withoutGlobalScopes()->find($cid)?->country ?: 'UG');
        $today = LocalDate::today($cid);
        $asOf = self::date($asOfRaw);
        if ($asOf === null) {
            $err('as_of', 0, 'The as-of date must look like 31/10/2026 or 2026-10-31.');
            $asOf = $today;
        } elseif ($asOf->gt($today)) {
            $err('as_of', 0, 'The as-of date cannot be after today.');
        }
        foreach (array_keys($sheets) as $name) {
            if (! isset(self::SHEETS[$name])) {
                $err((string) $name, 0, 'There is no sheet called "'.$name.'". The sheets are: '.implode(', ', array_keys(self::SHEETS)).'.');
            }
        }
        $read = [];
        foreach (self::SHEETS as $name => $cols) {
            $raw = $sheets[$name] ?? [];
            $rows = is_string($raw) ? self::parse($raw) : array_values((array) $raw);
            $read[$name] = [];
            foreach ($rows as $i => $r) {
                $r = (array) $r;
                if (isset($r['_header'])) {
                    $missing = array_diff(self::REQUIRED[$name], explode(',', (string) $r['_header']));
                    if ($missing !== []) {
                        $err($name, 1, 'The first row must name the columns; missing: '.implode(', ', $missing).'.');
                    }

                    continue;
                }
                $norm = ['_row' => (int) ($r['_row'] ?? $i + 2)];
                foreach ($r as $k => $v) {
                    if ($k !== '_row') {
                        $norm[str_replace([' ', '-'], '_', mb_strtolower(trim((string) $k)))] = trim((string) $v);
                    }
                }
                $read[$name][] = $norm;
            }
            if ($read[$name] !== []) {
                $missing = array_diff(self::REQUIRED[$name], array_keys($read[$name][0]));
                if ($missing !== []) {
                    $err($name, 1, 'The first row must name the columns; missing: '.implode(', ', $missing).'.');
                    $read[$name] = [];
                }
            }
        }
        $badRows = [];
        $bad = function (string $sheet, int $row, string $message) use ($err, &$badRows) {
            $err($sheet, $row, $message);
            $badRows[$sheet.':'.$row] = true;
        };
        $key = fn (?string $s) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));

        // What the account already has (names to point at, units already let).
        $existing = ['landlords' => [], 'properties' => [], 'units' => [], 'tenants' => []];
        foreach (RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->get(['id', 'name', 'is_self']) as $l) {
            if (! $l->is_self) {
                $existing['landlords'][$key($l->name)] = (int) $l->id;
            }
        }
        $existingProps = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->get(['id', 'name', 'deposit_held_by', 'status']);
        foreach ($existingProps as $p) {
            $existing['properties'][$key($p->name)] = (int) $p->id;
        }
        $live = array_flip(array_map('intval', RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE)->pluck('unit_id')->all()));
        $existingUnits = [];
        foreach (RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->get(['id', 'property_id', 'code', 'rent_minor', 'cycle_months', 'status']) as $u) {
            $pk = array_search((int) $u->property_id, $existing['properties'], true);
            if ($pk !== false) {
                $existing['units'][$pk.'|'.$key($u->code)] = (int) $u->id;
                $existingUnits[$pk.'|'.$key($u->code)] = $u;
            }
        }
        foreach (RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('phone_e164')->get(['id', 'phone_e164']) as $t) {
            $existing['tenants'][(string) $t->phone_e164] = (int) $t->id;
        }
        if (! $allowExisting && RentalLease::withoutGlobalScopes()->where('company_id', $cid)->exists() && $read['tenancies'] !== []) {
            $err('tenancies', 0, 'This account already has tenancies. Import into a new account, or say that adding to the ones there is meant (allow existing).');
        }

        // Landlords.
        $landlords = [];
        if ($owner && $read['landlords'] !== []) {
            $err('landlords', 0, 'This account manages its own property, so it has no landlords: leave the landlords sheet out.');
            $read['landlords'] = [];
        }
        foreach ($read['landlords'] as $r) {
            $row = $r['_row'];
            $name = trim($r['name'] ?? '');
            $k = $key($name);
            if ($name === '') {
                $bad('landlords', $row, 'Give the landlord a name.');

                continue;
            }
            if (mb_strlen($name) > 150) {
                $bad('landlords', $row, 'The name is too long (at most 150 characters).');

                continue;
            }
            if (isset($landlords[$k]) || isset($existing['landlords'][$k])) {
                $bad('landlords', $row, "{$name} is in the list twice (or is already in Estate Ledger).");

                continue;
            }
            $method = self::PAYOUT[str_replace(' ', '_', mb_strtolower($r['payout_method'] ?? ''))] ?? (($r['payout_method'] ?? '') === '' ? 'bank' : null);
            if ($method === null) {
                $bad('landlords', $row, 'payout_method must be bank, mobile_money or cash.');
            }
            $bp = $this->percent($r['commission_percent'] ?? '', fn ($m) => $bad('landlords', $row, $m));
            $flat = $this->amount($r['commission_flat'] ?? '', $decimals, false, fn ($m) => $bad('landlords', $row, "commission_flat: {$m}"));
            if ($bp !== null && $flat !== null) {
                $bad('landlords', $row, 'Give either commission_percent or commission_flat, not both.');
            }
            $opening = $this->amount($r['opening_balance'] ?? '', $decimals, true, fn ($m) => $bad('landlords', $row, "opening_balance: {$m}"));
            if (($r['email'] ?? '') !== '' && ! filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $bad('landlords', $row, 'The email address is not valid.');
            }
            if (($r['phone'] ?? '') !== '' && Phone::e164($r['phone'], $country) === null) {
                $bad('landlords', $row, 'The phone number is not valid.');
            }
            $landlords[$k] = ['_row' => $row, 'opening' => (int) $opening, 'data' => array_filter([
                'name' => $name, 'phone' => $r['phone'] ?? null, 'email' => $r['email'] ?? null, 'address' => $r['address'] ?? null, 'payout_method' => $method ?? 'bank',
                'payout_details' => $r['payout_details'] ?? null,
            ], fn ($v) => $v !== null && $v !== '') + self::commission($bp, $flat)];
        }

        // Properties.
        $properties = [];
        $heldBy = [];
        foreach ($existingProps as $p) {
            $heldBy[$key($p->name)] = $p->deposit_held_by ?? $settings->deposit_held_by;
        }
        foreach ($read['properties'] as $r) {
            $row = $r['_row'];
            $name = trim($r['name'] ?? '');
            $k = $key($name);
            if ($name === '') {
                $bad('properties', $row, 'Give the property a name.');

                continue;
            }
            if (mb_strlen($name) > 150) {
                $bad('properties', $row, 'The name is too long (at most 150 characters).');

                continue;
            }
            if (isset($properties[$k]) || isset($existing['properties'][$k])) {
                $bad('properties', $row, "{$name} is in the list twice (or is already in Estate Ledger).");

                continue;
            }
            $landlord = null;
            if (! $owner) {
                $lk = $key($r['landlord_name'] ?? '');
                if ($lk === '') {
                    $bad('properties', $row, 'Say whose property it is (landlord_name).');
                } elseif (! isset($landlords[$lk]) && ! isset($existing['landlords'][$lk])) {
                    $bad('properties', $row, 'No landlord called "'.($r['landlord_name'] ?? '').'" in the landlords sheet or in Estate Ledger.');
                } else {
                    $landlord = $lk;
                }
            }
            $kind = mb_strtolower(trim($r['kind'] ?? '')) ?: 'residential';
            $kind = $kind === 'mixed use' ? 'mixed' : $kind;
            if (! array_key_exists($kind, (array) config('rentals.property_kinds'))) {
                $bad('properties', $row, 'kind must be residential, commercial, mixed or land.');
            }
            $held = mb_strtolower(trim($r['deposit_held_by'] ?? ''));
            if ($held !== '' && ! in_array($held, ['agency', 'landlord'], true)) {
                $bad('properties', $row, 'deposit_held_by must be agency or landlord (or blank).');
            }
            $bp = $this->percent($r['commission_percent'] ?? '', fn ($m) => $bad('properties', $row, $m));
            $flat = $this->amount($r['commission_flat'] ?? '', $decimals, false, fn ($m) => $bad('properties', $row, "commission_flat: {$m}"));
            if ($bp !== null && $flat !== null) {
                $bad('properties', $row, 'Give either commission_percent or commission_flat, not both.');
            }
            $water = $this->amount($r['water_rate'] ?? '', $decimals, false, fn ($m) => $bad('properties', $row, "water_rate: {$m}"));
            $power = $this->amount($r['power_rate'] ?? '', $decimals, false, fn ($m) => $bad('properties', $row, "power_rate: {$m}"));
            $heldBy[$k] = $owner ? 'agency' : ($held !== '' ? $held : $settings->deposit_held_by);
            $properties[$k] = ['_row' => $row, 'landlord' => $landlord, 'data' => array_filter([
                'name' => $name, 'kind' => $kind, 'area' => $r['area'] ?? null, 'district' => $r['district'] ?? null, 'address' => $r['address'] ?? null,
                'deposit_held_by' => $held !== '' ? $held : null, 'water_rate_minor' => $water, 'power_rate_minor' => $power,
            ], fn ($v) => $v !== null && $v !== '') + ($owner ? [] : self::commission($bp, $flat))];
        }

        // Units.
        $units = [];
        $unitInfo = [];
        foreach ($existingUnits as $uk => $u) {
            $unitInfo[$uk] = ['rent' => (int) $u->rent_minor, 'cycle' => (int) $u->cycle_months, 'live' => isset($live[(int) $u->id]),
                'off' => in_array($u->status, ['inactive', 'maintenance'], true)];
        }
        foreach ($read['units'] as $r) {
            $row = $r['_row'];
            $pk = $key($r['property_name'] ?? '');
            $code = trim($r['code'] ?? '');
            if (! isset($properties[$pk]) && ! isset($existing['properties'][$pk])) {
                $bad('units', $row, 'No property called "'.($r['property_name'] ?? '').'" in the properties sheet or in Estate Ledger.');

                continue;
            }
            if ($code === '') {
                $bad('units', $row, 'Give the unit a code, for example A1.');

                continue;
            }
            $uk = $pk.'|'.$key($code);
            if (isset($units[$uk]) || isset($existing['units'][$uk])) {
                $bad('units', $row, "{$r['property_name']} has two units called {$code} (or one is already in Estate Ledger).");

                continue;
            }
            $kind = $this->unitKind($r['kind'] ?? '');
            if ($kind === null) {
                $bad('units', $row, 'kind must be one of: '.implode(', ', array_keys((array) config('rentals.unit_kinds'))).'.');
            }
            $rent = $this->amount($r['rent'] ?? '', $decimals, false, fn ($m) => $bad('units', $row, "rent: {$m}"));
            if ($rent === null || $rent <= 0) {
                if (($r['rent'] ?? '') === '' || $rent === 0) {
                    $bad('units', $row, 'Give the unit its rent a month.');
                }
                $rent = 0;
            }
            $deposit = $this->amount($r['deposit'] ?? '', $decimals, false, fn ($m) => $bad('units', $row, "deposit: {$m}"));
            $cycle = $this->cycle($r['cycle_months'] ?? '');
            if ($cycle === null) {
                $bad('units', $row, 'cycle_months must be 1, 3, 6 or 12.');
            }
            $units[$uk] = ['_row' => $row, 'property' => $pk, 'data' => array_filter([
                'code' => mb_substr($code, 0, 40), 'kind' => $kind ?? 'other', 'floor' => $r['floor'] ?? null, 'rent_minor' => $rent, 'deposit_minor' => $deposit,
                'cycle_months' => $cycle ?? 1, 'water_meter' => $r['water_meter'] ?? null, 'power_meter' => $r['power_meter'] ?? null,
            ], fn ($v) => $v !== null && $v !== '')];
            $unitInfo[$uk] = ['rent' => (int) $rent, 'cycle' => (int) ($cycle ?? 1), 'live' => false, 'off' => false];
        }
        $company = Company::withoutGlobalScopes()->find($cid);
        if ($units !== [] && $company !== null && ! app(Quotas::class)->allows($company, 'units', count($units))) {
            $limit = app(Quotas::class)->limit($company, 'units');
            $err('units', 0, "Your plan allows {$limit} units and this import would take the account past it. Choose a bigger plan first.");
        }

        // Tenancies.
        $tenancies = [];
        $taken = [];
        $people = [];
        $landlordDeposits = [];
        $money = ['owed_minor' => 0, 'credit_minor' => 0, 'deposits_minor' => 0];
        foreach ($read['tenancies'] as $r) {
            $row = $r['_row'];
            $pk = $key($r['property_name'] ?? '');
            $uk = $pk.'|'.$key($r['unit_code'] ?? '');
            if (! isset($unitInfo[$uk])) {
                $bad('tenancies', $row, 'No unit "'.($r['unit_code'] ?? '').'" in "'.($r['property_name'] ?? '').'" (in the units sheet or in Estate Ledger).');

                continue;
            }
            if ($unitInfo[$uk]['live'] || isset($taken[$uk])) {
                $bad('tenancies', $row, "Unit {$r['unit_code']} in {$r['property_name']} already has a tenant.");

                continue;
            }
            if ($unitInfo[$uk]['off']) {
                $bad('tenancies', $row, "Unit {$r['unit_code']} is switched off or under repair in Estate Ledger.");

                continue;
            }
            $taken[$uk] = true;
            $name = trim($r['tenant_name'] ?? '');
            if ($name === '') {
                $bad('tenancies', $row, 'Give the tenant\'s name.');
            } elseif (mb_strlen($name) > 150) {
                $bad('tenancies', $row, 'The tenant\'s name is too long (at most 150 characters).');
            }
            $phone = trim($r['phone'] ?? '');
            $e164 = $phone !== '' ? Phone::e164($phone, $country) : null;
            if ($phone !== '' && $e164 === null) {
                $bad('tenancies', $row, 'The phone number is not valid.');
            }
            if (($r['email'] ?? '') !== '' && ! filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $bad('tenancies', $row, 'The email address is not valid.');
            }
            $start = self::date($r['start_date'] ?? '');
            if ($start === null) {
                $bad('tenancies', $row, 'start_date must look like 31/10/2024 or 2024-10-31.');
            } elseif ($start->gt($asOf)) {
                $bad('tenancies', $row, 'The tenant moves in after the as-of date: record that move-in in Estate Ledger instead.');
            }
            $end = null;
            if (($r['end_date'] ?? '') !== '') {
                $end = self::date($r['end_date']);
                if ($end === null) {
                    $bad('tenancies', $row, 'end_date must look like 31/10/2027 or 2027-10-31 (or be blank).');
                } elseif ($start !== null && $end->lte($start)) {
                    $bad('tenancies', $row, 'end_date must be after start_date.');
                    $end = null;
                } elseif ($end->lte($asOf)) {
                    $bad('tenancies', $row, 'The fixed term ended before the as-of date: leave end_date blank when the tenant stays on month to month.');
                    $end = null;
                }
            }
            $rent = ($r['rent'] ?? '') === '' ? $unitInfo[$uk]['rent'] : $this->amount($r['rent'], $decimals, false, fn ($m) => $bad('tenancies', $row, "rent: {$m}"));
            if ((int) $rent <= 0) {
                $bad('tenancies', $row, 'Give the rent a month (or the unit\'s rent in the units sheet).');
            }
            $cycle = ($r['cycle_months'] ?? '') === '' ? $unitInfo[$uk]['cycle'] : $this->cycle($r['cycle_months']);
            if ($cycle === null) {
                $bad('tenancies', $row, 'cycle_months must be 1, 3, 6 or 12.');
            }
            $deposit = (int) $this->amount($r['deposit_held'] ?? '', $decimals, false, fn ($m) => $bad('tenancies', $row, "deposit_held: {$m}"));
            $owed = (int) $this->amount($r['owed_now'] ?? '', $decimals, false, fn ($m) => $bad('tenancies', $row, "owed_now: {$m}"));
            $credit = (int) $this->amount($r['credit_now'] ?? '', $decimals, false, fn ($m) => $bad('tenancies', $row, "credit_now: {$m}"));
            if ($owed > 0 && $credit > 0) {
                $bad('tenancies', $row, 'A tenant either owes or has paid ahead: give the difference in one of owed_now or credit_now.');
            }
            $items = $this->items($r['items'] ?? '', $decimals, fn ($m) => $bad('tenancies', $row, "items: {$m}"));
            $person = $e164;
            if ($person !== null && isset($people[$person]) && $key($people[$person]) !== $key($name)) {
                $bad('tenancies', $row, "The phone {$phone} is {$people[$person]}'s in an earlier row: give each tenant their own phone number.");
            }
            if ($person !== null) {
                $people[$person] ??= $name;
            }
            if (! isset($properties[$pk]) && isset($existing['properties'][$pk])) {
                $heldBy[$pk] ??= $settings->deposit_held_by;
            }
            if (($heldBy[$pk] ?? $settings->deposit_held_by) === 'landlord' && $deposit > 0) {
                $lk = $properties[$pk]['landlord'] ?? null;
                if ($lk !== null) {
                    $landlordDeposits[$lk] = ($landlordDeposits[$lk] ?? 0) + $deposit;
                }
            }
            $money['owed_minor'] += $owed;
            $money['credit_minor'] += $credit;
            $money['deposits_minor'] += $deposit;
            $tenancies[] = ['_row' => $row, 'unit' => $uk, 'person' => $person,
                'tenant' => array_filter(['name' => $name, 'phone' => $phone, 'email' => $r['email'] ?? null, 'id_number' => $r['id_number'] ?? null], fn ($v) => $v !== null && $v !== '')
                    + ($person === null ? ['allow_duplicate' => true] : []),
                'data' => ['start_date' => $start?->toDateString(), 'end_date' => $end?->toDateString(), 'rent_minor' => (int) $rent, 'cycle_months' => (int) ($cycle ?? 1), 'deposit_minor' => $deposit,
                    'items' => $items, 'import' => ['as_of' => $asOf->toDateString(), 'owed_minor' => $owed, 'credit_minor' => $credit, 'deposit_held_minor' => $deposit]]];
        }
        foreach ($tenancies as $i => $t) {
            if ($t['person'] !== null && isset($existing['tenants'][$t['person']])) {
                // Already a tenant in Estate Ledger (renting another unit): the tenancy is theirs.
                $tenancies[$i]['data']['tenant_id'] = $existing['tenants'][$t['person']];
            }
        }

        // Opening cash.
        $cash = [];
        foreach ($read['opening_cash'] as $r) {
            $row = $r['_row'];
            $method = self::CASH[str_replace(' ', '_', mb_strtolower($r['method'] ?? ''))] ?? null;
            if ($method === null) {
                $bad('opening_cash', $row, 'method must be cash, bank, mobile_money or cheque.');

                continue;
            }
            if (isset($cash[$method])) {
                $bad('opening_cash', $row, "{$method} is in the list twice: give one amount per account.");

                continue;
            }
            if (CashBook::openingRows($cid, $method)->isNotEmpty()) {
                $bad('opening_cash', $row, "The opening balance of {$method} is already in Estate Ledger: it cannot be brought in twice.");

                continue;
            }
            $amount = $this->amount($r['amount'] ?? '', $decimals, false, fn ($m) => $bad('opening_cash', $row, "amount: {$m}"));
            if ((int) $amount <= 0) {
                if ((string) ($r['amount'] ?? '') === '' || $amount === 0) {
                    $bad('opening_cash', $row, 'Give the amount in hand (above zero).');
                }

                continue;
            }
            $cash[$method] = ['_row' => $row, 'method' => $method, 'amount' => (int) $amount];
        }

        $rows = array_sum(array_map('count', $read));
        $ok = 0;
        foreach ($read as $sheet => $list) {
            foreach ($list as $r) {
                $ok += isset($badRows[$sheet.':'.$r['_row']]) ? 0 : 1;
            }
        }
        $newTenants = count(array_filter($tenancies, fn ($t) => ! isset($t['data']['tenant_id']) && $t['person'] === null))
            + count(array_unique(array_filter(array_map(fn ($t) => isset($t['data']['tenant_id']) ? null : $t['person'], $tenancies))));
        usort($errors, fn ($a, $b) => [array_search($a['sheet'], array_keys(self::SHEETS), true), $a['row']] <=> [array_search($b['sheet'], array_keys(self::SHEETS), true), $b['row']]);

        return [
            'ok' => $ok, 'errors' => $errors, 'as_of' => $asOf->toDateString(),
            'summary' => ['as_of' => $asOf->toDateString(), 'rows' => $rows, 'landlords' => count($landlords), 'properties' => count($properties), 'units' => count($units),
                'tenants' => $newTenants, 'tenancies' => count($tenancies), 'landlords_owed_minor' => array_sum(array_column($landlords, 'opening')),
                'cash_minor' => array_sum(array_column($cash, 'amount')), 'cash' => array_map(fn ($c) => $c['amount'], $cash)] + $money,
            'landlords' => $landlords, 'properties' => $properties, 'units' => $units, 'tenancies' => array_map(function ($t) {
                if (isset($t['data']['tenant_id'])) {
                    $t['person'] = null;
                }

                return $t;
            }, $tenancies), 'opening_cash' => array_values($cash), 'landlord_deposits' => $landlordDeposits,
            'existing' => ['landlords' => $existing['landlords'], 'properties' => $existing['properties'], 'units' => $existing['units'], 'tenants' => []],
        ];
    }

    /** A write that a service refused after the checks passed: named by its sheet and row, and the whole import rolls back. */
    private function step(string $sheet, int $row, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            throw BusinessRuleException::make('import_failed', self::where(['sheet' => $sheet, 'row' => $row]).': '.$e->getMessage().' Nothing was imported.',
                ['sheet' => $sheet, 'row' => $row, 'reason' => $e->errorCode()]);
        }
    }

    private static function where(array $e): string
    {
        return $e['row'] > 0 ? "{$e['sheet']}, row {$e['row']}" : $e['sheet'];
    }

    /** The commission override a landlord or property row gives: a percent, a flat amount per unit per month, or none. */
    private static function commission(?int $bp, ?int $flat): array
    {
        if ($flat !== null) {
            return ['commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => $flat];
        }

        return $bp !== null ? ['commission_type' => 'percent', 'commission_bp' => $bp] : [];
    }

    /** A date typed as d/m/Y or Y-m-d. */
    public static function date(?string $raw): ?Carbon
    {
        $s = trim((string) $raw);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? Carbon::create($y, $mo, $d, 0, 0, 0, 'UTC') : null;
    }

    /** An amount typed in major units; blank is null. Negative ("-50,000" or "(50,000)") only where allowed. */
    private function amount(string $raw, int $decimals, bool $negative, callable $fail): ?int
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $minus = false;
        if (preg_match('/^\((.*)\)$/', $s, $m) || preg_match('/^-\s*(.*)$/', $s, $m)) {
            $minus = true;
            $s = $m[1];
        }
        if ($minus && ! $negative) {
            $fail('it cannot be below zero.');

            return null;
        }
        try {
            $v = Minor::parse($s, $decimals);
        } catch (BusinessRuleException $e) {
            $fail($e->getMessage());

            return null;
        }

        return $minus ? -$v : $v;
    }

    /** "10", "10 %", "7.5" → basis points; blank is null. */
    private function percent(string $raw, callable $fail): ?int
    {
        $s = trim(str_replace('%', '', $raw));
        if ($s === '') {
            return null;
        }
        try {
            $bp = Minor::parse($s, 2);
        } catch (BusinessRuleException) {
            $fail('commission_percent must be a number from 0 to 100, for example 10.');

            return null;
        }
        if ($bp > 10000) {
            $fail('commission_percent cannot be above 100.');

            return null;
        }

        return $bp;
    }

    private function cycle(string $raw): ?int
    {
        $s = str_replace(' ', '_', mb_strtolower(trim($raw)));

        return $s === '' ? 1 : (self::CYCLES[$s] ?? null);
    }

    private function unitKind(string $raw): ?string
    {
        $s = mb_strtolower(trim($raw));
        if ($s === '') {
            return 'other';
        }
        $kinds = (array) config('rentals.unit_kinds');
        $snake = str_replace([' ', '-'], '_', $s);
        if (array_key_exists($snake, $kinds)) {
            return $snake;
        }
        foreach ($kinds as $k => $label) {
            if (mb_strtolower($label) === $s) {
                return $k;
            }
        }

        return self::UNIT_WORDS[$s] ?? self::UNIT_WORDS[$snake] ?? null;
    }

    /**
     * "Garbage:10000;Security:20000:agency" → lease items (a month's amount each; landlord's unless ":agency").
     *
     * @return list<array{name: string, amount_minor: int, goes_to: string}>
     */
    private function items(string $raw, int $decimals, callable $fail): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $raw)), fn ($s) => $s !== '') as $part) {
            $bits = array_map('trim', explode(':', $part));
            $name = $bits[0] ?? '';
            $amount = isset($bits[1]) ? $this->amount($bits[1], $decimals, false, fn ($m) => $fail("{$name}: {$m}")) : null;
            $goesTo = mb_strtolower($bits[2] ?? 'landlord');
            if ($name === '' || $amount === null || $amount <= 0) {
                $fail('write each fee as name:amount, for example Garbage:10000;Security:20000.');

                continue;
            }
            if (! in_array($goesTo, ['landlord', 'agency'], true)) {
                $fail("{$name}: the third part is agency or landlord.");

                continue;
            }
            $out[] = ['name' => mb_substr($name, 0, 80), 'amount_minor' => $amount, 'goes_to' => $goesTo];
        }

        return $out;
    }
}
