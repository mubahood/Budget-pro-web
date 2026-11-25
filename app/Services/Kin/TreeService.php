<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\KinLink;
use App\Models\KinSpouse;
use App\Models\SaccoMember;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * My Family Roots 360: a family's tree. People are sacco_members rows; kin_links joins a child to a parent and kin_spouses a
 * person to a spouse (another person, or a name only).
 *
 * - Guards (guardLink, guardSpouse), called by the models on every save from the API and from a phone's sync: no self-link,
 *   both people in the same family, no cycle (a person is never their own ancestor), at most one birth father and one birth
 *   mother; adoptive, step and guardian parents may be many. A child older than a parent is allowed, with a warning
 *   (warningsFor), since birth years are often guessed.
 * - Generations (recompute): founders (no parents in the tree) are 1, a child is one below its deepest parent (the largest
 *   parent generation + 1); a person who married in and has no parents takes their spouse's generation.
 *   root_id is the founder the person's line goes back to (father's side first). Worked out in PHP from one read of each
 *   table, never with a recursive query (MySQL 5.7). Only rows that changed are written, with a new server_seq so phones see it.
 * - The payload (tree) has an ETag; the API answers 304 to a phone that already has it.
 */
final class TreeService
{
    /** Birth parents are one of each; the others may be many. */
    public const SINGLE_KINDS = ['birth'];

    /** @var array<int, true> companies changed while a batch runs */
    private static array $pending = [];

    private static int $batching = 0;

    /** Run $fn and work the trees it changed out once at the end (a sync batch, the demo builder). @template T @param callable(): T $fn @return T */
    public static function batch(callable $fn): mixed
    {
        self::$batching++;
        try {
            $result = $fn();
        } finally {
            self::$batching--;
        }
        if (self::$batching === 0) {
            $pending = array_keys(self::$pending);
            self::$pending = [];
            foreach ($pending as $companyId) {
                self::recompute($companyId);
            }
        }

        return $result;
    }

    /** A link, marriage or person changed: work the tree out now, or at the end of the batch. */
    public static function changed(int $companyId): void
    {
        if (self::$batching > 0) {
            self::$pending[$companyId] = true;

            return;
        }
        self::recompute($companyId);
    }

    /** Is this account a family (the kin module)? */
    public static function isFamily(Company|int|null $company): bool
    {
        if ($company instanceof Company) {
            return in_array('kin', $company->modules(), true);
        }
        $modules = $company ? json_decode((string) DB::table('companies')->where('id', $company)->value('enabled_modules'), true) : null;

        return is_array($modules) && in_array('kin', $modules, true);
    }

    /** Does this family keep a purse (kin with sacco, while kin.purse_enabled)? */
    public static function hasPurse(Company $company): bool
    {
        return (bool) config('kin.purse_enabled', false) && in_array('kin', $company->modules(), true) && in_array('sacco', $company->modules(), true);
    }

    // ---------------------------------------------------------------- guards

    /** @throws BusinessRuleException */
    public static function guardLink(KinLink $link): void
    {
        $cid = (int) $link->company_id;
        $child = (int) $link->child_id;
        $parent = (int) $link->parent_id;
        if (! in_array($link->role, config('kin.link_roles'), true)) {
            throw BusinessRuleException::make('link_role', 'A parent is a father, a mother or a parent.', ['role' => config('kin.link_roles')]);
        }
        $link->kind = $link->kind ?: 'birth';
        if (! in_array($link->kind, config('kin.link_kinds'), true)) {
            throw BusinessRuleException::make('link_kind', 'A parent is by birth, adoptive, step or a guardian.', ['kind' => config('kin.link_kinds')]);
        }
        if ($child <= 0 || $parent <= 0) {
            throw BusinessRuleException::make('link_people', 'Choose the child and the parent.');
        }
        if ($child === $parent) {
            throw BusinessRuleException::make('link_self', 'A person cannot be their own parent.');
        }
        $found = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', [$child, $parent])->where('is_deleted', 0)->count();
        if ($found !== 2) {
            throw BusinessRuleException::make('not_in_family', 'Both people must be in this family.');
        }
        $others = KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when($link->exists, fn ($q) => $q->where('id', '!=', $link->id));
        if ((clone $others)->where('child_id', $child)->where('parent_id', $parent)->exists()) {
            throw BusinessRuleException::make('link_exists', 'That parent is already linked to this child.');
        }
        if ($link->kind === 'birth' && $link->role !== 'parent'
            && (clone $others)->where('child_id', $child)->where('kind', 'birth')->where('role', $link->role)->exists()) {
            throw BusinessRuleException::make('link_birth_parent', $link->role === 'father'
                ? 'This child already has a birth father. Add another as adoptive, step or guardian.'
                : 'This child already has a birth mother. Add another as adoptive, step or guardian.');
        }
        if ($link->kind === 'birth' && (clone $others)->where('child_id', $child)->where('kind', 'birth')->count() >= 2) {
            throw BusinessRuleException::make('link_birth_parent', 'This child already has two birth parents. Add another as adoptive, step or guardian.');
        }
        // A cycle: the new parent is the child, or one of the child's descendants.
        $children = [];
        foreach ((clone $others)->get(['child_id', 'parent_id']) as $l) {
            $children[(int) $l->parent_id][] = (int) $l->child_id;
        }
        $seen = [$child => true];
        $queue = [$child];
        while ($queue !== []) {
            $at = array_pop($queue);
            foreach ($children[$at] ?? [] as $c) {
                if ($c === $parent) {
                    throw BusinessRuleException::make('link_cycle', 'That would make a person their own ancestor.');
                }
                if (! isset($seen[$c])) {
                    $seen[$c] = true;
                    $queue[] = $c;
                }
            }
        }
    }

    /** @throws BusinessRuleException */
    public static function guardSpouse(KinSpouse $s): void
    {
        $cid = (int) $s->company_id;
        $s->status = $s->status ?: 'current';
        if (! in_array($s->status, config('kin.spouse_statuses'), true)) {
            throw BusinessRuleException::make('spouse_status', 'A marriage is current, ended or widowed.', ['status' => config('kin.spouse_statuses')]);
        }
        $spouseId = $s->spouse_id ? (int) $s->spouse_id : null;
        if ($spouseId === null) {
            $s->spouse_name = trim((string) $s->spouse_name);
            if ($s->spouse_name === '') {
                throw BusinessRuleException::make('spouse_required', 'Choose the spouse from the family, or give their name.');
            }
        } else {
            $s->spouse_name = null;
        }
        if ($spouseId === (int) $s->person_id) {
            throw BusinessRuleException::make('link_self', 'A person cannot marry themselves.');
        }
        $ids = array_values(array_filter([(int) $s->person_id, $spouseId]));
        if (SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids)->where('is_deleted', 0)->count() !== count($ids)) {
            throw BusinessRuleException::make('not_in_family', 'Both people must be in this family.');
        }
        if ($spouseId !== null) {
            $twice = KinSpouse::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->when($s->exists, fn ($q) => $q->where('id', '!=', $s->id))
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('person_id', $s->person_id)->where('spouse_id', $spouseId))
                    ->orWhere(fn ($w) => $w->where('person_id', $spouseId)->where('spouse_id', $s->person_id)))->exists();
            if ($twice) {
                throw BusinessRuleException::make('spouse_exists', 'These two are already married in the tree.');
            }
            if (KinLink::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->where('kind', 'birth')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('child_id', $s->person_id)->where('parent_id', $spouseId))
                    ->orWhere(fn ($w) => $w->where('child_id', $spouseId)->where('parent_id', $s->person_id)))->exists()) {
                throw BusinessRuleException::make('spouse_parent', 'A parent and their child cannot be married in the tree.');
            }
        }
        if ($s->married_on && $s->ended_on && Carbon::parse($s->ended_on)->lt(Carbon::parse($s->married_on))) {
            throw BusinessRuleException::make('spouse_dates', 'A marriage cannot end before it began.');
        }
    }

    /** Warnings that do not stop a link: the child is older than the parent. @return list<array{code: string, message: string}> */
    public static function warningsFor(KinLink $link): array
    {
        $dob = SaccoMember::withoutGlobalScopes()->where('company_id', $link->company_id)->whereIn('id', [$link->child_id, $link->parent_id])->pluck('date_of_birth', 'id');
        $child = $dob[$link->child_id] ?? null;
        $parent = $dob[$link->parent_id] ?? null;
        if ($child && $parent && Carbon::parse($child)->lte(Carbon::parse($parent))) {
            return [['code' => 'child_older_than_parent', 'message' => 'The child was born before the parent. Check the birth dates.']];
        }

        return [];
    }

    /** Validation of a link a phone pushes (SyncRegistry kin_links). @return array<string, array<int, mixed>> */
    public static function linkSyncRules(int $companyId, ?int $ignoreId): array
    {
        return ['role' => [$ignoreId === null ? 'required' : 'sometimes', 'in:'.implode(',', config('kin.link_roles'))],
            'kind' => ['sometimes', 'in:'.implode(',', config('kin.link_kinds'))], 'notes' => ['nullable', 'string', 'max:255']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function spouseSyncRules(int $companyId, ?int $ignoreId): array
    {
        return ['spouse_name' => ['nullable', 'string', 'max:150'], 'status' => ['sometimes', 'in:'.implode(',', config('kin.spouse_statuses'))],
            'married_on' => ['nullable', 'date'], 'ended_on' => ['nullable', 'date'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:50'], 'notes' => ['nullable', 'string', 'max:255']];
    }

    // ---------------------------------------------------------------- relatives

    /**
     * A person's parents, children, siblings (full when they share both birth parents, else half) and spouses.
     *
     * @return array{parents: list<array<string, mixed>>, children: list<array<string, mixed>>, siblings: list<array<string, mixed>>, spouses: list<array<string, mixed>>}
     */
    public static function relatives(int $companyId, int $personId): array
    {
        $links = KinLink::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->where(fn ($q) => $q->where('child_id', $personId)->orWhere('parent_id', $personId))->get();
        $parents = $links->where('child_id', $personId)->values();
        $children = $links->where('parent_id', $personId)->values();
        $parentIds = $parents->pluck('parent_id')->map(fn ($id) => (int) $id)->all();
        $birthParents = $parents->where('kind', 'birth')->pluck('parent_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $siblingLinks = $parentIds === [] ? collect() : KinLink::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->whereIn('parent_id', $parentIds)->where('child_id', '!=', $personId)->get();
        $spouses = KinSpouse::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->where(fn ($q) => $q->where('person_id', $personId)->orWhere('spouse_id', $personId))->orderBy('sort_order')->orderBy('id')->get();

        $ids = array_unique(array_merge($parentIds, $children->pluck('child_id')->all(), $siblingLinks->pluck('child_id')->all(),
            $spouses->pluck('person_id')->all(), $spouses->pluck('spouse_id')->filter()->all()));
        $people = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $brief = fn (int $id) => ($p = $people->get($id)) ? self::brief($p) : ['id' => $id];

        $siblings = [];
        foreach ($siblingLinks->groupBy('child_id') as $sid => $rows) {
            $theirBirth = KinLink::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->where('child_id', $sid)->where('kind', 'birth')
                ->pluck('parent_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $shared = $rows->pluck('parent_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $full = count($birthParents) === 2 && $theirBirth === $birthParents;
            $siblings[] = $brief((int) $sid) + ['relation' => $full ? 'full' : ($rows->every(fn ($r) => $r->kind === 'birth') ? 'half' : 'step'), 'shared_parent_ids' => $shared];
        }

        return [
            'parents' => $parents->map(fn ($l) => $brief((int) $l->parent_id) + ['link_id' => (int) $l->id, 'link_uuid' => $l->uuid, 'role' => $l->role, 'kind' => $l->kind])->values()->all(),
            'children' => $children->map(fn ($l) => $brief((int) $l->child_id) + ['link_id' => (int) $l->id, 'link_uuid' => $l->uuid, 'role' => $l->role, 'kind' => $l->kind])->values()->all(),
            'siblings' => $siblings,
            'spouses' => $spouses->map(function ($s) use ($personId, $brief) {
                $other = (int) $s->person_id === $personId ? ($s->spouse_id ? (int) $s->spouse_id : null) : (int) $s->person_id;

                // status is always the person's own (living, late), as for every relative; the marriage's is marriage_status.
                return ($other ? $brief($other) : ['id' => null, 'name' => $s->spouse_name, 'status' => null]) + ['spouse_link_id' => (int) $s->id, 'marriage_status' => $s->status,
                    'married_on' => $s->married_on?->toDateString(), 'ended_on' => $s->ended_on?->toDateString(), 'sort_order' => (int) $s->sort_order];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function brief(SaccoMember $p): array
    {
        return ['id' => (int) $p->id, 'uuid' => $p->uuid, 'name' => $p->full_name, 'gender' => $p->gender, 'status' => $p->status,
            'date_of_birth' => $p->date_of_birth?->toDateString(), 'generation' => $p->generation !== null ? (int) $p->generation : null];
    }

    // ---------------------------------------------------------------- generations

    /**
     * Work out every person's generation and root in the family, and write the ones that changed.
     *
     * @return array{people: int, changed: int}
     */
    public static function recompute(int $companyId): array
    {
        if (! self::isFamily($companyId)) {
            return ['people' => 0, 'changed' => 0];
        }
        $people = DB::table('sacco_members')->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->get(['id', 'generation', 'root_id'])->keyBy('id');
        $links = DB::table('kin_links')->where('company_id', $companyId)->where('is_deleted', 0)->get(['child_id', 'parent_id', 'role', 'kind']);
        $spouses = DB::table('kin_spouses')->where('company_id', $companyId)->where('is_deleted', 0)->whereNotNull('spouse_id')->get(['person_id', 'spouse_id']);

        [$generation, $root] = self::solve(array_map('intval', $people->keys()->all()), $links->all(), $spouses->all());

        $changed = [];
        foreach ($people as $id => $p) {
            $g = $generation[$id] ?? 1;
            $r = $root[$id] ?? (int) $id;
            if ((int) $p->generation !== $g || (int) $p->root_id !== $r || $p->generation === null) {
                $changed[(int) $id] = [$g, $r];
            }
        }
        if ($changed !== []) {
            $seq = SyncSequence::reserve(count($changed));
            foreach (array_chunk($changed, 500, true) as $chunk) {
                $ids = array_keys($chunk);
                $gen = $rt = $sq = '';
                $bindings = ['g' => [], 'r' => [], 's' => []];
                foreach ($chunk as $id => [$g, $r]) {
                    $gen .= ' WHEN ? THEN ?';
                    $rt .= ' WHEN ? THEN ?';
                    $sq .= ' WHEN ? THEN ?';
                    array_push($bindings['g'], $id, $g);
                    array_push($bindings['r'], $id, $r);
                    array_push($bindings['s'], $id, $seq++);
                }
                DB::update('UPDATE sacco_members SET generation = CASE id'.$gen.' END, root_id = CASE id'.$rt.' END, server_seq = CASE id'.$sq.' END, version = version + 1'
                    .' WHERE company_id = ? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                    array_merge($bindings['g'], $bindings['r'], $bindings['s'], [$companyId], $ids));
            }
        }

        return ['people' => $people->count(), 'changed' => count($changed)];
    }

    /**
     * The pure part of recompute: generation and root per person.
     *
     * @param  list<int>  $ids
     * @param  list<object{child_id: int, parent_id: int, role: string, kind: string}>  $links
     * @param  list<object{person_id: int, spouse_id: int}>  $spouses
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    public static function solve(array $ids, array $links, array $spouses): array
    {
        $known = array_fill_keys($ids, true);
        $isFather = [];
        $parents = [];
        $children = [];
        $indegree = array_fill_keys($ids, 0);
        foreach ($links as $l) {
            $c = (int) $l->child_id;
            $p = (int) $l->parent_id;
            if (! isset($known[$c], $known[$p])) {
                continue;
            }
            $parents[$c][] = ['id' => $p, 'rank' => self::rank((string) $l->role, (string) $l->kind)];
            if ((string) $l->role === 'father') {
                $isFather[$p] = true;
            }
            $children[$p][] = $c;
            $indegree[$c]++;
        }
        $spouseOf = [];
        foreach ($spouses as $s) {
            $a = (int) $s->person_id;
            $b = (int) $s->spouse_id;
            if (isset($known[$a], $known[$b])) {
                $spouseOf[$a][] = $b;
                $spouseOf[$b][] = $a;
            }
        }

        // Kahn's order from the founders down (the guards keep it free of cycles; anything left over is treated as a founder).
        $generation = [];
        $root = [];
        $queue = [];
        foreach ($indegree as $id => $n) {
            if ($n === 0) {
                $queue[] = $id;
            }
        }
        $order = [];
        $placed = [];
        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            $order[] = $id;
            $placed[$id] = true;
            foreach ($children[$id] ?? [] as $c) {
                if (--$indegree[$c] === 0) {
                    $queue[] = $c;
                }
            }
        }
        foreach ($ids as $id) {
            if (! isset($placed[$id])) {
                $order[] = $id; // never reached (a cycle the guards would have refused)
            }
        }

        // Founders first pass: people without parents wait for their spouse when the spouse has a line.
        $withoutParents = [];
        foreach ($order as $id) {
            $ps = array_filter($parents[$id] ?? [], fn ($p) => isset($generation[$p['id']]));
            if ($ps === []) {
                $withoutParents[] = $id;
                $generation[$id] = 1;
                $root[$id] = $id;

                continue;
            }
            $max = 0;
            foreach ($ps as $p) {
                $max = max($max, $generation[$p['id']]);
            }
            usort($ps, fn ($a, $b) => [$a['rank'], $a['id']] <=> [$b['rank'], $b['id']]);
            $generation[$id] = $max + 1;
            $root[$id] = $root[$ps[0]['id']];
        }

        // Married in: a person with no parents whose spouse has a line takes the spouse's generation and root. Their own
        // children were placed from them at 1; so when any moves, the line below them is worked out again.
        $moved = false;
        foreach ($withoutParents as $id) {
            $best = null;
            foreach ($spouseOf[$id] ?? [] as $s) {
                if (($parents[$s] ?? []) !== [] && ($best === null || $generation[$s] < $generation[$best] || ($generation[$s] === $generation[$best] && $s < $best))) {
                    $best = $s;
                }
            }
            if ($best !== null && ($generation[$id] !== $generation[$best] || $root[$id] !== $root[$best])) {
                $generation[$id] = $generation[$best];
                $root[$id] = $root[$best];
                $moved = true;
            }
        }
        // A founding couple (neither has parents in the book): the wife's line is her husband's, the one named as father.
        foreach ($withoutParents as $id) {
            if (isset($isFather[$id]) || $root[$id] !== $id) {
                continue;
            }
            foreach ($spouseOf[$id] ?? [] as $s) {
                if (($parents[$s] ?? []) === [] && isset($isFather[$s])) {
                    $root[$id] = $root[$s];
                    $moved = true;
                    break;
                }
            }
        }
        if ($moved) {
            foreach ($order as $id) {
                $ps = $parents[$id] ?? [];
                if ($ps === []) {
                    continue;
                }
                $max = 0;
                foreach ($ps as $p) {
                    $max = max($max, $generation[$p['id']] ?? 1);
                }
                usort($ps, fn ($a, $b) => [$a['rank'], $a['id']] <=> [$b['rank'], $b['id']]);
                $generation[$id] = $max + 1;
                $root[$id] = $root[$ps[0]['id']] ?? $ps[0]['id'];
            }
        }

        return [$generation, $root];
    }

    /** Which parent a line follows for its root: birth father, birth mother, birth parent, then the others. */
    private static function rank(string $role, string $kind): int
    {
        return ($kind === 'birth' ? 0 : 10) + match ($role) {
            'father' => 0,
            'mother' => 1,
            default => 2,
        };
    }

    // ---------------------------------------------------------------- the whole tree

    /**
     * Everything a phone needs to draw the tree, with an ETag that changes whenever any of it does.
     *
     * @return array{etag: string, data: array<string, mixed>}
     */
    public static function payload(int $companyId): array
    {
        $people = DB::table('sacco_members')->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->orderBy('generation')->orderBy('date_of_birth')->orderBy('id')
            ->get(['id', 'uuid', 'first_name', 'last_name', 'other_names', 'gender', 'status', 'date_of_birth', 'birth_date_precision', 'date_of_death',
                'branch_id', 'generation', 'root_id', 'photo_file_id', 'server_seq']);
        $links = DB::table('kin_links')->where('company_id', $companyId)->where('is_deleted', 0)->orderBy('id')
            ->get(['id', 'uuid', 'child_id', 'parent_id', 'role', 'kind', 'server_seq']);
        $spouses = DB::table('kin_spouses')->where('company_id', $companyId)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'uuid', 'person_id', 'spouse_id', 'spouse_name', 'status', 'married_on', 'ended_on', 'sort_order', 'server_seq']);
        $branches = DB::table('kin_branches')->where('company_id', $companyId)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'uuid', 'name', 'founder_id', 'server_seq']);

        // Deleted rows count too: a removal moves the newest server_seq on.
        $seq = max(array_map(fn (string $t) => (int) DB::table($t)->where('company_id', $companyId)->max('server_seq'), ['sacco_members', 'kin_links', 'kin_spouses', 'kin_branches']));
        $etag = '"kin-'.$companyId.'-'.$seq.'-'.$people->count().'-'.$links->count().'-'.$spouses->count().'-'.$branches->count().'"';
        $strip = fn ($rows) => $rows->map(function ($r) {
            $a = (array) $r;
            unset($a['server_seq']);

            return $a;
        })->values()->all();
        $people = $people->map(function ($p) {
            $p->name = trim(implode(' ', array_filter([$p->first_name, $p->other_names, $p->last_name])));
            $p->generation = $p->generation !== null ? (int) $p->generation : null;

            return $p;
        });

        return ['etag' => $etag, 'data' => [
            'people' => $strip($people), 'links' => $strip($links), 'spouses' => $strip($spouses), 'branches' => $strip($branches),
            'generations' => (int) ($people->max('generation') ?? 0), 'server_seq' => $seq,
        ]];
    }
}
