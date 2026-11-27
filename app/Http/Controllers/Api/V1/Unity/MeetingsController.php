<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\UnityMeeting;
use App\Models\UnityResolution;
use App\Services\Unity\CardTokens;
use App\Services\Unity\Meetings;
use App\Services\Unity\UnityMembers;
use App\Services\Unity\Welfare;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meetings & Events: the calendar, attendance (a list, or check-in from the card's QR), RSVPs with tickets, minutes and
 * resolutions. Reads unity_view (the list and one meeting are open to all, for RSVP); writes unity_meetings; a member
 * answers an invitation only for themselves.
 */
class MeetingsController extends UnityController
{
    /** GET unity/meetings?when=upcoming|past&kind=&chapter_id=&page */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $when = $request->query('when');
        $now = now()->toDateTimeString();
        $q = UnityMeeting::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when(in_array($k = $request->query('kind'), Meetings::KINDS, true), fn ($w) => $w->where('kind', $k))
            ->when($request->filled('chapter_id'), fn ($w) => $w->where('chapter_id', (int) $request->query('chapter_id')))
            ->when($when === 'upcoming', fn ($w) => $w->where('starts_at', '>=', $now)->where('status', 'scheduled')->orderBy('starts_at')->orderBy('id'))
            ->when($when === 'past', fn ($w) => $w->where(fn ($x) => $x->where('starts_at', '<', $now)->orWhere('status', '!=', 'scheduled'))->orderByDesc('starts_at')->orderByDesc('id'))
            ->when(! in_array($when, ['upcoming', 'past'], true), fn ($w) => $w->orderByDesc('starts_at')->orderByDesc('id'));
        $chapters = $this->chapterNames($cid);

        return $this->pageOf($q, $request, fn ($rows) => $rows->map(fn ($m) => Meetings::shape($m, $chapters) + ['counts' => Meetings::counts($m)])->values()->all());
    }

    /** GET unity/meetings/{id}: the meeting, its counts, resolutions and the signed-in member's own answer. */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $cid = $this->cid($request);
            $mt = $this->find($request, $id);
            $me = \App\Services\Unity\UnityAccess::memberId($cid, $request->user());
            $mine = $me === null ? null : DB::table('unity_attendance')->where('meeting_id', $mt->id)->where('member_id', $me)->where('is_deleted', 0)->first(['rsvp', 'ticket_no', 'present']);

            return $this->success(Meetings::shape($mt, $this->chapterNames($cid)) + Meetings::counts($mt) + [
                'resolutions' => $this->resolutionsOf($cid, [(int) $mt->id]),
                'my_rsvp' => $mine?->rsvp, 'my_ticket_no' => $mine?->ticket_no, 'i_was_present' => $mine === null ? null : (bool) $mine->present,
            ], 'Meeting.');
        });
    }

    /** POST unity/meetings {kind, title, chapter_id?, starts_at, ends_at?, venue?, agenda?, rsvp_open?, capacity?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules(true));

        return $this->attempt(function () use ($request, $d) {
            $cid = $this->cid($request);
            $this->checkChapter($cid, $d['chapter_id'] ?? null);
            $mt = UnityMeeting::create($this->times($cid, $d) + ['company_id' => $cid, 'status' => 'scheduled', 'created_by_id' => $request->user()->id,
                'rsvp_open' => (bool) ($d['rsvp_open'] ?? true)]);

            return $this->created(Meetings::shape($mt->fresh(), $this->chapterNames($cid)), 'Meeting set.');
        });
    }

    /** PATCH unity/meetings/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $mt = $this->find($request, $id);
            if ($mt->status === 'cancelled') {
                throw BusinessRuleException::make('cancelled_final', 'A cancelled meeting is not changed. Set a new one.');
            }
            if (array_key_exists('chapter_id', $d)) {
                $this->checkChapter($cid, $d['chapter_id']);
            }
            $mt->fill($this->times($cid, $d))->save();

            return $this->success(Meetings::shape($mt->fresh(), $this->chapterNames($cid)), 'Saved.');
        });
    }

    /** POST unity/meetings/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $mt = $this->find($request, $id);
            if ($mt->status === 'held') {
                throw BusinessRuleException::make('not_open', 'This meeting was held; it is not cancelled now.');
            }
            $mt->status = 'cancelled';
            $mt->save();

            return $this->success(Meetings::shape($mt->fresh(), $this->chapterNames($this->cid($request))), 'Meeting cancelled.');
        });
    }

    /** GET unity/meetings/{id}/attendance?q&page: the members expected, each with their mark. */
    public function attendance(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $mt = $this->find($request, $id);
            $cid = $this->cid($request);
            $q = Meetings::expected($mt);
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('other_names', 'like', $like)->orWhere('member_no', 'like', $like));
            }
            $q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->select(['id', 'first_name', 'last_name', 'other_names', 'member_no', 'photo_file_id']);

            return $this->pageOf($q, $request, function ($rows) use ($cid, $mt) {
                $photos = \App\Services\Unity\UnityLinks::photos($cid, $rows->pluck('photo_file_id')->filter()->all());
                $marks = DB::table('unity_attendance')->where('meeting_id', $mt->id)->where('is_deleted', 0)->whereIn('member_id', $rows->pluck('id')->all() ?: [0])
                    ->get(['member_id', 'present', 'apology', 'rsvp', 'ticket_no', 'method'])->keyBy('member_id');

                return $rows->map(function ($r) use ($photos, $marks) {
                    $a = $marks[$r->id] ?? null;

                    return ['member_id' => (int) $r->id, 'name' => \App\Services\Unity\Insights::name($r), 'member_no' => $r->member_no,
                        'photo_url' => $r->photo_file_id ? ($photos[(int) $r->photo_file_id] ?? null) : null,
                        'present' => (bool) ($a->present ?? false), 'apology' => (bool) ($a->apology ?? false), 'rsvp' => $a->rsvp ?? null,
                        'ticket_no' => $a->ticket_no ?? null, 'method' => $a->method ?? null];
                })->values()->all();
            }, 100);
        });
    }

    /** POST unity/meetings/{id}/attendance {present: [ids], absent: [ids], apology: [ids]} */
    public function saveAttendance(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['present' => ['sometimes', 'array'], 'present.*' => ['integer'], 'absent' => ['sometimes', 'array'], 'absent.*' => ['integer'],
            'apology' => ['sometimes', 'array'], 'apology.*' => ['integer']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $mt = $this->find($request, $id);
            $saved = Meetings::attendance($mt, $d);

            return $this->success(['saved' => $saved] + Meetings::counts($mt->fresh()), 'Attendance saved.');
        });
    }

    /** POST unity/meetings/{id}/checkin {token | member_id}: returns the member. Safe to repeat. */
    public function checkin(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['token' => ['required_without:member_id', 'nullable', 'string', 'max:64'], 'member_id' => ['required_without:token', 'nullable', 'integer']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $mt = $this->find($request, $id);
            if (! empty($d['token'])) {
                $m = CardTokens::member($d['token']);
                if ($m === null || (int) $m->company_id !== $cid) {
                    throw BusinessRuleException::make('not_found', 'This card is not one of this group\'s.');
                }
                $method = 'qr';
            } else {
                $m = UnityMembers::find($cid, (int) $d['member_id']);
                $method = 'list';
            }
            $a = Meetings::checkin($mt, $m, $method);

            return $this->success(['member' => UnityMembers::present($m), 'present' => true, 'checked_in_at' => Meetings::iso($cid, $a->getRawOriginal('checked_in_at')),
                'method' => $a->method, 'ticket_no' => $a->ticket_no, 'rsvp' => $a->rsvp], 'Checked in.');
        });
    }

    /** POST unity/meetings/{id}/rsvp {answer, member_id?}: without member_id, the signed-in member answers. */
    public function rsvp(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['answer' => ['required', 'in:'.implode(',', Meetings::RSVP)], 'member_id' => ['sometimes', 'nullable', 'integer']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $mt = $this->find($request, $id);
            if (! $mt->rsvp_open) {
                throw BusinessRuleException::make('not_open', 'Answers are closed for this meeting.');
            }
            $m = $this->memberFor($request, isset($d['member_id']) ? (int) $d['member_id'] : null, 'unity_meetings');
            $a = Meetings::rsvp($mt, $m, $d['answer']);

            return $this->success(['meeting_id' => (int) $mt->id, 'member_id' => (int) $m->id, 'rsvp' => $a->rsvp, 'ticket_no' => $a->ticket_no] + Meetings::counts($mt), 'Answer saved.');
        });
    }

    /** POST unity/meetings/{id}/minutes {minutes} and/or a multipart minutes_photo */
    public function minutes(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['minutes' => ['sometimes', 'nullable', 'string', 'max:20000'], 'minutes_photo' => ['sometimes', 'file', 'mimes:jpeg,jpg,png', 'max:8192']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $mt = $this->find($request, $id);
            if (array_key_exists('minutes', $d)) {
                $mt->minutes = $d['minutes'];
                $mt->save();
            }
            if ($request->hasFile('minutes_photo')) {
                $mt = Meetings::minutesPhoto($this->company($request), $mt, $request->user(), $request->file('minutes_photo'));
            }

            return $this->success(Meetings::shape($mt->fresh(), $this->chapterNames($this->cid($request))), 'Minutes saved.');
        });
    }

    /** GET unity/resolutions?status=open|done&page */
    public function resolutions(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $q = UnityResolution::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when(in_array($s = $request->query('status'), ['open', 'done'], true), fn ($w) => $w->where('status', $s))
            ->orderByRaw("status = 'done'")->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderByDesc('id');

        return $this->pageOf($q, $request, fn ($rows) => $this->shapeResolutions($cid, $rows));
    }

    /** POST unity/meetings/{id}/resolutions {text, owner_member_id?, due_on?} */
    public function storeResolution(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:500'], 'owner_member_id' => ['sometimes', 'nullable', 'integer'], 'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $mt = $this->find($request, $id);
            if (! empty($d['owner_member_id'])) {
                UnityMembers::find($cid, (int) $d['owner_member_id']);
            }
            $r = UnityResolution::create(['company_id' => $cid, 'meeting_id' => $mt->id, 'text' => $d['text'], 'owner_member_id' => $d['owner_member_id'] ?? null,
                'due_on' => $d['due_on'] ?? null, 'status' => 'open']);

            return $this->created($this->shapeResolutions($cid, collect([$r->fresh()]))[0], 'Resolution recorded.');
        });
    }

    /** PATCH unity/resolutions/{id} {status?, text?, owner_member_id?, due_on?} */
    public function updateResolution(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['sometimes', 'in:open,done'], 'text' => ['sometimes', 'string', 'max:500'], 'owner_member_id' => ['sometimes', 'nullable', 'integer'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $cid = $this->cid($request);
            $r = UnityResolution::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->findOrFail($id);
            if (! empty($d['owner_member_id'])) {
                UnityMembers::find($cid, (int) $d['owner_member_id']);
            }
            $r->fill($d);
            if (isset($d['status'])) {
                $r->done_on = $d['status'] === 'done' ? ($r->done_on ?? LocalDate::today($cid)->toDateString()) : null;
            }
            $r->save();

            return $this->success($this->shapeResolutions($cid, collect([$r->fresh()]))[0], 'Saved.');
        });
    }

    private function find(Request $request, int $id): UnityMeeting
    {
        return UnityMeeting::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function times(int $cid, array $d): array
    {
        foreach (['starts_at', 'ends_at'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = $d[$k] === null ? null : Meetings::parse($cid, (string) $d[$k]);
            }
        }
        if (isset($d['starts_at'], $d['ends_at']) && $d['ends_at'] !== null && $d['ends_at']->lt($d['starts_at'])) {
            throw BusinessRuleException::make('validation', 'It cannot end before it starts.', ['ends_at' => ['After the start.']]);
        }

        return $d;
    }

    private function checkChapter(int $cid, mixed $chapterId): void
    {
        if ($chapterId !== null && ! DB::table('unity_chapters')->where('company_id', $cid)->where('id', (int) $chapterId)->where('kind', 'chapter')->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('validation', 'That chapter was not found.', ['chapter_id' => ['Unknown chapter.']]);
        }
    }

    /** @return array<int, string> */
    private function chapterNames(int $cid): array
    {
        return DB::table('unity_chapters')->where('company_id', $cid)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => $n])->all();
    }

    /** @param list<int> $meetingIds @return list<array<string, mixed>> */
    private function resolutionsOf(int $cid, array $meetingIds): array
    {
        return $this->shapeResolutions($cid, UnityResolution::withoutGlobalScopes()->where('company_id', $cid)->whereIn('meeting_id', $meetingIds)->where('is_deleted', 0)->orderBy('id')->get());
    }

    /** @return list<array<string, mixed>> */
    private function shapeResolutions(int $cid, \Illuminate\Support\Collection $rows): array
    {
        $names = Welfare::names($cid, $rows->pluck('owner_member_id'));
        $meetings = DB::table('unity_meetings')->where('company_id', $cid)->whereIn('id', $rows->pluck('meeting_id')->unique()->all() ?: [0])->get(['id', 'title', 'starts_at'])->keyBy('id');
        $today = LocalDate::today($cid)->toDateString();

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'meeting_id' => (int) $r->meeting_id, 'meeting_title' => $meetings[$r->meeting_id]->title ?? null,
            'meeting_date' => isset($meetings[$r->meeting_id]) ? substr((string) Meetings::iso($cid, $meetings[$r->meeting_id]->starts_at), 0, 10) : null,
            'text' => $r->text, 'owner_member_id' => $r->owner_member_id ? (int) $r->owner_member_id : null, 'owner_name' => $r->owner_member_id ? ($names[(int) $r->owner_member_id] ?? null) : null,
            'due_on' => $r->due_on?->toDateString(), 'status' => $r->status, 'done_on' => $r->done_on?->toDateString(),
            'overdue' => $r->status === 'open' && $r->due_on !== null && $r->due_on->toDateString() < $today, 'version' => (int) $r->version])->values()->all();
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return ['kind' => [$r, 'in:'.implode(',', Meetings::KINDS)], 'title' => [$r, 'string', 'max:150'], 'chapter_id' => ['sometimes', 'nullable', 'integer'],
            'starts_at' => [$r, 'string', 'max:40'], 'ends_at' => ['sometimes', 'nullable', 'string', 'max:40'], 'venue' => ['sometimes', 'nullable', 'string', 'max:150'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:5000'], 'rsvp_open' => ['sometimes', 'boolean'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000']];
    }
}
