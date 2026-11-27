<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\UnityCommittee;
use App\Models\UnityCommitteeMember;
use App\Services\Unity\Insights;
use App\Services\Unity\UnityMembers;
use App\Services\Unity\UnityOffices;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Committees (the Executive Committee, a chapter's committee) and their officials with titles. Reads unity_view, writes
 * unity_chapters. An official who steps down gets an end date (history stays); a committee with serving officials is kept.
 */
class CommitteesController extends UnityController
{
    /** GET unity/committees?chapter_id= : each with its serving officials. */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $rows = UnityCommittee::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when($request->filled('chapter_id'), fn ($q) => $q->where('chapter_id', (int) $request->query('chapter_id')))
            ->orderByRaw('chapter_id IS NOT NULL')->orderBy('chapter_id')->orderBy('sort_order')->orderBy('name')->get();
        $officials = $this->officials($cid, $rows->pluck('id')->all());

        return $this->success($rows->map(fn ($c) => self::shape($c) + ['officials' => $officials[(int) $c->id] ?? []])->values()->all(), 'Listed successfully.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $c = $this->find($request, $id);

            return $this->success(self::shape($c) + ['officials' => $this->officials($this->cid($request), [(int) $c->id])[(int) $c->id] ?? []]);
        });
    }

    /** POST unity/committees {name, chapter_id?, sort_order?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'chapter_id' => ['sometimes', 'nullable', 'integer'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']]);

        return $this->attempt(function () use ($request, $d) {
            $this->checkChapter($request, $d['chapter_id'] ?? null);
            $c = UnityCommittee::create($d + ['company_id' => $this->cid($request)]);

            return $this->created(self::shape($c->fresh()) + ['officials' => []], 'Committee added.');
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'chapter_id' => ['sometimes', 'nullable', 'integer'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $c = $this->find($request, $id);
            if (array_key_exists('chapter_id', $d)) {
                $this->checkChapter($request, $d['chapter_id']);
            }
            $c->fill($d)->save();

            return $this->success(self::shape($c->fresh()), 'Saved.');
        });
    }

    /** DELETE unity/committees/{id}: only with no serving officials (409 committee_in_use). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $c = $this->find($request, $id);
            $n = UnityOffices::serving($this->cid($request))->where('cm.committee_id', $c->id)->count();
            if ($n > 0) {
                throw BusinessRuleException::make('committee_in_use', "This committee has {$n} serving ".($n === 1 ? 'official' : 'officials').'. End their terms first.');
            }
            $c->is_deleted = true;
            $c->save();

            return $this->success(null, 'Committee removed.');
        });
    }

    /** POST unity/committees/{id}/officials {member_id, title, sort_order?, starts_on?} */
    public function addOfficial(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:60'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $c = $this->find($request, $id);
            $m = UnityMembers::find($cid, (int) $d['member_id']);
            if (! in_array($m->status, UnityMembers::CURRENT, true)) {
                throw BusinessRuleException::make('validation', 'Only a current member holds an office.', ['member_id' => ['Not a current member.']]);
            }
            if (UnityOffices::serving($cid)->where('cm.committee_id', $c->id)->where('cm.member_id', $m->id)->exists()) {
                throw BusinessRuleException::make('validation', 'This member already serves on this committee.', ['member_id' => ['Already serving.']]);
            }
            UnityCommitteeMember::create(['company_id' => $cid, 'committee_id' => $c->id, 'member_id' => $m->id, 'title' => trim($d['title']),
                'sort_order' => $d['sort_order'] ?? 0, 'starts_on' => $d['starts_on'] ?? LocalDate::today($cid)->toDateString()]);

            return $this->created(self::shape($c) + ['officials' => $this->officials($cid, [(int) $c->id])[(int) $c->id] ?? []], 'Official added.');
        });
    }

    /** POST unity/committees/{id}/officials/{memberId}/end {on?}: the term ends (yesterday by default, so they stop serving today). */
    public function endOfficial(Request $request, int $id, int $memberId): JsonResponse
    {
        $d = $request->validate(['on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($request, $id, $memberId, $d) {
            $cid = $this->cid($request);
            $c = $this->find($request, $id);
            $today = LocalDate::today($cid)->toDateString();
            $row = UnityCommitteeMember::withoutGlobalScopes()->where('company_id', $cid)->where('committee_id', $c->id)->where('member_id', $memberId)
                ->where('is_deleted', 0)->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->firstOrFail();
            $end = $d['on'] ?? LocalDate::today($cid)->subDay()->toDateString();
            if ($row->starts_on !== null && $end < $row->starts_on->toDateString()) {
                $row->is_deleted = true; // added by mistake: never served
            } else {
                $row->ends_on = $end;
            }
            $row->save();

            return $this->success(self::shape($c) + ['officials' => $this->officials($cid, [(int) $c->id])[(int) $c->id] ?? []], 'Term ended.');
        });
    }

    private function find(Request $request, int $id): UnityCommittee
    {
        return UnityCommittee::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    private function checkChapter(Request $request, mixed $chapterId): void
    {
        if ($chapterId !== null && ! DB::table('unity_chapters')->where('company_id', $this->cid($request))->where('id', (int) $chapterId)->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('validation', 'That chapter or region was not found.', ['chapter_id' => ['Unknown chapter.']]);
        }
    }

    /** @param list<int> $ids @return array<int, list<array<string, mixed>>> */
    private function officials(int $cid, array $ids): array
    {
        $out = [];
        foreach (UnityOffices::serving($cid)->whereIn('cm.committee_id', $ids ?: [0])->join('sacco_members AS m', 'm.id', '=', 'cm.member_id')
            ->orderBy('cm.sort_order')->orderBy('m.last_name')
            ->get(['cm.committee_id', 'cm.member_id', 'cm.title', 'cm.sort_order', 'cm.starts_on', 'm.first_name', 'm.last_name', 'm.other_names', 'm.member_no']) as $r) {
            $out[(int) $r->committee_id][] = ['member_id' => (int) $r->member_id, 'name' => Insights::name($r), 'member_no' => $r->member_no, 'title' => $r->title,
                'sort_order' => (int) $r->sort_order, 'starts_on' => $r->starts_on ? substr((string) $r->starts_on, 0, 10) : null];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function shape(UnityCommittee $c): array
    {
        return ['id' => (int) $c->id, 'uuid' => $c->uuid, 'name' => $c->name, 'chapter_id' => $c->chapter_id ? (int) $c->chapter_id : null,
            'sort_order' => (int) $c->sort_order, 'version' => (int) $c->version];
    }
}
