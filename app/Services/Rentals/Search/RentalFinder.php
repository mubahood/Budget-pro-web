<?php

namespace App\Services\Rentals\Search;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLease;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use App\Support\Rentals\SearchText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The phone's live search (`GET rentals/search`): the kernel's smaller copy of the portal's App\Shop\RentalSearch, over the same index (SearchIndex), with the same scores and
 * the same phone rule: 0772…, 772… and +256772… are one number, and the end of a number finds it too. It answers with a `link` where the web has an address, and has no pins,
 * history or `is:` filters. A person sees only what they may (the kind's permission) and where they may (a caretaker's properties; a tenant through a lease there).
 */
final class RentalFinder
{
    /** Index kind => [the API's kind, label, who may see it], in the order groups show when they tie. */
    public const KINDS = [
        'tenant' => ['tenant', 'Tenants', 'rentals_view'],
        'unit' => ['unit', 'Units', 'rentals_view'],
        'payment' => ['payment', 'Receipts', 'rentals_view'],
        'lease' => ['lease', 'Leases', 'rentals_view'],
        'landlord' => ['landlord', 'Landlords', 'rentals_landlords'],
        'property' => ['property', 'Properties', 'rentals_view'],
        'expense' => ['expense', 'Expenses', 'rentals_expenses'],
        'ticket' => ['maintenance', 'Repairs', 'rentals_maintenance'],
    ];

    /** `kind:` typed in front of a query. */
    private const PREFIXES = ['tenant' => 'tenant', 'tenants' => 'tenant', 'unit' => 'unit', 'units' => 'unit', 'lease' => 'lease', 'leases' => 'lease', 'receipt' => 'payment', 'receipts' => 'payment',
        'payment' => 'payment', 'payments' => 'payment', 'landlord' => 'landlord', 'landlords' => 'landlord', 'property' => 'property', 'properties' => 'property', 'expense' => 'expense', 'expenses' => 'expense',
        'repair' => 'ticket', 'repairs' => 'ticket'];

    /** Kinds found by money. */
    private const MONEY_KINDS = ['payment', 'expense'];

    /** Words find people and places before papers. */
    private const WORD_SCORES = ['tenant' => 300, 'property' => 295, 'unit' => 290, 'landlord' => 285, 'ticket' => 270, 'expense' => 265, 'lease' => 250];

    /** A record of the past shows after a current one. */
    private const PAST_STATES = ['former', 'ended', 'cancelled', 'inactive', 'archived', 'reversed'];

    private const CANDIDATES = 200;

    /** Fewer than this, and names that sound alike are looked for too. */
    private const ENOUGH = 3;

    public const RECENT_KEEP = 8;

    public const PIN_LIMIT = 12;

    private readonly int $cid;

    /** @var list<int>|null */
    private readonly ?array $properties;

    public function __construct(private readonly User $by)
    {
        $this->cid = (int) $by->company_id;
        $this->properties = RentalAccess::propertyIds($by);
    }

    /**
     * @return array{q: string, total: int, groups: list<array{kind: string, label: string, total: int, results: list<array<string, mixed>>}>}
     */
    public function find(string $typed, int $perKind = 5): array
    {
        $none = ['q' => $typed, 'total' => 0, 'groups' => []];
        $query = trim(mb_substr($typed, 0, 100));
        $kinds = $this->kinds();
        if (preg_match('/^\s*([a-z]+)\s*:\s*(.*)$/i', $query, $m) === 1 && isset(self::PREFIXES[strtolower($m[1])])) {
            $kinds = array_values(array_intersect($kinds, [self::PREFIXES[strtolower($m[1])]]));
            $query = trim($m[2]);
        }
        $amounts = [];
        $dates = [];
        $plain = [];
        if (! SearchText::surelyPhone($query)) {
            [$dates, $query] = SearchText::dates($query, LocalDate::today($this->cid));
            [$amounts, $query, $plain] = SearchText::amounts($query);
        }
        $scale = 10 ** Minor::decimals($this->cid);
        $p = ['query' => $query, 'words' => SearchText::words($query), 'amounts' => array_map(fn (int $a) => $a * $scale, $amounts), 'plain' => array_map(fn (int $a) => $a * $scale, $plain), 'dates' => $dates];
        $hasMoney = $p['amounts'] !== [] || $p['dates'] !== [];
        $wordy = mb_strlen($query) >= 2;
        if ($kinds === [] || (! $wordy && ! $hasMoney)) {
            return $none;
        }

        /** @var array<int, float> $scores */
        $scores = [];
        $add = function (iterable $ids, float $score) use (&$scores): void {
            foreach ($ids as $id) {
                $scores[(int) $id] = max($scores[(int) $id] ?? 0.0, $score);
            }
        };
        if ($hasMoney) {
            $this->findByMoney($p, $kinds, $add);
            if ($p['words'] !== []) {
                $wordy = false; // the words were the payer's: they look for nothing else
            }
        }
        if ($wordy) {
            $this->findByCode($p, $kinds, $add);
            $this->findByPhone($p, $kinds, $add);
            $this->findById($p, $kinds, $add);
            $this->findByWords($p, $kinds, $add);
        }
        $close = [];
        if ($wordy && count($scores) < self::ENOUGH && $p['words'] !== [] && ! SearchText::looksLikePhone($query)) {
            $close = $this->findClose($p['words'], $kinds, $scores);
        }
        if ($scores === []) {
            return $none;
        }
        $entries = $this->scoped(DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->whereIn('e.kind', $kinds))
            ->whereIn('e.id', array_keys($scores))->get(['e.id', 'e.kind', 'e.record_id', 'e.property_id', 'e.tenant_id', 'e.title', 'e.subtitle', 'e.status', 'e.amount_minor', 'e.on_date']);
        if ($entries->isEmpty()) {
            return $none;
        }

        return ['q' => $typed, 'total' => $entries->count(), 'groups' => $this->group($entries, $scores, $close, $p['words'], $perKind)];
    }

    /** The API's kind (`maintenance`) as the index calls it (`ticket`); null when there is no such kind. */
    public static function indexKind(string $api): ?string
    {
        foreach (self::KINDS as $index => [$name]) {
            if ($name === $api) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Pinned and recent records, for when nothing is typed: the same marks as the web's search, in the same shape as a result, each with `pinned`.
     *
     * @return array{pinned: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function home(): array
    {
        $marks = DB::table('rental_search_marks')->where('company_id', $this->cid)->where('user_id', $this->by->id)
            ->where(fn ($w) => $w->where('pinned', true)->orWhereNotNull('opened_at'))->orderByDesc('opened_at')->limit(self::RECENT_KEEP + self::PIN_LIMIT)->get();
        $kinds = $this->kinds();
        $marks = $marks->filter(fn ($m) => in_array($m->kind, $kinds, true))->values();
        if ($marks->isEmpty()) {
            return ['pinned' => [], 'recent' => []];
        }
        $q = DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->where(function ($w) use ($marks) {
            foreach ($marks->groupBy('kind') as $kind => $group) {
                $w->orWhere(fn ($o) => $o->where('e.kind', $kind)->whereIn('e.record_id', $group->pluck('record_id')->all()));
            }
        });
        $entries = $this->scoped($q)->get(['e.id', 'e.kind', 'e.record_id', 'e.property_id', 'e.tenant_id', 'e.title', 'e.subtitle', 'e.status', 'e.amount_minor', 'e.on_date']);
        $money = $this->tenantMoney($entries->where('kind', 'tenant')->pluck('record_id')->map(fn ($v) => (int) $v)->all());
        $items = [];
        foreach ($entries->groupBy('kind') as $kind => $group) {
            $lines = $this->lines($kind, $group, $money);
            foreach ($group as $e) {
                $line = $lines[(int) $e->record_id] ?? ['detail' => $e->subtitle, 'owes' => false];
                $api = self::KINDS[$kind][0];
                $items[$kind.':'.$e->record_id] = ['kind' => $api, 'id' => (int) $e->record_id, 'title' => (string) $e->title, 'detail' => $line['detail'] ?: null, 'owes' => (bool) ($line['owes'] ?? false), 'close' => false,
                    'link' => ['type' => $api, 'id' => (int) $e->record_id]];
            }
        }
        $pinned = [];
        $recent = [];
        foreach ($marks as $m) {
            $item = $items[$m->kind.':'.$m->record_id] ?? null;
            if ($item === null) {
                continue;
            }
            $item['pinned'] = (bool) $m->pinned;
            if ($m->pinned) {
                $pinned[] = $item;
            } elseif (count($recent) < self::RECENT_KEEP) {
                $recent[] = $item;
            }
        }

        return ['pinned' => $pinned, 'recent' => $recent];
    }

    /** Note that this person opened a record. Ignored when they may not see it. */
    public function opened(string $kind, int $id): void
    {
        if (! $this->visible($kind, $id)) {
            return;
        }
        $user = (int) $this->by->id;
        $now = now();
        DB::table('rental_search_marks')->upsert([['company_id' => $this->cid, 'user_id' => $user, 'kind' => $kind, 'record_id' => $id, 'pinned' => false, 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['user_id', 'kind', 'record_id'], ['opened_at', 'updated_at']);
        $old = DB::table('rental_search_marks')->where('company_id', $this->cid)->where('user_id', $user)->where('pinned', false)
            ->orderByDesc('opened_at')->orderByDesc('id')->offset(self::RECENT_KEEP)->limit(20)->pluck('id');
        if ($old->isNotEmpty()) {
            DB::table('rental_search_marks')->whereIn('id', $old->all())->delete();
        }
    }

    /** Pin or unpin. @return bool|null true when now pinned, false when not, null when there are too many pins. @throws BusinessRuleException not_found when they may not see it */
    public function togglePin(string $kind, int $id): ?bool
    {
        if (! $this->visible($kind, $id)) {
            throw BusinessRuleException::make('not_found', 'That record was not found.');
        }
        $user = (int) $this->by->id;
        $mark = DB::table('rental_search_marks')->where('user_id', $user)->where('kind', $kind)->where('record_id', $id)->first();
        $now = now();
        if ($mark !== null && $mark->pinned) {
            DB::table('rental_search_marks')->where('id', $mark->id)->update(['pinned' => false, 'updated_at' => $now]);

            return false;
        }
        if (DB::table('rental_search_marks')->where('company_id', $this->cid)->where('user_id', $user)->where('pinned', true)->count() >= self::PIN_LIMIT) {
            return null;
        }
        DB::table('rental_search_marks')->upsert([['company_id' => $this->cid, 'user_id' => $user, 'kind' => $kind, 'record_id' => $id, 'pinned' => true, 'opened_at' => $mark?->opened_at, 'created_at' => $now, 'updated_at' => $now]],
            ['user_id', 'kind', 'record_id'], ['pinned', 'updated_at']);

        return true;
    }

    /** Whether this person may see this record: their kinds, their account and, for a caretaker, their properties. */
    private function visible(string $kind, int $id): bool
    {
        return in_array($kind, $this->kinds(), true)
            && $this->scoped(DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->where('e.kind', $kind)->where('e.record_id', $id))->exists();
    }

    /** @return list<string> the kinds this person may see */
    private function kinds(): array
    {
        $owner = RentalsModule::isOwnerMode($this->cid);

        return array_values(array_filter(array_keys(self::KINDS), fn (string $k): bool => RentalAccess::can($this->by, self::KINDS[$k][2]) && ! ($owner && $k === 'landlord')));
    }

    private function scoped(Builder $q): Builder
    {
        if ($this->properties === null) {
            return $q;
        }
        $ids = $this->properties ?: [0];

        return $q->where(fn ($w) => $w->where(fn ($p) => $p->where('e.kind', '!=', 'tenant')->whereIn('e.property_id', $ids))
            ->orWhere(fn ($t) => $t->where('e.kind', 'tenant')->whereIn('e.record_id', DB::table('rental_leases')->where('company_id', $this->cid)->whereIn('property_id', $ids)->select('tenant_id'))));
    }

    /** Keys of this account whose entry is of these kinds and visible to this person. @param list<string> $kinds */
    private function keys(array $kinds): Builder
    {
        return $this->scoped(DB::table('rental_search_keys as k')->join('rental_search_entries as e', 'e.id', '=', 'k.entry_id')
            ->where('k.company_id', $this->cid)->whereIn('e.kind', $kinds))->select('k.entry_id')->limit(self::CANDIDATES);
    }

    /** @param array<string, mixed> $p @param list<string> $kinds */
    private function findByMoney(array $p, array $kinds, callable $add): void
    {
        $money = array_values(array_intersect($kinds, self::MONEY_KINDS));
        if ($money === []) {
            return;
        }
        $q = $this->scoped(DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->whereIn('e.kind', $money));
        if ($p['amounts'] !== []) {
            $q->whereIn('e.amount_minor', $p['amounts']);
        }
        if ($p['dates'] !== []) {
            $q->whereIn('e.on_date', $p['dates']);
        }
        if ($p['words'] !== []) {
            $q->whereIn('e.tenant_id', $this->tenantsNamed($p['words']) ?: [0]);
        }
        $add($q->orderByDesc('e.on_date')->orderByDesc('e.id')->limit(self::CANDIDATES)->pluck('e.id'), 850);
    }

    /** @param array<string, mixed> $p @param list<string> $kinds */
    private function findByCode(array $p, array $kinds, callable $add): void
    {
        $query = $p['query'];
        $code = SearchText::code($query);
        if (strlen($code) >= 2 && (preg_match('/\d/', $code) === 1 || preg_match('/\s/', $query) !== 1)) {
            $add($this->keys($kinds)->where('k.term', 'c:'.$code)->pluck('k.entry_id'), 1000);
            $add($this->keys($kinds)->where('k.term', 'like', 'c:'.$code.'%')->pluck('k.entry_id'), 600);
        }
        if (ctype_digit($query)) {
            $number = ltrim($query, '0');
            if ($number !== '') {
                // A number read off paper without its letters is most often a receipt's.
                foreach ($this->keys($kinds)->where('k.term', 'n:'.$number)->addSelect('e.kind')->get() as $hit) {
                    $add([$hit->entry_id], $hit->kind === 'payment' ? 660 : 650);
                }
            }
            $money = array_values(array_intersect($kinds, self::MONEY_KINDS));
            if ($p['plain'] !== [] && $money !== []) {
                $add($this->scoped(DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->whereIn('e.kind', $money)->whereIn('e.amount_minor', $p['plain']))
                    ->orderByDesc('e.on_date')->orderByDesc('e.id')->limit(self::CANDIDATES)->pluck('e.id'), 620);
            }
        }
    }

    /** @param array<string, mixed> $p @param list<string> $kinds */
    private function findByPhone(array $p, array $kinds, callable $add): void
    {
        if (! SearchText::looksLikePhone($p['query'])) {
            return;
        }
        $digits = SearchText::digits($p['query']);
        $national = SearchText::national($p['query']);
        if (strlen($national) >= 3) {
            if (strlen($national) >= 7) {
                $add($this->keys($kinds)->where('k.term', 'p:'.$national)->pluck('k.entry_id'), 900);
                $add($this->keys($kinds)->where('k.term', 'q:'.$national)->pluck('k.entry_id'), 700);
            }
            $add($this->keys($kinds)->where('k.term', 'like', 'p:'.$national.'%')->pluck('k.entry_id'), 450);
        }
        if (strlen($digits) >= 4) {
            $add($this->keys($kinds)->where('k.term', 'like', 'r:'.strrev($digits).'%')->pluck('k.entry_id'), 400);
        }
    }

    /** An ID number, exact only, and only for those who manage tenants: anyone else could use the box to test whose number it is. @param array<string, mixed> $p @param list<string> $kinds */
    private function findById(array $p, array $kinds, callable $add): void
    {
        if (strlen(SearchText::code($p['query'])) < 5 || ! RentalAccess::can($this->by, 'rentals_manage') || ! in_array('tenant', $kinds, true)) {
            return;
        }
        $add($this->keys(['tenant'])->where('k.term', SearchIndex::idTerm($p['query']))->pluck('k.entry_id'), 950);
    }

    /** Every word must start a word of the record. @param array<string, mixed> $p @param list<string> $kinds */
    private function findByWords(array $p, array $kinds, callable $add): void
    {
        $words = array_slice($p['words'], 0, 6);
        if ($words === []) {
            return;
        }
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));
        $word = array_values(array_diff($kinds, ['payment']));
        if ($word !== []) {
            $q = $this->keys($word)->where('k.term', 'like', 'w:'.$words[0].'%');
            foreach (array_slice($words, 1) as $w) {
                $q->whereExists(fn (Builder $k) => $k->selectRaw('1')->from('rental_search_keys as k2')->whereColumn('k2.entry_id', 'k.entry_id')->where('k2.term', 'like', 'w:'.$w.'%'));
            }
            foreach ($q->addSelect('e.kind')->get() as $r) {
                $add([$r->entry_id], self::WORD_SCORES[$r->kind] ?? 200);
            }
        }
        // A person's receipts are found through the person: `receipts: nambi`.
        if ($kinds === ['payment'] && ($tenants = $this->tenantsNamed($p['words'])) !== []) {
            $add($this->scoped(DB::table('rental_search_entries as e')->where('e.company_id', $this->cid)->where('e.kind', 'payment')->whereIn('e.tenant_id', $tenants))
                ->orderByDesc('e.on_date')->orderByDesc('e.id')->limit(self::CANDIDATES)->pluck('e.id'), 240);
        }
    }

    /** Tenants whose name starts with these words. @param list<string> $words @return list<int> */
    private function tenantsNamed(array $words): array
    {
        $words = array_slice($words, 0, 6);
        if ($words === []) {
            return [];
        }
        $q = $this->keys(['tenant'])->where('k.term', 'like', 'w:'.$words[0].'%');
        foreach (array_slice($words, 1) as $w) {
            $q->whereExists(fn (Builder $k) => $k->selectRaw('1')->from('rental_search_keys as k2')->whereColumn('k2.entry_id', 'k.entry_id')->where('k2.term', 'like', 'w:'.$w.'%'));
        }
        $ids = $q->pluck('e.record_id');

        return $ids->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * Names that sound alike, or are a letter or two off. These show after every real match, marked `close`.
     *
     * @param  list<string>  $words
     * @param  list<string>  $kinds
     * @param  array<int, float>  $scores
     * @return array<int, true>
     */
    private function findClose(array $words, array $kinds, array &$scores): array
    {
        $kinds = array_values(array_intersect($kinds, ['tenant', 'landlord', 'property']));
        $words = array_values(array_filter(array_slice($words, 0, 6), fn ($w) => ! ctype_digit($w)));
        if ($kinds === [] || $words === []) {
            return [];
        }
        $termsOf = fn (string $w): array => array_values(array_filter([($s = SearchText::sound($w)) !== '' ? 's:'.$s : null, ($k = SearchText::skeleton($w)) !== '' ? 'k:'.$k : null]));
        $q = null;
        foreach ($words as $w) {
            $terms = $termsOf($w);
            if ($terms === []) {
                continue;
            }
            if ($q === null) {
                $q = $this->keys($kinds)->whereIn('k.term', $terms);
            } else {
                $q->whereExists(fn (Builder $k) => $k->selectRaw('1')->from('rental_search_keys as k2')->whereColumn('k2.entry_id', 'k.entry_id')->whereIn('k2.term', $terms));
            }
        }
        $close = [];
        foreach ($q?->pluck('k.entry_id') ?? [] as $id) {
            $id = (int) $id;
            if (! isset($scores[$id])) {
                $close[$id] = true;
                $scores[$id] = 5.0;
            }
        }
        if (count($scores) >= self::ENOUGH) {
            return $close;
        }
        foreach ($this->byTypos($words, $kinds) as $id => $distance) {
            if (! isset($scores[$id])) {
                $close[$id] = true;
                $scores[$id] = 4.0 - $distance;
            }
        }

        return $close;
    }

    /**
     * Names one letter off (two for a word of six letters or more). The words of the account that start with the same letter are few, so they are compared here and only the
     * near ones are looked up. Every typed word must be that near a word of the record's name.
     *
     * @param  list<string>  $words
     * @param  list<string>  $kinds
     * @return array<int, int> entry id => the largest distance among its words
     */
    private function byTypos(array $words, array $kinds): array
    {
        $words = array_values(array_filter($words, fn ($w) => strlen($w) >= 4));
        if ($words === []) {
            return [];
        }
        $found = null;
        foreach ($words as $word) {
            $near = [];
            foreach (DB::table('rental_search_keys')->where('company_id', $this->cid)->where('term', 'like', 'w:'.$word[0].'%')->distinct()->limit(3000)->pluck('term') as $term) {
                $d = levenshtein($word, substr($term, 2));
                if ($d <= (strlen($word) >= 6 ? 2 : 1)) {
                    $near[$term] = $d;
                }
            }
            if ($near === []) {
                return [];
            }
            $hits = [];
            foreach ($this->keys($kinds)->whereIn('k.term', array_keys($near))->addSelect('k.term', 'e.title')->get() as $row) {
                if (in_array(substr($row->term, 2), SearchText::words($row->title), true)) {
                    $hits[(int) $row->entry_id] = min($hits[(int) $row->entry_id] ?? 9, $near[$row->term]);
                }
            }
            if ($found === null) {
                $found = $hits;
            } else {
                $both = [];
                foreach ($hits as $id => $d) {
                    if (isset($found[$id])) {
                        $both[$id] = max($d, $found[$id]);
                    }
                }
                $found = $both;
            }
        }

        return $found;
    }

    /**
     * @param  Collection<int, \stdClass>  $entries
     * @param  array<int, float>  $scores
     * @param  array<int, true>  $close
     * @param  list<string>  $words
     * @return list<array{kind: string, label: string, total: int, results: list<array<string, mixed>>}>
     */
    private function group(Collection $entries, array $scores, array $close, array $words, int $perKind): array
    {
        $typed = implode(' ', $words);
        foreach ($entries as $e) {
            $id = (int) $e->id;
            if ($typed !== '' && ! isset($close[$id]) && in_array($e->kind, ['tenant', 'landlord', 'property', 'unit'], true)) {
                $joined = implode(' ', SearchText::words($e->title));
                if ($joined === $typed) {
                    $scores[$id] = max($scores[$id], 800.0);
                } elseif (str_starts_with($joined, $typed)) {
                    $scores[$id] = max($scores[$id], 500.0);
                }
            }
            $e->score = $scores[$id];
            $e->close = isset($close[$id]);
        }
        $money = $this->tenantMoney($entries->where('kind', 'tenant')->pluck('record_id')->map(fn ($v) => (int) $v)->all());
        foreach ($entries as $e) {
            $e->rank = [$e->close, -$e->score, $e->kind === 'tenant' ? -($money[(int) $e->record_id]['owed'] ?? 0) : 0, in_array($e->status, self::PAST_STATES, true),
                $e->on_date ? -(int) str_replace('-', '', (string) $e->on_date) : 0, $e->title];
        }
        $order = array_flip(array_keys(self::KINDS));
        $groups = [];
        foreach ($entries->sort(fn ($a, $b): int => $a->rank <=> $b->rank)->values()->groupBy('kind') as $kind => $all) {
            $top = $all->take($perKind)->values();
            $lines = $this->lines($kind, $top, $money);
            $results = [];
            foreach ($top as $e) {
                $line = $lines[(int) $e->record_id] ?? ['detail' => $e->subtitle, 'owes' => false];
                $api = self::KINDS[$kind][0];
                $results[] = ['kind' => $api, 'id' => (int) $e->record_id, 'title' => (string) $e->title, 'detail' => $line['detail'] ?: null, 'owes' => (bool) ($line['owes'] ?? false), 'close' => (bool) $e->close,
                    'link' => ['type' => $api, 'id' => (int) $e->record_id]];
            }
            $groups[] = ['kind' => self::KINDS[$kind][0], 'label' => self::KINDS[$kind][1], 'total' => $all->count(), 'results' => $results, '_best' => (float) ($all->first()->close ? 0 : $all->first()->score), '_order' => $order[$kind]];
        }
        usort($groups, fn ($a, $b) => [-$a['_best'], $a['_order']] <=> [-$b['_best'], $b['_order']]);

        return array_map(function ($g) {
            unset($g['_best'], $g['_order']);

            return $g;
        }, $groups);
    }

    /**
     * What each tenant owes on the live leases this person may see, with where they stay. Amounts from Leases::positions.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, array{owed: int, overdue: int, overdue_days: int, credit: int, place: ?string, leases: int}>
     */
    private function tenantMoney(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $leases = RentalAccess::scope(RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->whereIn('tenant_id', $tenantIds)->whereIn('status', RentalLease::LIVE), $this->by)->orderBy('id')->get();
        $positions = Leases::positions($leases->all());
        $units = DB::table('rental_units')->where('company_id', $this->cid)->whereIn('id', $leases->pluck('unit_id')->all() ?: [0])->pluck('code', 'id');
        $props = DB::table('rental_properties')->where('company_id', $this->cid)->whereIn('id', $leases->pluck('property_id')->all() ?: [0])->pluck('name', 'id');
        $out = [];
        foreach ($leases as $l) {
            $t = (int) $l->tenant_id;
            $m = $positions[(int) $l->id] ?? ['owed_minor' => 0, 'overdue_minor' => 0, 'overdue_days' => 0, 'credit_minor' => 0];
            $out[$t] ??= ['owed' => 0, 'overdue' => 0, 'overdue_days' => 0, 'credit' => 0, 'place' => null, 'leases' => 0];
            $out[$t]['place'] ??= trim(($units[$l->unit_id] ?? '').' '.($props[$l->property_id] ?? ''));
            $out[$t]['leases']++;
            $out[$t]['owed'] += (int) $m['owed_minor'];
            $out[$t]['overdue'] += (int) $m['overdue_minor'];
            $out[$t]['overdue_days'] = max($out[$t]['overdue_days'], (int) $m['overdue_days']);
            $out[$t]['credit'] += (int) $m['credit_minor'];
        }

        return $out;
    }

    /**
     * A result line that says what you came for: record id => {detail, owes}.
     *
     * @param  Collection<int, \stdClass>  $group
     * @param  array<int, array<string, mixed>>  $money
     * @return array<int, array{detail: ?string, owes?: bool}>
     */
    private function lines(string $kind, Collection $group, array $money): array
    {
        return match ($kind) {
            'tenant' => $this->tenantLines($group, $money),
            'unit' => $this->unitLines($group),
            'lease' => $this->leaseLines($group),
            'payment' => $this->paymentLines($group),
            'expense' => $this->expenseLines($group),
            'ticket' => $group->mapWithKeys(fn ($e) => [(int) $e->record_id => ['detail' => implode(' · ', array_filter([$e->subtitle, ['open' => 'Open', 'in_progress' => 'Being fixed', 'done' => 'Done', 'cancelled' => 'Cancelled'][$e->status] ?? null]))]])->all(),
            default => [],
        };
    }

    /** @param Collection<int, \stdClass> $group @param array<int, array<string, mixed>> $money @return array<int, array{detail: ?string, owes?: bool}> */
    private function tenantLines(Collection $group, array $money): array
    {
        $out = [];
        foreach ($group as $e) {
            $id = (int) $e->record_id;
            $m = $money[$id] ?? null;
            if ($m === null) {
                $out[$id] = ['detail' => $e->status === 'former' ? 'Former tenant' : 'No unit now'];

                continue;
            }
            $bits = [$m['place'].($m['leases'] > 1 ? ' and '.($m['leases'] - 1).' more' : '')];
            if ($m['owed'] > 0) {
                $bits[] = 'owes '.Minor::format($m['owed'], $this->cid);
                if ($m['overdue'] > 0) {
                    $bits[] = 'overdue '.$m['overdue_days'].' '.($m['overdue_days'] === 1 ? 'day' : 'days');
                }
            } else {
                $bits[] = $m['credit'] > 0 ? 'paid ahead '.Minor::format($m['credit'], $this->cid) : 'nothing owed';
            }
            $out[$id] = ['detail' => implode(' · ', $bits), 'owes' => $m['owed'] > 0];
        }

        return $out;
    }

    /** @param Collection<int, \stdClass> $group @return array<int, array{detail: ?string, owes?: bool}> */
    private function unitLines(Collection $group): array
    {
        $units = RentalUnit::withoutGlobalScopes()->where('company_id', $this->cid)->whereIn('id', $group->pluck('record_id')->all())->get()->keyBy('id');
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->whereIn('unit_id', $units->keys()->all() ?: [0])->whereIn('status', RentalLease::LIVE)->orderBy('id')->get()->keyBy('unit_id');
        $positions = Leases::positions($leases->values()->all());
        $tenants = DB::table('rental_tenants')->where('company_id', $this->cid)->whereIn('id', $leases->pluck('tenant_id')->all() ?: [0])->pluck('name', 'id');
        $words = ['vacant' => 'Vacant', 'reserved' => 'Reserved', 'maintenance' => 'Under repair', 'inactive' => 'Switched off', 'in_use' => 'In use'];
        $out = [];
        foreach ($group as $e) {
            $id = (int) $e->record_id;
            $u = $units->get($id);
            if ($u === null) {
                continue;
            }
            $lease = $leases->get($id);
            $bits = [(string) $e->subtitle];
            $owed = 0;
            if ($lease !== null) {
                $owed = (int) ($positions[(int) $lease->id]['owed_minor'] ?? 0);
                $bits[] = (string) ($tenants[$lease->tenant_id] ?? '');
                $bits[] = $owed > 0 ? 'owes '.Minor::format($owed, $this->cid) : 'nothing owed';
            } else {
                $bits[] = $words[Units::state($u, null)] ?? 'Vacant';
                if ((int) $u->rent_minor > 0) {
                    $bits[] = 'asks '.Minor::format((int) $u->rent_minor, $this->cid);
                }
            }
            $out[$id] = ['detail' => implode(' · ', array_filter($bits)), 'owes' => $owed > 0];
        }

        return $out;
    }

    /** @param Collection<int, \stdClass> $group @return array<int, array{detail: ?string, owes?: bool}> */
    private function leaseLines(Collection $group): array
    {
        $positions = Leases::positions($group->pluck('record_id')->map(fn ($v) => (int) $v)->all());
        $words = ['notice' => 'Under notice', 'ended' => 'Ended', 'cancelled' => 'Cancelled'];
        $out = [];
        foreach ($group as $e) {
            $id = (int) $e->record_id;
            $owes = (int) ($positions[$id]['owed_minor'] ?? 0);
            $bits = [(string) $e->subtitle, $words[$e->status] ?? null, $owes > 0 ? 'owes '.Minor::format($owes, $this->cid) : null];
            $out[$id] = ['detail' => implode(' · ', array_filter($bits)), 'owes' => $owes > 0];
        }

        return $out;
    }

    /** @param Collection<int, \stdClass> $group @return array<int, array{detail: ?string}> */
    private function paymentLines(Collection $group): array
    {
        $rows = DB::table('rental_payments as p')->leftJoin('rental_tenants as t', 't.id', '=', 'p.tenant_id')->leftJoin('rental_units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $this->cid)->whereIn('p.id', $group->pluck('record_id')->all())->get(['p.id', 'p.reference', 'p.amount_minor', 'p.paid_on', 'p.reversed_at', 't.name as tenant', 'u.code as unit']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = ['detail' => implode(' · ', array_filter([$r->tenant, $r->unit, Minor::format((int) $r->amount_minor, $this->cid), Carbon::parse($r->paid_on)->format('j M Y'), $r->reference, $r->reversed_at ? 'reversed' : null]))];
        }

        return $out;
    }

    /** @param Collection<int, \stdClass> $group @return array<int, array{detail: ?string}> */
    private function expenseLines(Collection $group): array
    {
        $out = [];
        foreach ($group as $e) {
            $out[(int) $e->record_id] = ['detail' => implode(' · ', array_filter([$e->subtitle, Minor::format((int) $e->amount_minor, $this->cid), $e->on_date ? Carbon::parse($e->on_date)->format('j M Y') : null, $e->status === 'reversed' ? 'reversed' : null]))];
        }

        return $out;
    }
}
