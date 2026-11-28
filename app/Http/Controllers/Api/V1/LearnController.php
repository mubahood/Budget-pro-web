<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Learn\LearnLesson;
use App\Models\User;
use App\Services\Learn\Learn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Learn: the training videos for the app the phone names (X-App header, or ?app=). docs/LEARN_VIDEOS.md has the contract.
 * Reading works signed out (public lessons only); with a token it adds the person's lessons, progress and "new" marks.
 */
class LearnController extends Controller
{
    public function __construct(private readonly Learn $learn)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $app = $this->app($request);
        if ($app === null) {
            return $this->noApp();
        }
        $data = $this->learn->library($app, $this->user(), $this->text($request, 'area', 40), $this->text($request, 'q', 80), $this->lang($request));

        return response()->json(['code' => 1, 'message' => 'Lessons.', 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $app = $this->app($request);
        if ($app === null) {
            return $this->noApp();
        }
        $data = $this->learn->lesson($id, $app, $this->user(), $this->lang($request));
        if ($data === null) {
            return response()->json(['code' => 0, 'message' => 'This lesson is not available here.', 'data' => null], 404);
        }

        return response()->json(['code' => 1, 'message' => 'Lesson.', 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function progress(Request $request, int $id): JsonResponse
    {
        $app = $this->app($request);
        if ($app === null) {
            return $this->noApp();
        }
        $data = $request->validate([
            'position' => ['required', 'numeric', 'min:0', 'max:86400'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'watched' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'completed' => ['nullable', 'boolean'],
            'started' => ['nullable', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $lesson = LearnLesson::query()->find($id);
        // Only a lesson this person can see in this app takes progress.
        if ($lesson === null || $this->learn->lesson($id, $app, $user) === null) {
            return response()->json(['code' => 0, 'message' => 'This lesson is not available here.', 'data' => null], 404);
        }
        $p = $this->learn->record($lesson, $user, $app, $data);

        return response()->json(['code' => 1, 'message' => 'Progress saved.', 'data' => ['lesson_id' => $lesson->id, 'progress' => $p->toApi()]]);
    }

    public function seen(Request $request): JsonResponse
    {
        $app = $this->app($request);
        if ($app === null) {
            return $this->noApp();
        }
        $at = $this->learn->markSeen($request->user(), $app);

        return response()->json(['code' => 1, 'message' => 'Seen.', 'data' => ['last_seen_at' => $at->toIso8601String()]]);
    }

    private function app(Request $request): ?string
    {
        return Learn::app((string) ($request->header('X-App') ?: $request->query('app', '')));
    }

    private function noApp(): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => 'Say which app: the X-App header or ?app=, e.g. family-roots.', 'data' => null], 422);
    }

    /** The signed-in person when a valid token came with the request; null otherwise (these reads are open). */
    private function user(): ?User
    {
        $user = Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    private function text(Request $request, string $key, int $max): ?string
    {
        $v = $request->query($key);

        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
    }

    private function lang(Request $request): ?string
    {
        $v = (string) $request->query('lang', '');

        return preg_match('/^[a-z]{2,3}$/', $v) ? $v : null;
    }
}
