<?php

namespace App\Services\Learn;

use App\Models\Learn\LearnLesson;
use App\Models\Learn\LearnProgress;
use App\Models\Learn\LearnSection;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\AppFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Learn: which lessons a person sees in an app, their progress, and what is new to them (docs/LEARN_VIDEOS.md).
 *
 * A lesson shows in an app when it is published and is for all apps or names that app. Then its audience decides:
 * public (anyone, signed out too), everyone (signed in), officers (any role but the member roles), members (the member
 * roles). Its optional roles and permissions lists narrow it further: the person needs one of them.
 */
class Learn
{
    /** @return array<string, string> slug => name: every app a lesson can target */
    public static function apps(): array
    {
        $apps = [];
        foreach (AppFamily::APPS as $slug => $row) {
            $apps[$slug] = $row[0];
        }

        return $apps + (array) config('learn.extra_apps', []);
    }

    /** The app a request names (a slug or an older alias), or null when it names none we know. */
    public static function app(?string $app): ?string
    {
        $slug = AppFamily::slug($app);
        if ($slug !== null) {
            return $slug;
        }
        $app = strtolower(trim((string) $app));

        return array_key_exists($app, (array) config('learn.extra_apps', [])) ? $app : null;
    }

    public static function roleOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        try {
            return Permissions::roleOf($user);
        } catch (\Throwable) {
            return '';
        }
    }

    public function visible(LearnLesson $lesson, ?User $user): bool
    {
        if ($user === null) {
            return $lesson->audience === 'public';
        }
        $role = (string) self::roleOf($user);
        $member = in_array($role, (array) config('learn.member_roles'), true);
        $ok = match ($lesson->audience) {
            'public', 'everyone' => true,
            'officers' => ! $member,
            'members' => $member,
            default => false,
        };
        if (! $ok) {
            return false;
        }
        $roles = array_filter((array) $lesson->roles);
        if ($roles !== [] && ! in_array($role, $roles, true)) {
            return false;
        }
        $perms = array_filter((array) $lesson->permissions);
        if ($perms !== []) {
            $held = rescue(fn () => Permissions::of($user), [], false);

            return array_intersect($perms, $held) !== [];
        }

        return true;
    }

    /**
     * Every lesson this person may see in this app, in screen order (section, then lesson).
     *
     * @return Collection<int, LearnLesson>
     */
    public function lessons(string $app, ?User $user, ?string $lang = null): Collection
    {
        $langs = array_unique(['en', $lang ?: 'en']);

        return LearnLesson::query()->forApp($app)->with('section')->whereIn('language', $langs)
            ->get()->filter(fn (LearnLesson $l) => $this->visible($l, $user))
            ->sortBy(fn (LearnLesson $l) => [$l->section ? 0 : 1, $l->section?->sort ?? 0, $l->section_id ?? 0, $l->sort, $l->id])
            ->values();
    }

    /** @return array<string, mixed> the GET /api/v1/learn payload */
    public function library(string $app, ?User $user, ?string $area = null, ?string $q = null, ?string $lang = null): array
    {
        $lessons = $this->lessons($app, $user, $lang);
        if ($area !== null && $area !== '') {
            $lessons = $lessons->filter(fn (LearnLesson $l) => in_array($area, (array) $l->areas, true))->values();
        }
        if ($q !== null && trim($q) !== '') {
            $needle = mb_strtolower(trim($q));
            $lessons = $lessons->filter(fn (LearnLesson $l) => str_contains(mb_strtolower($l->title.' '.$l->summary.' '.$l->description), $needle))->values();
        }
        $progress = $this->progressFor($user, $lessons->pluck('id')->all());
        $seenAt = $this->seenAt($user, $app);
        $rows = $lessons->map(fn (LearnLesson $l) => $this->toApi($l, $progress[$l->id] ?? null, $user, $seenAt));

        $featured = $lessons->first(fn (LearnLesson $l) => $l->featured && ! (($progress[$l->id] ?? null)?->completed_at))
            ?? $lessons->first(fn (LearnLesson $l) => $l->featured);

        $sections = [];
        foreach ($lessons as $i => $l) {
            $key = $l->section?->slug ?? 'more';
            $sections[$key] ??= ['key' => $key, 'title' => $l->section?->title ?? 'More', 'description' => $l->section?->description, 'lessons' => []];
            $sections[$key]['lessons'][] = $rows[$i];
        }

        return [
            'app' => $app,
            'signed_in' => $user !== null,
            'last_seen_at' => $seenAt?->toIso8601String(),
            'new_count' => $rows->where('is_new', true)->count(),
            'featured' => $featured ? $rows->firstWhere('id', $featured->id) : null,
            'sections' => array_values($sections),
            'count' => $lessons->count(),
            'completed' => $rows->filter(fn ($r) => $r['progress']['completed'] ?? false)->count(),
        ];
    }

    /** @return array{lesson: array<string, mixed>, previous_id: int|null, next_id: int|null}|null */
    public function lesson(int $id, string $app, ?User $user, ?string $lang = null): ?array
    {
        $lessons = $this->lessons($app, $user, $lang);
        $at = $lessons->search(fn (LearnLesson $l) => $l->id === $id);
        if ($at === false) {
            return null;
        }
        $l = $lessons[$at];
        $progress = $this->progressFor($user, [$l->id]);

        return [
            'lesson' => $this->toApi($l, $progress[$l->id] ?? null, $user, $this->seenAt($user, $app)) + ['description' => $l->description],
            'previous_id' => $at > 0 ? $lessons[$at - 1]->id : null,
            'next_id' => $lessons[$at + 1]->id ?? null,
        ];
    }

    /**
     * A progress report from the player. A person only ever writes their own row. "watched" is capped (max_step) so a
     * phone that slept cannot claim an hour; 90% watched, or the player saying it ended, finishes the lesson.
     *
     * @param  array{position?: int|float, duration?: int|float|null, watched?: int|float, completed?: bool, started?: bool}  $data
     */
    public function record(LearnLesson $lesson, User $user, string $app, array $data): LearnProgress
    {
        $now = now();
        $position = max(0, (int) round((float) ($data['position'] ?? 0)));
        $duration = isset($data['duration']) && (float) $data['duration'] > 0 ? (int) round((float) $data['duration']) : null;
        $watched = min((int) config('learn.max_step', 60), max(0, (int) round((float) ($data['watched'] ?? 0))));

        return DB::transaction(function () use ($lesson, $user, $app, $data, $now, $position, $duration, $watched) {
            $p = LearnProgress::query()->lockForUpdate()->firstOrNew(['lesson_id' => $lesson->id, 'user_id' => $user->id]);
            $p->company_id ??= $user->company_id;
            $p->app = $app;
            $p->first_seen_at ??= $now;
            $p->last_seen_at = $now;
            $p->position_seconds = $position;
            $p->furthest_seconds = max((int) $p->furthest_seconds, $position);
            $p->watched_seconds = (int) $p->watched_seconds + $watched;
            if (! empty($data['started']) || ! $p->exists) {
                $p->views = (int) $p->views + 1;
            }
            $total = $duration ?? $lesson->duration_seconds ?? $p->duration_seconds;
            if ($duration !== null) {
                $p->duration_seconds = $duration;
                if (! $lesson->duration_seconds) {
                    $lesson->forceFill(['duration_seconds' => $duration])->saveQuietly();
                }
            }
            $done = ! empty($data['completed']) || ($total && $p->furthest_seconds >= (float) config('learn.finished_at', 0.9) * $total);
            if ($done && ! $p->completed_at) {
                $p->completed_at = $now;
            }
            $p->save();

            return $p;
        });
    }

    /** The person opened Learn in this app now: what was published before is no longer new. */
    public function markSeen(User $user, string $app): Carbon
    {
        $now = now();
        DB::table('learn_seen')->upsert([['user_id' => $user->id, 'app' => $app, 'seen_at' => $now]], ['user_id', 'app'], ['seen_at']);

        return $now;
    }

    public function seenAt(?User $user, string $app): ?Carbon
    {
        if ($user === null) {
            return null;
        }
        $at = DB::table('learn_seen')->where('user_id', $user->id)->where('app', $app)->value('seen_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, LearnProgress>
     */
    public function progressFor(?User $user, array $ids): array
    {
        if ($user === null || $ids === []) {
            return [];
        }

        return LearnProgress::query()->where('user_id', $user->id)->whereIn('lesson_id', $ids)->get()->keyBy('lesson_id')->all();
    }

    /** @return array<string, mixed> one lesson as the phone and the web read it */
    public function toApi(LearnLesson $l, ?LearnProgress $p, ?User $user, ?Carbon $seenAt): array
    {
        // New: published after the person last opened Learn (or in the last 30 days when they never have), not yet started.
        $since = $seenAt ?? now()->subDays(30);
        $isNew = $user !== null && $p === null && $l->published_at !== null && $l->published_at->gt($since);

        return [
            'id' => $l->id,
            'title' => $l->title,
            'summary' => $l->summary,
            'section' => $l->section?->slug ?? 'more',
            'areas' => array_values((array) $l->areas),
            'audience' => $l->audience,
            'language' => $l->language,
            'duration_seconds' => $l->duration_seconds,
            'length' => $l->length(),
            'thumbnail_url' => $l->thumbnail(),
            'source' => [
                'type' => $l->source_type,
                'youtube_id' => $l->source_type === 'youtube' ? $l->youtube_id : null,
                'url' => $l->source_type === 'youtube' ? null : $l->playUrl(),
                'watch_url' => $l->playUrl(),
            ],
            'featured' => (bool) $l->featured,
            'published_at' => $l->published_at?->toIso8601String(),
            'is_new' => $isNew,
            'progress' => $p?->toApi(),
        ];
    }

    // ── Admin ──

    /**
     * Views, people, completions and minutes watched per lesson, from the progress rows.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{views: int, people: int, completed: int, minutes: int}>
     */
    public function stats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LearnProgress::query()->whereIn('lesson_id', $ids)->groupBy('lesson_id')
            ->selectRaw('lesson_id, sum(views) as views, count(*) as people, sum(case when completed_at is null then 0 else 1 end) as completed, sum(watched_seconds) as watched')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->lesson_id => [
                'views' => (int) $r->views, 'people' => (int) $r->people, 'completed' => (int) $r->completed, 'minutes' => (int) round($r->watched / 60),
            ]])->all();
    }

    /** A section by title (made when it is new), or null for a blank title. */
    public function section(?string $title): ?LearnSection
    {
        $title = trim((string) $title);
        if ($title === '') {
            return null;
        }
        $slug = Str::slug($title) ?: 'section';

        return LearnSection::query()->firstOrCreate(['slug' => $slug],
            ['title' => $title, 'sort' => (int) LearnSection::query()->max('sort') + 1]);
    }

    /** Move a lesson one place up or down among the lessons of its section (sorts are renumbered first). */
    public function move(LearnLesson $lesson, string $dir): void
    {
        $this->swap(LearnLesson::query()->where('section_id', $lesson->section_id)->orderBy('sort')->orderBy('id')->get(), $lesson->id, $dir);
    }

    public function moveSection(LearnSection $section, string $dir): void
    {
        $this->swap(LearnSection::query()->orderBy('sort')->orderBy('id')->get(), $section->id, $dir);
    }

    /** Put lessons in the order given (drag and drop): their sort becomes their place in the list. */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            LearnLesson::query()->whereKey((int) $id)->update(['sort' => $i + 1]);
        }
    }

    private function swap(Collection $rows, int $id, string $dir): void
    {
        DB::transaction(function () use ($rows, $id, $dir) {
            $rows = $rows->values();
            foreach ($rows as $i => $row) {
                if ($row->sort !== $i + 1) {
                    $row->forceFill(['sort' => $i + 1])->save();
                }
            }
            $at = $rows->search(fn ($r) => $r->id === $id);
            $to = $dir === 'up' ? $at - 1 : $at + 1;
            if ($at === false || ! isset($rows[$to])) {
                return;
            }
            [$a, $b] = [$rows[$at], $rows[$to]];
            $a->forceFill(['sort' => $to + 1])->save();
            $b->forceFill(['sort' => $at + 1])->save();
        });
    }
}
