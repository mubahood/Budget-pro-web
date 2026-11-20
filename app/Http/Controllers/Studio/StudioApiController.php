<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\PromoVideo;
use App\Models\PromoVideoRender;
use App\Services\PromoVideos\PromoStudio;
use App\Services\PromoVideos\StudioConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/studio-api: the video studio's worker (pb-studio, engine/system.py) and the system. Bearer token only
 * (App\Http\Middleware\StudioToken). It sends scripts and renders, and takes queued renders.
 */
class StudioApiController extends Controller
{
    public function __construct(private PromoStudio $studio) {}

    public function index(Request $request): JsonResponse
    {
        $q = PromoVideo::query()->orderBy('app')->orderBy('part')->orderBy('id');
        if ($request->filled('app')) {
            $q->where('app', (string) $request->query('app'));
        }

        return response()->json(['videos' => $q->get()->map(fn (PromoVideo $v) => $this->brief($v))->values()]);
    }

    public function show(string $app, string $slug): JsonResponse
    {
        return response()->json(['video' => $this->brief($this->find($app, $slug)) + ['script' => $this->find($app, $slug)->script]]);
    }

    public function upsert(Request $request, string $app, string $slug): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:160'], 'script' => ['required', 'array', 'max:60'],
            'base_rev' => ['nullable', 'integer'], 'series' => ['nullable', 'array'],
        ]);

        return $this->run(fn () => ['video' => $this->brief($this->studio->upsertFromStudio($app, $slug, $request->all()))]);
    }

    public function file(Request $request, string $app, string $slug, string $kind): JsonResponse
    {
        $video = $this->find($app, $slug);

        return $this->run(fn () => $this->studio->receiveChunk($video, $kind, (string) $request->query('upload'),
            (int) $request->query('offset'), (int) $request->query('size'), strtolower((string) $request->query('sha1')),
            (string) $request->query('ext'), $request->getContent()));
    }

    public function rendered(Request $request, string $app, string $slug): JsonResponse
    {
        $video = $this->find($app, $slug);

        return $this->run(fn () => ['video' => $this->brief($this->studio->markRendered($video, $request->all()))]);
    }

    public function claim(Request $request): JsonResponse
    {
        $job = $this->studio->claim((string) $request->input('worker', 'studio'));
        if (! $job) {
            return response()->json(['render' => null]);
        }
        $v = $job->video;

        return response()->json(['render' => ['id' => $job->id, 'app' => $v->app, 'slug' => $v->slug, 'script_rev' => $job->script_rev]]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $render = PromoVideoRender::query()->findOrFail($id);
        $request->validate(['status' => ['required', 'string'], 'log' => ['nullable', 'string']]);

        return $this->run(fn () => ['render' => ['id' => $id, 'status' => $this->studio->report($render, (string) $request->input('status'), $request->input('log'))->status]]);
    }

    private function find(string $app, string $slug): PromoVideo
    {
        return PromoVideo::query()->where('app', $app)->where('slug', $slug)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function brief(PromoVideo $v): array
    {
        return ['id' => $v->id, 'app' => $v->app, 'slug' => $v->slug, 'title' => $v->title, 'part' => $v->part,
            'script_rev' => $v->script_rev, 'rendered_rev' => $v->rendered_rev, 'state' => $v->state(),
            'published' => $v->isPublished(), 'live_rev' => $v->live_rev,
            // Stored names carry the file's sha1 prefix, so the studio can skip sending what the server already has.
            'files' => ['video' => $v->video_path ? basename($v->video_path) : null, 'poster' => $v->poster_path ? basename($v->poster_path) : null,
                'sheet' => $v->sheet_path ? basename($v->sheet_path) : null]];
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (StudioConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
