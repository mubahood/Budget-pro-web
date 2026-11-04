<?php

namespace App\Services\Rentals\Search;

use App\Support\DataCrypt;
use App\Support\Rentals\SearchText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps rental_search_entries and rental_search_keys in step with the rent books. Every rental model that is searched reports its
 * writes here (App\Traits\RentalRecord), whichever app or job wrote it; the rows are written once, after the writer's transaction
 * has committed, so a search index can never hold a money lock open or fail a payment. `rentals:search-index` fills what is missing
 * for accounts that already exist and repairs anything a crash missed.
 *
 * A copy for finding only: it never holds a balance (those are read when answering), a tenant's name is not copied onto their
 * receipts and bills (those are found through the tenant), and an ID number is kept only as a keyed hash.
 */
final class SearchIndex
{
    /** Kind => its table. */
    public const TABLES = [
        'tenant' => 'rental_tenants', 'unit' => 'rental_units', 'lease' => 'rental_leases', 'payment' => 'rental_payments', 'bill' => 'rental_charges',
        'landlord' => 'rental_landlords', 'property' => 'rental_properties', 'payout' => 'rental_payouts', 'expense' => 'rental_expenses', 'ticket' => 'rental_maintenance',
    ];

    /** Model => [its kind, the columns that change what is found or shown]. Other writes (a bill's paid amount, a lease's billed-until) need no new index row. */
    private const MODELS = [
        \App\Models\RentalTenant::class => ['tenant', ['code', 'name', 'phone', 'phone_e164', 'alt_phone', 'email', 'id_number', 'employer', 'occupation', 'next_of_kin_phone', 'status']],
        \App\Models\RentalUnit::class => ['unit', ['code', 'property_id', 'status', 'water_meter', 'power_meter']],
        \App\Models\RentalLease::class => ['lease', ['number', 'unit_id', 'property_id', 'tenant_id', 'status']],
        \App\Models\RentalPayment::class => ['payment', ['number', 'reference', 'amount_minor', 'paid_on', 'method', 'reversed_at', 'lease_id', 'tenant_id', 'property_id']],
        \App\Models\RentalCharge::class => ['bill', ['description', 'amount_minor', 'due_date', 'status', 'lease_id', 'tenant_id', 'property_id']],
        \App\Models\RentalLandlord::class => ['landlord', ['code', 'name', 'phone', 'phone_e164', 'status', 'is_self']],
        \App\Models\RentalProperty::class => ['property', ['code', 'name', 'area', 'district', 'status']],
        \App\Models\RentalPayout::class => ['payout', ['number', 'reference', 'landlord_id', 'amount_minor', 'paid_on', 'reversed_at']],
        \App\Models\RentalExpense::class => ['expense', ['number', 'category', 'description', 'vendor', 'property_id', 'amount_minor', 'paid_on', 'reversed_at']],
        \App\Models\RentalMaintenance::class => ['ticket', ['number', 'title', 'assignee_name', 'property_id', 'unit_id', 'tenant_id', 'status', 'cost_minor']],
    ];

    /** @var array<string, array<int, true>> kind => ids written and not yet indexed */
    private static array $dirty = [];

    /** Called by every rental model when saved. Never throws: search must not stop a write. */
    public static function saved(Model $model): void
    {
        $known = self::MODELS[$model::class] ?? null;
        if ($known === null || (! $model->wasRecentlyCreated && ! $model->wasChanged($known[1]))) {
            return;
        }
        self::note($known[0], (int) $model->getKey());
    }

    public static function deleted(Model $model): void
    {
        $known = self::MODELS[$model::class] ?? null;
        if ($known !== null) {
            self::note($known[0], (int) $model->getKey());
        }
    }

    /** One record to index when the current transaction commits (at once when there is none). */
    public static function note(string $kind, int $id): void
    {
        self::$dirty[$kind][$id] = true;
        try {
            DB::afterCommit(fn () => self::flush());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Index what has been noted. Safe to call at any time; a failure is reported, and `rentals:search-index` repairs it that night. */
    public static function flush(): void
    {
        if (self::$dirty === []) {
            return;
        }
        $dirty = self::$dirty;
        self::$dirty = [];
        try {
            $index = new self;
            foreach ($index->withRelated($dirty) as $kind => $ids) {
                $index->indexRecords($kind, array_keys($ids));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A lease shows its tenant and unit, a unit its property, a payout its landlord: when those change, so do the rows that show them.
     *
     * @param  array<string, array<int, true>>  $dirty
     * @return array<string, array<int, true>>
     */
    private function withRelated(array $dirty): array
    {
        $add = function (string $kind, iterable $ids) use (&$dirty) {
            foreach ($ids as $id) {
                $dirty[$kind][(int) $id] = true;
            }
        };
        if (! empty($dirty['property'])) {
            $add('unit', DB::table('rental_units')->whereIn('property_id', array_keys($dirty['property']))->pluck('id'));
        }
        if (! empty($dirty['unit'])) {
            $add('lease', DB::table('rental_leases')->whereIn('unit_id', array_keys($dirty['unit']))->pluck('id'));
        }
        if (! empty($dirty['tenant'])) {
            $add('lease', DB::table('rental_leases')->whereIn('tenant_id', array_keys($dirty['tenant']))->pluck('id'));
        }
        if (! empty($dirty['landlord'])) {
            $add('payout', DB::table('rental_payouts')->whereIn('landlord_id', array_keys($dirty['landlord']))->pluck('id'));
        }
        if (! empty($dirty['lease'])) {
            // A move-out marks the tenant former with a plain update, so the lease brings the tenant along.
            $add('tenant', DB::table('rental_leases')->whereIn('id', array_keys($dirty['lease']))->pluck('tenant_id'));
        }

        return $dirty;
    }

    /**
     * Fill the index. By default: every record not yet in it, and every record changed in the last two days, and it removes rows
     * whose record is gone. `$full` rewrites everything (after the rules for cutting text change).
     *
     * @return array{indexed: int, removed: int}
     */
    public function fill(int $companyId, bool $full = false): array
    {
        $indexed = 0;
        $removed = 0;
        $since = now()->subDays(2);
        foreach (self::TABLES as $kind => $table) {
            $last = 0;
            while (true) {
                $q = DB::table($table.' as s')->where('s.company_id', $companyId)->where('s.id', '>', $last)->orderBy('s.id')->limit(500);
                if (! $full) {
                    $q->where(fn ($w) => $w->where('s.updated_at', '>=', $since)
                        ->orWhereNotExists(fn ($e) => $e->selectRaw('1')->from('rental_search_entries as e')->where('e.kind', $kind)->whereColumn('e.record_id', 's.id')));
                }
                $ids = $q->pluck('s.id')->map(fn ($id) => (int) $id)->all();
                if ($ids === []) {
                    break;
                }
                $this->indexRecords($kind, $ids);
                $indexed += count($ids);
                $last = (int) end($ids);
            }
            $gone = DB::table('rental_search_entries as e')->where('e.company_id', $companyId)->where('e.kind', $kind)
                ->whereNotExists(fn ($s) => $s->selectRaw('1')->from($table.' as s')->whereColumn('s.id', 'e.record_id'))->pluck('e.id')->all();
            $this->remove($gone);
            $removed += count($gone);
        }

        return ['indexed' => $indexed, 'removed' => $removed];
    }

    /**
     * Write the rows of these records, and remove the rows of records that no longer exist.
     *
     * @param  list<int>  $ids
     */
    public function indexRecords(string $kind, array $ids): void
    {
        if ($ids === [] || ! isset(self::TABLES[$kind])) {
            return;
        }
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $docs = $this->documents($kind, $chunk);
            DB::transaction(function () use ($kind, $chunk, $docs) {
                $this->write($kind, $chunk, $docs);
            });
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $docs  record id => its row and `keys`
     */
    private function write(string $kind, array $ids, array $docs): void
    {
        $missing = array_values(array_diff($ids, array_keys($docs)));
        if ($missing !== []) {
            $this->remove(DB::table('rental_search_entries')->where('kind', $kind)->whereIn('record_id', $missing)->pluck('id')->all());
        }
        if ($docs === []) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($docs as $id => $doc) {
            $row = $doc;
            unset($row['keys']);
            $rows[] = $row + ['kind' => $kind, 'record_id' => $id, 'updated_at' => $now];
        }
        DB::table('rental_search_entries')->upsert($rows, ['kind', 'record_id'], ['company_id', 'property_id', 'tenant_id', 'title', 'subtitle', 'status', 'body', 'amount_minor', 'on_date', 'updated_at']);
        $entryIds = DB::table('rental_search_entries')->where('kind', $kind)->whereIn('record_id', array_keys($docs))->pluck('id', 'record_id');
        DB::table('rental_search_keys')->whereIn('entry_id', $entryIds->values()->all())->delete();
        $keys = [];
        foreach ($docs as $id => $doc) {
            foreach ($doc['keys'] as $term) {
                $keys[] = ['entry_id' => (int) $entryIds[$id], 'company_id' => $doc['company_id'], 'term' => $term];
            }
        }
        foreach (array_chunk($keys, 1000) as $chunk) {
            DB::table('rental_search_keys')->insert($chunk);
        }
    }

    /** @param  list<int>  $entryIds */
    private function remove(array $entryIds): void
    {
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            DB::table('rental_search_keys')->whereIn('entry_id', $chunk)->delete();
            DB::table('rental_search_entries')->whereIn('id', $chunk)->delete();
        }
    }

    /**
     * The hash an ID number is found by: keyed with the key both apps share, so the table alone tells nothing about it.
     */
    public static function idTerm(string $idNumber): string
    {
        return 'i:'.substr(DataCrypt::hash('rental-search-id', SearchText::code($idNumber)), 0, 40);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function documents(string $kind, array $ids): array
    {
        $docs = [];
        foreach ($this->rows($kind, $ids) as $r) {
            $doc = match ($kind) {
                'tenant' => $this->tenant($r),
                'unit' => $this->unit($r),
                'lease' => $this->lease($r),
                'payment' => $this->payment($r),
                'bill' => $this->bill($r),
                'landlord' => $this->landlord($r),
                'property' => $this->property($r),
                'payout' => $this->payout($r),
                'expense' => $this->expense($r),
                'ticket' => $this->ticket($r),
                default => null,
            };
            if ($doc !== null) {
                $docs[(int) $r->id] = $doc;
            }
        }

        return $docs;
    }

    /**
     * @param  list<int>  $ids
     * @return iterable<object>
     */
    private function rows(string $kind, array $ids): iterable
    {
        $q = DB::table(self::TABLES[$kind].' as s')->whereIn('s.id', $ids);

        return match ($kind) {
            'unit' => $q->leftJoin('rental_properties as p', 'p.id', '=', 's.property_id')->get(['s.*', 'p.name as property_name'])->all(),
            'lease' => $q->leftJoin('rental_tenants as t', 't.id', '=', 's.tenant_id')->leftJoin('rental_units as u', 'u.id', '=', 's.unit_id')->leftJoin('rental_properties as p', 'p.id', '=', 's.property_id')
                ->get(['s.*', 't.name as tenant_name', 'u.code as unit_code', 'p.name as property_name'])->all(),
            'payout' => $q->leftJoin('rental_landlords as l', 'l.id', '=', 's.landlord_id')->get(['s.*', 'l.name as landlord_name'])->all(),
            'ticket' => $q->leftJoin('rental_units as u', 'u.id', '=', 's.unit_id')->leftJoin('rental_properties as p', 'p.id', '=', 's.property_id')
                ->get(['s.*', 'u.code as unit_code', 'p.name as property_name'])->all(),
            default => $q->get()->all(),
        };
    }

    /** @return array<string, mixed> */
    private function tenant(object $t): array
    {
        $keys = [];
        $this->words($keys, $t->name, true);
        $this->words($keys, $t->employer);
        $this->words($keys, $t->occupation);
        $this->code($keys, $t->code);
        $this->code($keys, $t->email);
        $this->phone($keys, $t->phone);
        $this->phone($keys, $t->phone_e164);
        $this->phone($keys, $t->alt_phone);
        if (($kin = SearchText::national($t->next_of_kin_phone)) !== '') {
            $keys['q:'.$kin] = true;
        }
        if (! empty($t->id_number)) {
            try {
                $keys[self::idTerm(DataCrypt::decrypt($t->id_number))] = true;
            } catch (\Throwable) {
                // An ID stored under an older key can't be read: the tenant is still found by everything else.
            }
        }

        return $this->doc($t, $t->name, $t->code, $t->status, $keys, ['tenant_id' => (int) $t->id]);
    }

    /** @return array<string, mixed> */
    private function unit(object $u): array
    {
        $keys = [];
        $this->words($keys, $u->code);
        $this->words($keys, $u->property_name);
        $this->code($keys, $u->code);
        $this->code($keys, $u->water_meter);
        $this->code($keys, $u->power_meter);

        return $this->doc($u, $u->code, $u->property_name, $u->status, $keys, ['property_id' => (int) $u->property_id]);
    }

    /** @return array<string, mixed> */
    private function lease(object $l): array
    {
        $keys = [];
        $this->words($keys, $l->tenant_name);
        $this->words($keys, $l->unit_code);
        $this->words($keys, $l->property_name);
        $this->code($keys, $l->number);

        return $this->doc($l, $l->number, trim($l->tenant_name.' · '.$l->unit_code.' '.$l->property_name), $l->status, $keys, ['property_id' => (int) $l->property_id, 'tenant_id' => (int) $l->tenant_id]);
    }

    /** @return array<string, mixed> */
    private function payment(object $p): array
    {
        $keys = [];
        $this->code($keys, $p->number);
        $this->code($keys, $p->reference);

        return $this->doc($p, $p->number, $p->reference, $p->reversed_at ? 'reversed' : null, $keys,
            ['property_id' => (int) $p->property_id, 'tenant_id' => (int) $p->tenant_id, 'amount_minor' => (int) $p->amount_minor, 'on_date' => $p->paid_on]);
    }

    /** @return array<string, mixed> */
    private function bill(object $c): array
    {
        $keys = [];
        foreach (SearchText::words($c->description) as $word) {
            $keys['b:'.substr($word, 0, SearchText::MAX_TERM)] = true;
        }

        return $this->doc($c, $c->description, null, $c->status, $keys,
            ['property_id' => (int) $c->property_id, 'tenant_id' => (int) $c->tenant_id, 'amount_minor' => (int) $c->amount_minor, 'on_date' => $c->due_date]);
    }

    /** @return array<string, mixed>|null */
    private function landlord(object $l): ?array
    {
        if ($l->is_self) {
            return null; // an owner-run account's own books, not a landlord anyone looks for
        }
        $keys = [];
        $this->words($keys, $l->name, true);
        $this->code($keys, $l->code);
        $this->code($keys, $l->email);
        $this->phone($keys, $l->phone);
        $this->phone($keys, $l->phone_e164);

        return $this->doc($l, $l->name, $l->phone, $l->status, $keys);
    }

    /** @return array<string, mixed> */
    private function property(object $p): array
    {
        $keys = [];
        $this->words($keys, $p->name, true);
        $this->words($keys, $p->area, true);
        $this->words($keys, $p->district);
        $this->code($keys, $p->code);

        return $this->doc($p, $p->name, trim(implode(', ', array_filter([$p->area, $p->district]))), $p->status, $keys, ['property_id' => (int) $p->id]);
    }

    /** @return array<string, mixed> */
    private function payout(object $p): array
    {
        $keys = [];
        $this->words($keys, $p->landlord_name);
        $this->code($keys, $p->number);
        $this->code($keys, $p->reference);

        return $this->doc($p, $p->number, $p->landlord_name, $p->reversed_at ? 'reversed' : null, $keys, ['amount_minor' => (int) $p->amount_minor, 'on_date' => $p->paid_on]);
    }

    /** @return array<string, mixed> */
    private function expense(object $e): array
    {
        $keys = [];
        $this->words($keys, $e->vendor);
        $this->words($keys, $e->category);
        $this->words($keys, $e->description);
        $this->code($keys, $e->number);

        return $this->doc($e, $e->number, implode(' · ', array_filter([$e->vendor, $e->category])), $e->reversed_at ? 'reversed' : null, $keys,
            ['property_id' => $e->property_id ? (int) $e->property_id : null, 'amount_minor' => (int) $e->amount_minor, 'on_date' => $e->paid_on]);
    }

    /** @return array<string, mixed> */
    private function ticket(object $m): array
    {
        $keys = [];
        $this->words($keys, $m->title);
        $this->words($keys, $m->assignee_name);
        $this->code($keys, $m->number);

        return $this->doc($m, $m->title, trim($m->number.' · '.trim(($m->unit_code ?? '').' '.($m->property_name ?? ''))), $m->status, $keys,
            ['property_id' => (int) $m->property_id, 'tenant_id' => $m->tenant_id ? (int) $m->tenant_id : null, 'on_date' => substr((string) $m->created_at, 0, 10) ?: null]);
    }

    /**
     * @param  array<string, true>  $keys
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function doc(object $r, ?string $title, ?string $subtitle, ?string $status, array $keys, array $more = []): array
    {
        $body = [];
        foreach (array_keys($keys) as $term) {
            if (str_starts_with($term, 'w:') || str_starts_with($term, 'b:') || str_starts_with($term, 'c:')) {
                $body[] = substr($term, 2);
            }
        }

        return $more + [
            'company_id' => (int) $r->company_id,
            'property_id' => null, 'tenant_id' => null, 'amount_minor' => null, 'on_date' => null,
            'title' => Str::limit((string) $title, 185, ''),
            'subtitle' => $subtitle === null || $subtitle === '' ? null : Str::limit($subtitle, 250, ''),
            'status' => $status,
            'body' => implode(' ', $body),
            'keys' => array_keys($keys),
        ];
    }

    /** @param  array<string, true>  $keys */
    private function words(array &$keys, ?string $text, bool $sounds = false): void
    {
        foreach (SearchText::words($text) as $word) {
            $keys['w:'.substr($word, 0, SearchText::MAX_TERM)] = true;
            if ($sounds) {
                if (($sound = SearchText::sound($word)) !== '') {
                    $keys['s:'.$sound] = true;
                }
                if (($skeleton = SearchText::skeleton($word)) !== '') {
                    $keys['k:'.$skeleton] = true;
                }
            }
        }
    }

    /** @param  array<string, true>  $keys */
    private function code(array &$keys, ?string $text): void
    {
        $code = substr(SearchText::code($text), 0, SearchText::MAX_TERM);
        if ($code === '') {
            return;
        }
        $keys['c:'.$code] = true;
        // R-001450 is also found as 1450.
        if (($number = ltrim(SearchText::digits($text), '0')) !== '' && $number !== $code) {
            $keys['n:'.substr($number, 0, SearchText::MAX_TERM)] = true;
        }
    }

    /** @param  array<string, true>  $keys */
    private function phone(array &$keys, ?string $text): void
    {
        $national = SearchText::national($text);
        if (strlen($national) < 5) {
            return;
        }
        $keys['p:'.substr($national, 0, SearchText::MAX_TERM)] = true;
        $keys['r:'.substr(strrev($national), 0, SearchText::MAX_TERM)] = true;
    }
}
