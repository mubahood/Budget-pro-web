<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\KinAnnouncement;
use App\Models\User;
use App\Services\Team\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The family's announcements. Everyone reads what is published (pinned first, then the newest); kin_announce (the head and
 * the secretary) posts, edits and removes, and also sees what is set for later. Removing keeps the row (soft delete).
 */
class AnnouncementsController extends KinController
{
    public function index(Request $request): JsonResponse
    {
        $q = KinAnnouncement::withoutGlobalScopes()->where('company_id', $this->cid($request));
        if (! Permissions::can($request->user(), 'kin_announce')) {
            $q->whereNotNull('published_at')->where('published_at', '<=', now());
        }
        $p = $q->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->paginate($this->perPage($request, 20));

        return $this->page($p, self::shapeAll($p->getCollection()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::shapeAll(collect([$this->find($request, $id, true)]))[0]));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules(true));
        $a = KinAnnouncement::create(['company_id' => $this->cid($request), 'title' => $d['title'], 'body' => $d['body'] ?? null, 'pinned' => (bool) ($d['pinned'] ?? false),
            'author_user_id' => $request->user()->id, 'published_at' => $d['published_at'] ?? now()]);

        return $this->created(self::shapeAll(collect([$a->fresh()]))[0], 'Announcement posted.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $a = $this->find($request, $id, false);
            $a->fill($d)->save();

            return $this->success(self::shapeAll(collect([$a->fresh()]))[0], 'Saved.');
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->find($request, $id, false)->delete();

            return $this->success(null, 'Announcement removed.');
        });
    }

    private function find(Request $request, int $id, bool $reading): KinAnnouncement
    {
        $q = KinAnnouncement::withoutGlobalScopes()->where('company_id', $this->cid($request));
        if ($reading && ! Permissions::can($request->user(), 'kin_announce')) {
            $q->whereNotNull('published_at')->where('published_at', '<=', now());
        }

        return $q->findOrFail($id);
    }

    /** @return array<string, array<int, string>> */
    private static function rules(bool $creating): array
    {
        return ['title' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'], 'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pinned' => ['sometimes', 'boolean'], 'published_at' => ['sometimes', 'nullable', 'date']];
    }

    /** @return list<array<string, mixed>> */
    private static function shapeAll($rows): array
    {
        $authors = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('author_user_id')->filter()->unique())->get(['id', 'name', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim((string) ($u->name ?: $u->first_name.' '.$u->last_name))]);

        return $rows->map(fn (KinAnnouncement $a) => ['id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body, 'pinned' => (bool) $a->pinned,
            'author_user_id' => $a->author_user_id ? (int) $a->author_user_id : null, 'author_name' => $a->author_user_id ? ($authors[(int) $a->author_user_id] ?? null) : null,
            'published_at' => $a->published_at?->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String(), 'updated_at' => $a->updated_at?->toIso8601String()])->values()->all();
    }
}
