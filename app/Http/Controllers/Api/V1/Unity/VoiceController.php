<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\UnityAnnouncement;
use App\Models\UnityBallot;
use App\Models\UnitySetting;
use App\Services\Unity\Ballots;
use App\Services\Unity\MemberFilters;
use App\Services\Unity\UnityAccess;
use App\Services\Unity\Voice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Voice: ballots (elections and polls, secret: only counts and turnout are ever shown), the noticeboard and messages.
 * Reads open to the team (a member sees ballots and notices too); writes unity_voice; voting is each member's own.
 * Also the group's UnityDesk settings (unity_settings).
 */
class VoiceController extends UnityController
{
    /** GET unity/ballots?status=draft|open|closed&page: a member sees open and closed ballots only. */
    public function ballots(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $officer = $this->can($request, 'unity_voice');
        $q = UnityBallot::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)
            ->when(! $officer, fn ($w) => $w->where('status', '!=', 'draft'))
            ->when(in_array($s = $request->query('status'), ['draft', 'open', 'closed'], true), fn ($w) => $w->where('status', $s))
            ->orderByRaw("FIELD(status, 'open', 'draft', 'closed')")->orderByDesc('id');
        $me = UnityAccess::member($cid, $request->user());

        return $this->pageOf($q, $request, fn ($rows) => $rows->map(function ($b) use ($me) {
            $voted = $me !== null && DB::table('unity_votes')->where('ballot_id', $b->id)->where('member_id', $me->id)->where('is_deleted', 0)->exists();

            return Ballots::shape($b) + ['i_voted' => $voted];
        })->values()->all());
    }

    /** GET unity/ballots/{id} */
    public function ballot(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $officer = $this->can($request, 'unity_voice');
            $b = $this->findBallot($request, $id, $officer);

            return $this->success(Ballots::show($b, UnityAccess::member($this->cid($request), $request->user()), $officer), 'Ballot.');
        });
    }

    /** POST unity/ballots {kind, title, description?, chapter_id?, opens_at?, closes_at?, eligibility, questions: [{title, options: [{label, member_id?}]}]} */
    public function storeBallot(Request $request): JsonResponse
    {
        $d = $request->validate(self::ballotRules(true));

        return $this->attempt(function () use ($request, $d) {
            $this->checkChapter($request, $d['chapter_id'] ?? null);
            $b = Ballots::create($this->company($request), $d, (int) $request->user()->id);

            return $this->created(Ballots::show($b, null, true), $b->kind === 'election' ? 'Election drafted.' : 'Poll drafted.');
        });
    }

    /** PATCH unity/ballots/{id} (draft only) */
    public function updateBallot(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::ballotRules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            if (array_key_exists('chapter_id', $d)) {
                $this->checkChapter($request, $d['chapter_id']);
            }
            $b = Ballots::update($this->company($request), $this->findBallot($request, $id, true), $d);

            return $this->success(Ballots::show($b, null, true), 'Saved.');
        });
    }

    public function openBallot(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Ballots::show(Ballots::open($this->company($request), $this->findBallot($request, $id, true)), null, true), 'Voting is open.'));
    }

    public function closeBallot(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Ballots::show(Ballots::close($this->findBallot($request, $id, true)), null, true), 'Voting is closed.'));
    }

    /** POST unity/ballots/{id}/vote {answers: [{question_id, option_id}]}: as the signed-in member, once per question. */
    public function vote(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['answers' => ['required', 'array', 'min:1'], 'answers.*.question_id' => ['required', 'integer'], 'answers.*.option_id' => ['required', 'integer']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $b = $this->findBallot($request, $id, false);
            $me = $this->ownMember($request);
            $n = Ballots::vote($b, $me, $d['answers']);

            return $this->success(Ballots::show($b->fresh(), $me, $this->can($request, 'unity_voice')), $n === 1 ? 'Your vote is in.' : 'Your votes are in.');
        });
    }

    /** GET unity/announcements?page: the noticeboard, pinned first. A member sees those for everyone and for their own chapter, region and category. */
    public function announcements(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $q = UnityAnnouncement::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0);
        if (! $this->can($request, 'unity_view')) {
            $me = UnityAccess::member($cid, $request->user());
            $region = $me?->chapter_id ? DB::table('unity_chapters')->where('id', $me->chapter_id)->value('parent_id') : null;
            $q->where(fn ($w) => $w->where('audience', 'all')
                ->orWhere(fn ($x) => $x->where('audience', 'chapter')->where('audience_id', (int) ($me->chapter_id ?? 0)))
                ->orWhere(fn ($x) => $x->where('audience', 'region')->where('audience_id', (int) ($region ?? 0)))
                ->orWhere(fn ($x) => $x->where('audience', 'category')->where('audience_id', (int) ($me->category_id ?? 0))));
        }
        $q->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id');
        $names = DB::table('unity_chapters')->where('company_id', $cid)->pluck('name', 'id')->all();
        $categories = DB::table('unity_categories')->where('company_id', $cid)->pluck('name', 'id')->all();

        return $this->pageOf($q, $request, fn ($rows) => $rows->map(fn ($a) => Voice::shape($a, $a->audience === 'category' ? $categories : $names))->values()->all(), 20);
    }

    /** POST unity/announcements {title, body, audience, audience_id?, pinned?, send_notice?, send_sms?} */
    public function storeAnnouncement(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:2000'], 'audience' => ['sometimes', 'in:'.implode(',', Voice::AUDIENCES)],
            'audience_id' => ['sometimes', 'nullable', 'integer'], 'pinned' => ['sometimes', 'boolean'], 'send_notice' => ['sometimes', 'boolean'], 'send_sms' => ['sometimes', 'boolean']]);

        return $this->attempt(function () use ($request, $d) {
            $a = Voice::announce($this->company($request), $d, (int) $request->user()->id);

            return $this->created(Voice::shape($a, $this->audienceNames($request, $a)), 'Announced.');
        });
    }

    /** PATCH unity/announcements/{id} {title?, body?, pinned?}: no new notices are sent. */
    public function updateAnnouncement(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['title' => ['sometimes', 'string', 'max:150'], 'body' => ['sometimes', 'string', 'max:2000'], 'pinned' => ['sometimes', 'boolean']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $a = $this->findAnnouncement($request, $id);
            $a->fill($d)->save();

            return $this->success(Voice::shape($a->fresh(), $this->audienceNames($request, $a)), 'Saved.');
        });
    }

    public function destroyAnnouncement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $a = $this->findAnnouncement($request, $id);
            $a->is_deleted = true;
            $a->save();

            return $this->success(null, 'Removed from the noticeboard.');
        });
    }

    /** POST unity/messages {body, member_ids?: [], filter?: {members filters}, channel: notice|sms}: {recipients, sent, skipped_no_phone, quota_left} */
    public function message(Request $request): JsonResponse
    {
        $d = $request->validate(['body' => ['required', 'string', 'max:1000'], 'channel' => ['required', 'in:notice,sms'], 'member_ids' => ['sometimes', 'array', 'max:5000'],
            'member_ids.*' => ['integer'], 'filter' => ['sometimes', 'array']]);

        return $this->attempt(function () use ($request, $d) {
            $cid = $this->cid($request);
            if (! empty($d['member_ids'])) {
                $ids = MemberFilters::base($cid)->whereIn('id', array_map('intval', $d['member_ids']))->pluck('id');
            } else {
                $filter = $d['filter'] ?? [];
                $q = ($filter['status'] ?? '') === '' ? MemberFilters::current($cid) : MemberFilters::base($cid);
                $ids = MemberFilters::apply($q, $cid, $filter)->pluck('id');
            }
            $ids = $ids->map(fn ($i) => (int) $i)->unique()->values()->all();
            if ($ids === []) {
                throw \App\Exceptions\BusinessRuleException::make('validation', 'No members match. Choose who gets the message.', ['member_ids' => ['No recipients.']]);
            }

            return $this->success(Voice::message($this->company($request), $ids, $d['body'], $d['channel'], (int) $request->user()->id), 'Message sent.');
        });
    }

    /** GET unity/settings */
    public function settings(Request $request): JsonResponse
    {
        $s = UnitySetting::for($this->cid($request));

        return $this->success(['verify_page' => (bool) $s->verify_page], 'Settings.');
    }

    /** PATCH unity/settings {verify_page} */
    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['verify_page' => ['sometimes', 'boolean']]);
        $s = UnitySetting::for($this->cid($request));
        $s->fill($d)->save();

        return $this->success(['verify_page' => (bool) $s->verify_page], 'Saved.');
    }

    private function findBallot(Request $request, int $id, bool $officer): UnityBallot
    {
        return UnityBallot::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)
            ->when(! $officer, fn ($w) => $w->where('status', '!=', 'draft'))->findOrFail($id);
    }

    private function findAnnouncement(Request $request, int $id): UnityAnnouncement
    {
        return UnityAnnouncement::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<int, string> */
    private function audienceNames(Request $request, UnityAnnouncement $a): array
    {
        $table = $a->audience === 'category' ? 'unity_categories' : 'unity_chapters';

        return $a->audience_id ? DB::table($table)->where('company_id', $this->cid($request))->where('id', $a->audience_id)->pluck('name', 'id')->all() : [];
    }

    private function checkChapter(Request $request, mixed $chapterId): void
    {
        if ($chapterId !== null && ! DB::table('unity_chapters')->where('company_id', $this->cid($request))->where('id', (int) $chapterId)->where('kind', 'chapter')->where('is_deleted', 0)->exists()) {
            throw \App\Exceptions\BusinessRuleException::make('validation', 'That chapter was not found.', ['chapter_id' => ['Unknown chapter.']]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private static function ballotRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return ['kind' => [$r, 'in:'.implode(',', Ballots::KINDS)], 'title' => [$r, 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'chapter_id' => ['sometimes', 'nullable', 'integer'], 'opens_at' => ['sometimes', 'nullable', 'string', 'max:40'], 'closes_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'eligibility' => ['sometimes', 'in:'.implode(',', Ballots::ELIGIBILITY)], 'questions' => [$r, 'array', 'min:1', 'max:30'],
            'questions.*.title' => ['required', 'string', 'max:150'], 'questions.*.options' => ['required', 'array', 'min:2', 'max:30'],
            'questions.*.options.*.label' => ['required', 'string', 'max:150'], 'questions.*.options.*.member_id' => ['sometimes', 'nullable', 'integer']];
    }
}
