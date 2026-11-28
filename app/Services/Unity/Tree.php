<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The organisation tree: the group, its regions, chapters and committees, and the members in them. One level at a time
 * (node), the whole skeleton for offline use (outline), and a search that gives each hit's path.
 *
 * count is the current members under a node (filters status, category_id and paid_up change which members count); a
 * committee counts its serving officials. A region's or chapter's health is the share of its members paid up and its
 * attendance over held meetings of the last 90 days; tone is good when the average of the two known values is 70 or more,
 * fair from 50, weak below.
 */
final class Tree
{
    /** @param array<string, mixed> $filters */
    public static function members(int $cid, array $filters): Builder
    {
        $f = array_intersect_key($filters, array_flip(['status', 'category_id', 'paid_up']));
        if (($f['status'] ?? '') === '') {
            $f['status'] = 'current';
        }

        return MemberFilters::apply(MemberFilters::base($cid), $cid, $f);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{node: array<string, mixed>, children: list<array<string, mixed>>, total: int}
     */
    public static function node(int $cid, string $key, array $filters, int $page, int $perPage): array
    {
        $ctx = self::context($cid, $filters);
        [$kind, $id] = self::parse($key);
        $offset = ($page - 1) * $perPage;

        if ($kind === 'org') {
            $rows = [];
            foreach ($ctx['places']->where('kind', 'region') as $r) {
                $rows[] = self::placeRow($r, $ctx);
            }
            foreach ($ctx['places']->where('kind', 'chapter')->whereNull('parent_id') as $c) {
                $rows[] = self::placeRow($c, $ctx);
            }
            foreach ($ctx['committees']->whereNull('chapter_id') as $c) {
                $rows[] = self::committeeRow($c, $ctx);
            }
            $unassigned = (int) ($ctx['counts'][0]->n ?? 0);
            if ($unassigned > 0) {
                $rows[] = ['key' => 'unassigned', 'kind' => 'unassigned', 'id' => null, 'name' => 'No chapter yet', 'count' => $unassigned];
            }
            $node = ['key' => 'org', 'kind' => 'org', 'name' => (string) DB::table('companies')->where('id', $cid)->value('name'), 'count' => (int) $ctx['counts']->sum('n')];

            return ['node' => $node, 'children' => array_slice($rows, $offset, $perPage), 'total' => count($rows)];
        }

        if ($kind === 'region') {
            $r = $ctx['places']->where('kind', 'region')->firstWhere('id', $id) ?? self::missing();
            $rows = [];
            foreach ($ctx['places']->where('parent_id', $id) as $c) {
                $rows[] = self::placeRow($c, $ctx);
            }
            foreach ($ctx['committees']->where('chapter_id', $id) as $c) {
                $rows[] = self::committeeRow($c, $ctx);
            }

            return ['node' => self::nodeOf(self::placeRow($r, $ctx)), 'children' => array_slice($rows, $offset, $perPage), 'total' => count($rows)];
        }

        if ($kind === 'committee') {
            $c = $ctx['committees']->firstWhere('id', $id) ?? self::missing();
            $q = UnityOffices::serving($cid)->join('sacco_members AS m', 'm.id', '=', 'cm.member_id')->where('cm.committee_id', $id);
            $total = (clone $q)->count();
            $rows = $q->orderBy('cm.sort_order')->orderBy('m.last_name')->orderBy('m.first_name')->offset($offset)->limit($perPage)
                ->get(['m.id', 'm.first_name', 'm.last_name', 'm.other_names', 'm.member_no', 'm.photo_file_id', 'm.standing', 'm.membership_ends_on', 'm.status', 'cm.title']);

            return ['node' => self::nodeOf(self::committeeRow($c, $ctx)), 'children' => self::memberRows($cid, $rows), 'total' => $total];
        }

        // chapter or unassigned: committees first (a chapter's), then its members, officials first with their title.
        $structural = [];
        if ($kind === 'chapter') {
            $c = $ctx['places']->where('kind', 'chapter')->firstWhere('id', $id) ?? self::missing();
            foreach ($ctx['committees']->where('chapter_id', $id) as $cm) {
                $structural[] = self::committeeRow($cm, $ctx);
            }
            $node = self::nodeOf(self::placeRow($c, $ctx));
            $titles = UnityOffices::serving($cid)->where('c.chapter_id', $id)->orderBy('c.sort_order')->orderBy('cm.sort_order')
                ->get(['cm.member_id', 'cm.title'])->unique('member_id')->pluck('title', 'member_id')->all();
        } else {
            $node = ['key' => 'unassigned', 'kind' => 'unassigned', 'name' => 'No chapter yet', 'count' => (int) ($ctx['counts'][0]->n ?? 0)];
            $titles = [];
        }
        $members = self::members($cid, $filters)->when($kind === 'chapter', fn ($q) => $q->where('chapter_id', $id), fn ($q) => $q->whereNull('chapter_id'));
        $memberTotal = (clone $members)->count();
        $children = array_slice($structural, $offset, $perPage);
        $need = $perPage - count($children);
        if ($need > 0) {
            $officials = array_map('intval', array_keys($titles));
            $rows = $members->when($officials !== [], fn ($q) => $q->orderByRaw('CASE WHEN id IN ('.implode(',', $officials).') THEN 0 ELSE 1 END'))
                ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->offset(max(0, $offset - count($structural)))->limit($need)
                ->get(['id', 'first_name', 'last_name', 'other_names', 'member_no', 'photo_file_id', 'standing', 'membership_ends_on', 'status']);
            foreach ($rows as $r) {
                $r->title = $titles[(int) $r->id] ?? null;
            }
            $children = [...$children, ...self::memberRows($cid, $rows)];
        }

        return ['node' => $node, 'children' => $children, 'total' => count($structural) + $memberTotal];
    }

    /** Every region, chapter and committee with its parent and count. @return list<array<string, mixed>> */
    public static function outline(int $cid): array
    {
        $ctx = self::context($cid, []);
        $out = [];
        foreach ($ctx['places']->sortBy(fn ($p) => $p->kind === 'region' ? 0 : 1) as $p) {
            $out[] = self::placeRow($p, $ctx) + ['parent_key' => $p->parent_id ? 'region:'.$p->parent_id : 'org'];
        }
        foreach ($ctx['committees'] as $c) {
            $parent = $c->chapter_id ? $ctx['places']->firstWhere('id', $c->chapter_id) : null;
            $out[] = self::committeeRow($c, $ctx) + ['parent_key' => $parent ? $parent->kind.':'.$parent->id : 'org', 'health' => null];
        }

        return array_map(fn ($r) => ['key' => $r['key'], 'kind' => $r['kind'], 'id' => $r['id'], 'name' => $r['name'], 'parent_key' => $r['parent_key'],
            'count' => $r['count'], 'health' => $r['health'] ?? null], $out);
    }

    /** Up to 20 places, committees and members matching q, each with its path from the top. @return list<array<string, mixed>> */
    public static function find(int $cid, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $places = DB::table('unity_chapters')->where('company_id', $cid)->where('is_deleted', 0)->get(['id', 'name', 'kind', 'parent_id', 'code'])->keyBy('id');
        $path = function (?int $chapterId) use ($places): array {
            $p = ['org'];
            $c = $chapterId ? $places->get($chapterId) : null;
            if ($c?->parent_id) {
                $p[] = 'region:'.$c->parent_id;
            }
            if ($c) {
                $p[] = $c->kind.':'.$c->id;
            }

            return $p;
        };
        $out = [];
        foreach ($places->filter(fn ($p) => mb_stripos($p->name, $q) !== false || ($p->code && mb_stripos($p->code, $q) !== false))->take(20) as $p) {
            $out[] = ['key' => $p->kind.':'.$p->id, 'kind' => $p->kind, 'name' => $p->name, 'path' => array_slice($path((int) $p->id), 0, -1)];
        }
        foreach (DB::table('unity_committees')->where('company_id', $cid)->where('is_deleted', 0)->where('name', 'like', $like)->limit(20)->get(['id', 'name', 'chapter_id']) as $c) {
            $out[] = ['key' => 'committee:'.$c->id, 'kind' => 'committee', 'name' => $c->name, 'path' => $path($c->chapter_id ? (int) $c->chapter_id : null)];
        }
        $members = MemberFilters::base($cid)->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
            ->orWhere('other_names', 'like', $like)->orWhere('member_no', 'like', $like)->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like]))
            ->orderByRaw("FIELD(status, 'active', 'dormant') = 0")->orderBy('last_name')->orderBy('first_name')->limit(20)
            ->get(['id', 'first_name', 'last_name', 'other_names', 'member_no', 'chapter_id']);
        foreach ($members as $m) {
            $p = $path($m->chapter_id ? (int) $m->chapter_id : null);
            if (! $m->chapter_id) {
                $p[] = 'unassigned';
            }
            $out[] = ['key' => 'member:'.$m->id, 'kind' => 'member', 'name' => Insights::name($m), 'member_no' => $m->member_no, 'path' => $p];
        }

        return array_slice($out, 0, 20);
    }

    /** @return array{0: string, 1: ?int} */
    public static function parse(string $key): array
    {
        if ($key === 'org' || $key === '' || $key === 'unassigned') {
            return [$key === 'unassigned' ? 'unassigned' : 'org', null];
        }
        if (preg_match('/^(region|chapter|committee):(\d+)$/', $key, $m)) {
            return [$m[1], (int) $m[2]];
        }
        throw BusinessRuleException::make('validation', 'Unknown node.', ['node' => ['Use org, region:{id}, chapter:{id}, committee:{id} or unassigned.']]);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private static function context(int $cid, array $filters): array
    {
        $today = LocalDate::today($cid);
        [$paidSql, $paidArgs] = Insights::paidUpSql($today);
        $counts = self::members($cid, $filters)->selectRaw("COALESCE(chapter_id, 0) AS chapter_id, COUNT(*) AS n, SUM(CASE WHEN {$paidSql} THEN 1 ELSE 0 END) AS paid", $paidArgs)
            ->groupBy(DB::raw('COALESCE(chapter_id, 0)'))->get()->keyBy('chapter_id');
        $places = DB::table('unity_chapters')->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'kind', 'parent_id', 'code']);
        $committees = DB::table('unity_committees')->where('company_id', $cid)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'chapter_id']);
        $officials = UnityOffices::serving($cid)->groupBy('cm.committee_id')->selectRaw('cm.committee_id, COUNT(*) AS n')->pluck('n', 'committee_id');

        return ['places' => $places, 'committees' => $committees, 'counts' => $counts, 'officials' => $officials,
            'attendance' => Insights::attendanceByChapter($cid, $today->copy()->subDays(89), $today)];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private static function placeRow(object $p, array $ctx): array
    {
        $ids = $p->kind === 'region' ? $ctx['places']->where('parent_id', $p->id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [(int) $p->id];
        $n = 0;
        $paid = 0;
        $present = 0;
        $marked = 0;
        foreach ($ids as $id) {
            $n += (int) ($ctx['counts'][$id]->n ?? 0);
            $paid += (int) ($ctx['counts'][$id]->paid ?? 0);
            $present += $ctx['attendance'][$id]['present'] ?? 0;
            $marked += $ctx['attendance'][$id]['marked'] ?? 0;
        }

        return ['key' => $p->kind.':'.$p->id, 'kind' => $p->kind, 'id' => (int) $p->id, 'name' => $p->name, 'code' => $p->code, 'count' => $n,
            'health' => self::health($n > 0 ? round($paid * 100 / $n, 1) : null, $marked > 0 ? round($present * 100 / $marked, 1) : null)];
    }

    /** @return array{paid_up_pct: ?float, attendance_pct: ?float, tone: ?string} */
    public static function health(?float $paidUp, ?float $attendance): array
    {
        $known = array_filter([$paidUp, $attendance], fn ($v) => $v !== null);
        $avg = $known === [] ? null : array_sum($known) / count($known);

        return ['paid_up_pct' => $paidUp, 'attendance_pct' => $attendance, 'tone' => $avg === null ? null : ($avg >= 70 ? 'good' : ($avg >= 50 ? 'fair' : 'weak'))];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private static function committeeRow(object $c, array $ctx): array
    {
        return ['key' => 'committee:'.$c->id, 'kind' => 'committee', 'id' => (int) $c->id, 'name' => $c->name, 'count' => (int) ($ctx['officials'][$c->id] ?? 0)];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function nodeOf(array $row): array
    {
        return array_intersect_key($row, array_flip(['key', 'kind', 'id', 'name', 'code', 'count', 'health']));
    }

    /** @return list<array<string, mixed>> */
    private static function memberRows(int $cid, iterable $rows): array
    {
        $rows = collect($rows);
        $photos = UnityLinks::photos($cid, $rows->pluck('photo_file_id')->filter()->all());
        $today = LocalDate::today($cid);

        return $rows->map(fn ($r) => ['key' => 'member:'.$r->id, 'kind' => 'member', 'id' => (int) $r->id, 'name' => Insights::name($r), 'count' => 0,
            'title' => $r->title ?? null, 'member_no' => $r->member_no, 'photo_url' => $r->photo_file_id ? ($photos[(int) $r->photo_file_id] ?? null) : null,
            'standing' => Memberships::standingOf($r, $today), 'status' => $r->status])->values()->all();
    }

    private static function missing(): never
    {
        throw BusinessRuleException::make('not_found', 'That part of the organisation was not found.');
    }
}
