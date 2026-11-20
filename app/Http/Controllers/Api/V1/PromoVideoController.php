<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PromoVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Public: an app's published promo videos (App\Models\PromoVideo), for the phone apps and any other reader.
 * Only the live copy is shown; nothing about drafts, scripts or renders.
 */
class PromoVideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $app = (string) $request->query('app', '');
        if (! preg_match('/^[a-z0-9-]{2,40}$/', $app)) {
            return response()->json(['code' => 0, 'message' => 'Say which app: ?app=pledge-and-budget', 'data' => []], 422);
        }
        $media = Storage::disk(config('promo_videos.disk'))->url('');
        $videos = PromoVideo::query()->published()->forApp($app)->get()
            // Captions as a file beside the film when the publish wrote one (a static file, cached for a year), else made here.
            ->map(function (PromoVideo $v) use ($media) {
                $a = $v->toPublicArray($media);

                return $a + ['captions_url' => $a['captions_file_url'] ?? url("api/v1/videos/{$v->app}/{$v->slug}/captions.vtt")];
            });

        return response()->json(['code' => 1, 'message' => 'Videos.', 'data' => $videos->values()])
            ->header('Cache-Control', 'public, max-age=300')->header('Access-Control-Allow-Origin', '*');
    }

    public function captions(string $app, string $slug): Response
    {
        $video = PromoVideo::query()->published()->where('app', $app)->where('slug', $slug)->firstOrFail();

        return response($video->captionsVtt(), 200, ['Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }
}
