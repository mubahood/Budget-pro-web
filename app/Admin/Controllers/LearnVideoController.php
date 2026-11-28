<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Learn\LearnLesson;
use App\Models\Learn\LearnSection;
use App\Services\Learn\Learn;
use App\Services\Learn\LearnUploads;
use App\Services\Learn\UploadConflict;
use App\Services\Learn\YouTube;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Platform › Learn videos: the training lessons every app shows on its Learn screen (docs/LEARN_VIDEOS.md).
 * Add a lesson (a YouTube link fills its title and picture, or upload a file here), pick the apps it is for (or all),
 * who sees it, the screens it explains, its section and order, then publish. Each row shows views and completions.
 * Routed in the platform group (edition:saas + admin.platform:all): a 404 on a standalone copy, a 403 for shop users.
 */
class LearnVideoController extends Controller
{
    public function __construct(private readonly Learn $learn)
    {
    }

    public function index(Content $content, Request $request)
    {
        $apps = Learn::apps();
        $app = array_key_exists((string) $request->query('app'), $apps) ? (string) $request->query('app') : null;
        $q = LearnLesson::query()->with(['section', 'appRows'])->orderBy('sort')->orderBy('id');
        if ($app !== null) {
            $q->where(fn ($w) => $w->where('all_apps', true)->orWhereHas('appRows', fn ($a) => $a->where('app', $app)));
        }
        $lessons = $q->get();
        $sections = LearnSection::query()->orderBy('sort')->orderBy('id')->get();
        $groups = $sections->map(fn (LearnSection $s) => ['section' => $s, 'lessons' => $lessons->where('section_id', $s->id)->values()])
            ->push(['section' => null, 'lessons' => $lessons->whereNull('section_id')->values()])
            ->filter(fn ($g) => $g['section'] !== null || $g['lessons']->isNotEmpty())->values();

        return $content->title('Learn videos')->description('Training lessons for each app\'s Learn screen')
            ->body(view('admin.learn-videos', [
                'groups' => $groups, 'apps' => $apps, 'app' => $app, 'stats' => $this->learn->stats($lessons->pluck('id')->all()),
                'audiences' => LearnLesson::AUDIENCES, 'total' => $lessons->count(),
            ]));
    }

    public function create(Content $content)
    {
        return $this->form($content, new LearnLesson(['audience' => 'everyone', 'language' => 'en', 'source_type' => 'youtube']));
    }

    public function edit(Content $content, int $id)
    {
        return $this->form($content, LearnLesson::query()->with(['section', 'appRows'])->findOrFail($id));
    }

    public function store(Request $request)
    {
        $lesson = new LearnLesson(['created_by' => Admin::user()?->id]);
        $this->save($request, $lesson);
        admin_success('Lesson added', $lesson->source_type === 'file' && ! $lesson->file_path
            ? 'Upload its video file below, then publish it.' : 'Publish it when it is ready.');

        return redirect(admin_url('learn-videos/'.$lesson->id.'/edit'));
    }

    public function update(Request $request, int $id)
    {
        $lesson = LearnLesson::query()->findOrFail($id);
        $this->save($request, $lesson);
        admin_success('Saved', $lesson->published ? 'The apps show the change at once.' : 'Saved as a draft.');

        return redirect(admin_url('learn-videos/'.$lesson->id.'/edit'));
    }

    public function publish(Request $request, int $id)
    {
        $lesson = LearnLesson::query()->findOrFail($id);
        $on = ! $lesson->published;
        if ($on && ($problem = $this->notReady($lesson)) !== null) {
            admin_error('Not published', $problem);

            return back();
        }
        $lesson->forceFill(['published' => $on, 'published_at' => $on ? ($lesson->published_at ?? now()) : $lesson->published_at])->save();
        admin_success($on ? 'Published' : 'Taken down', $on ? 'It shows in its apps now.' : 'It no longer shows in any app.');

        return back();
    }

    public function move(Request $request, int $id)
    {
        $this->learn->move(LearnLesson::query()->findOrFail($id), $request->input('dir') === 'up' ? 'up' : 'down');

        return back();
    }

    public function moveSection(Request $request, int $id)
    {
        $this->learn->moveSection(LearnSection::query()->findOrFail($id), $request->input('dir') === 'up' ? 'up' : 'down');

        return back();
    }

    /** Drag and drop: the lessons of one section in their new order. */
    public function reorder(Request $request): JsonResponse
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $this->learn->reorder(array_slice($ids, 0, 500));

        return response()->json(['ok' => true]);
    }

    public function destroy(int $id)
    {
        $lesson = LearnLesson::query()->findOrFail($id);
        DB::transaction(function () use ($lesson) {
            $lesson->appRows()->delete();
            $lesson->progress()->delete();
            $lesson->delete();
        });
        $disk = Storage::disk(config('learn.disk'));
        foreach (array_filter([$lesson->file_path, $lesson->thumbnail_path]) as $path) {
            $disk->delete($path);
        }
        admin_success('Deleted', '"'.$lesson->title.'" and its progress are gone.');

        return redirect(admin_url('learn-videos'));
    }

    /** A pasted YouTube link: its id, title and picture, to fill the form. */
    public function youtube(Request $request): JsonResponse
    {
        $id = YouTube::videoId((string) $request->query('url'));
        if ($id === null) {
            return response()->json(['message' => 'That is not a YouTube video link.'], 422);
        }

        return response()->json(YouTube::lookup($id));
    }

    public function upload(Request $request, int $id, string $kind, LearnUploads $uploads): JsonResponse
    {
        $lesson = LearnLesson::query()->findOrFail($id);
        try {
            return response()->json($uploads->receiveChunk($lesson, $kind, (string) $request->query('upload'), (int) $request->query('offset'),
                (int) $request->query('size'), strtolower((string) $request->query('sha1')), (string) $request->query('ext'), $request->getContent()));
        } catch (UploadConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function form(Content $content, LearnLesson $lesson)
    {
        return $content->title($lesson->exists ? $lesson->title : 'New lesson')->description('Learn videos')
            ->body(view('admin.learn-video', [
                'l' => $lesson, 'apps' => Learn::apps(), 'audiences' => LearnLesson::AUDIENCES,
                'sections' => LearnSection::query()->orderBy('sort')->pluck('title')->all(),
                'roles' => array_keys((array) config('permissions.roles')), 'perms' => array_keys((array) config('permissions.permissions')),
                'languages' => (array) config('learn.languages'), 'max' => (array) config('learn.max'),
                'stats' => $lesson->exists ? ($this->learn->stats([$lesson->id])[$lesson->id] ?? null) : null,
                'media' => fn (?string $p) => $p ? Storage::disk(config('learn.disk'))->url($p) : null,
            ]));
    }

    private function save(Request $request, LearnLesson $lesson): void
    {
        $apps = array_keys(Learn::apps());
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'section' => ['nullable', 'string', 'max:120'],
            'all_apps' => ['nullable', 'boolean'],
            'apps' => ['nullable', 'array'], 'apps.*' => ['string', Rule::in($apps)],
            'areas' => ['nullable', 'string', 'max:300'],
            'audience' => ['required', Rule::in(array_keys(LearnLesson::AUDIENCES))],
            'roles' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'string', 'max:500'],
            'language' => ['required', Rule::in(array_keys((array) config('learn.languages')))],
            'source_type' => ['required', Rule::in(LearnLesson::SOURCES)],
            'youtube' => ['nullable', 'string', 'max:300'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'duration' => ['nullable', 'string', 'max:10'],
            'featured' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
        ]);
        $all = (bool) ($data['all_apps'] ?? false);
        $chosen = array_values(array_unique((array) ($data['apps'] ?? [])));
        if (! $all && $chosen === []) {
            throw ValidationException::withMessages(['apps' => 'Tick the apps this lesson is for, or "All apps".']);
        }
        $youtubeId = null;
        if ($data['source_type'] === 'youtube') {
            $youtubeId = YouTube::videoId($data['youtube'] ?? '');
            if ($youtubeId === null) {
                throw ValidationException::withMessages(['youtube' => 'Paste a YouTube video link (or its 11-character id).']);
            }
        }
        if ($data['source_type'] === 'url' && empty($data['video_url'])) {
            throw ValidationException::withMessages(['video_url' => 'Give the link to the video file.']);
        }
        $list = fn (?string $s, ?array $allowed = null) => array_values(array_filter(array_unique(array_map(
            fn ($v) => strtolower(trim($v)), preg_split('/[\s,]+/', (string) $s) ?: [])),
            fn ($v) => $v !== '' && preg_match('/^[a-z0-9_.-]{1,40}$/', $v) && ($allowed === null || in_array($v, $allowed, true))));

        $published = (bool) ($data['published'] ?? false);
        $lesson->fill([
            'title' => trim($data['title']), 'summary' => $data['summary'] ?? null, 'description' => $data['description'] ?? null,
            'section_id' => $this->learn->section($data['section'] ?? null)?->id,
            'all_apps' => $all, 'areas' => $list($data['areas'] ?? null) ?: null, 'audience' => $data['audience'],
            'roles' => $list($data['roles'] ?? null, array_keys((array) config('permissions.roles'))) ?: null,
            'permissions' => $list($data['permissions'] ?? null, array_keys((array) config('permissions.permissions'))) ?: null,
            'language' => $data['language'], 'source_type' => $data['source_type'], 'youtube_id' => $youtubeId,
            'video_url' => $data['source_type'] === 'url' ? $data['video_url'] : null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null, 'duration_seconds' => $this->seconds($data['duration'] ?? null) ?? $lesson->duration_seconds,
            'featured' => (bool) ($data['featured'] ?? false),
        ]);
        if (! $lesson->exists) {
            $lesson->sort = (int) LearnLesson::query()->where('section_id', $lesson->section_id)->max('sort') + 1;
        }
        if ($published && ($problem = $this->notReady($lesson)) !== null) {
            $published = false;
            admin_warning('Saved as a draft', $problem);
        }
        $lesson->published = $published;
        if ($published) {
            $lesson->published_at ??= now();
        }
        DB::transaction(function () use ($lesson, $all, $chosen) {
            $lesson->save();
            $lesson->syncApps($all ? [] : $chosen);
        });
    }

    private function notReady(LearnLesson $lesson): ?string
    {
        return match (true) {
            $lesson->source_type === 'file' && ! $lesson->file_path => 'Upload its video file first.',
            $lesson->source_type === 'youtube' && ! $lesson->youtube_id => 'It needs a YouTube link.',
            $lesson->source_type === 'url' && ! $lesson->video_url => 'It needs a link to its video.',
            default => null,
        };
    }

    /** "3:20", "1:02:03" or "200" (seconds). */
    private function seconds(?string $s): ?int
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $parts = explode(':', $s);
        if (count($parts) > 3 || array_filter($parts, fn ($p) => ! ctype_digit($p)) !== []) {
            return null;
        }
        $total = 0;
        foreach ($parts as $p) {
            $total = $total * 60 + (int) $p;
        }

        return $total;
    }
}
