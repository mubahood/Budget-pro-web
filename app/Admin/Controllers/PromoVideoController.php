<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PromoVideo;
use App\Services\PromoVideos\PromoStudio;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform › Promo videos: every app's films. From the video studio (change the spoken words, queue a render that the
 * studio's worker makes) or with no studio at all (upload the film, its poster and its captions here, in chunks, so
 * the host's upload limit never matters). Then publish to the app's website and the phone apps, and mark one film as
 * the app's featured one for its home page. Routed in the platform group (edition:saas + admin.platform:all): a 404
 * on a standalone copy, a 403 for shop users.
 */
class PromoVideoController extends Controller
{
    public function index(Content $content, PromoStudio $studio)
    {
        $videos = PromoVideo::query()->with(['renders' => fn ($q) => $q->limit(1)])
            ->orderBy('app')->orderBy('series')->orderByRaw('part is null')->orderBy('part')->orderBy('id')->get();

        return $content->title('Promo videos')->description('Films for each app: words, renders, and what the public sees')
            ->body(view('admin.promo-videos', ['groups' => $videos->groupBy('app'), 'worker' => $studio->workerSeen(), 'apps' => $this->apps()]));
    }

    /** A film made outside the studio: name it here, then upload its file on its page. */
    public function store(Request $request, PromoStudio $studio)
    {
        $data = $request->validate([
            'app' => ['required', 'string', 'regex:/^[a-z0-9-]{2,40}$/'], 'slug' => ['required', 'string', 'regex:/^[a-z0-9-]{2,80}$/'],
            'title' => ['required', 'string', 'max:160'], 'part' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        try {
            $video = $studio->create($data['app'], $data['slug'], trim($data['title']), isset($data['part']) ? (int) $data['part'] : null);
        } catch (\DomainException $e) {
            admin_error('Not done', $e->getMessage());

            return redirect(admin_url('promo-videos'))->withInput();
        }
        admin_success('Video added', 'Upload its film, poster and captions below, then publish it.');

        return redirect(admin_url('promo-videos/'.$video->id));
    }

    /**
     * One chunk of a film, poster or captions file from this page's uploader (the same rules as the studio's: in
     * order, whole, of the right kind). A film arrives with its index moved to the front and its length read from it,
     * ready to publish.
     */
    public function upload(Request $request, int $id, string $kind, PromoStudio $studio): JsonResponse
    {
        $video = PromoVideo::query()->findOrFail($id);
        try {
            $r = $studio->receiveChunk($video, $kind, (string) $request->query('upload'), (int) $request->query('offset'),
                (int) $request->query('size'), strtolower((string) $request->query('sha1')), (string) $request->query('ext'), $request->getContent());
            if (($r['done'] ?? false) && $kind === 'video') {
                $studio->acceptUploadedFilm($video->refresh(), (array) ($r['facts'] ?? []));
            }

            return response()->json($r);
        } catch (\App\Services\PromoVideos\StudioConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Content $content, int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->with(['renders' => fn ($q) => $q->limit(8)])->findOrFail($id);

        return $content->title($video->title)->description(trim(($video->partLabel() ?? '').' · '.$video->app, ' ·'))
            ->body(view('admin.promo-video', ['v' => $video, 'worker' => $studio->workerSeen(), 'apps' => $this->apps(),
                'max' => (array) config('promo_videos.max'),
                'media' => fn (?string $p) => $p ? \Storage::disk(config('promo_videos.disk'))->url($p) : null]));
    }

    public function details(Request $request, int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'], 'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'app' => ['required', 'string', 'regex:/^[a-z0-9-]{2,40}$/'], 'part' => ['nullable', 'integer', 'min:1', 'max:99'],
            'parts' => ['nullable', 'integer', 'min:1', 'max:99'], 'series_title' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->act($id, fn () => $studio->updateDetails($video, $data + ['featured' => $request->boolean('featured')]), 'Saved',
            'The title, words, part and order show at once wherever the video is published.');
    }

    /** Lead the app's home page with this film (or stop). */
    public function feature(Request $request, int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);
        $on = $request->boolean('on', true);

        return $this->act($id, function () use ($studio, $video, $on) {
            $studio->feature($video, $on);
            $studio->refreshSites();
        }, $on ? 'Featured' : 'No longer featured', $on ? 'It leads the app\'s home page while it is published.' : 'The home page shows the first part instead.');
    }

    /** @return array<string, string> app key => name: the known apps, and any other app a film already belongs to */
    private function apps(): array
    {
        $apps = (array) config('promo_videos.apps');
        foreach (PromoVideo::query()->distinct()->pluck('app') as $key) {
            $apps[$key] ??= $key;
        }

        return $apps;
    }

    public function script(Request $request, int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);
        $texts = (array) $request->input('lines', []);

        return $this->act($id, function () use ($studio, $video, $texts) {
            if (! $studio->editScript($video, $texts)) {
                throw new \DomainException('No words changed.');
            }
        }, 'Words saved', 'Queue a render to hear them. What the public sees stays as it is until you publish the new film.');
    }

    public function render(int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);

        return $this->act($id, fn () => $studio->queueRender($video, Admin::user()?->id), 'Render queued',
            'The studio worker takes it within a minute while it runs (./studio worker), and needs about two minutes per film.');
    }

    public function publish(int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);

        return $this->act($id, fn () => $studio->publish($video, Admin::user()?->id), 'Published',
            'It is on the app\'s website (/videos) and in the phone apps\' video list now.');
    }

    public function unpublish(int $id, PromoStudio $studio)
    {
        $video = PromoVideo::query()->findOrFail($id);

        return $this->act($id, fn () => $studio->unpublish($video), 'Taken down', 'The video no longer shows anywhere public. Publish it again any time.');
    }

    private function act(int $id, callable $action, string $title, string $message)
    {
        $back = redirect(admin_url('promo-videos/'.$id));
        try {
            $action();
        } catch (\DomainException|\RuntimeException $e) {
            admin_error('Not done', $e->getMessage());

            return $back->withInput();
        }
        admin_success($title, $message);

        return $back;
    }
}
