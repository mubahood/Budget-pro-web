<?php

namespace App\Services\PromoVideos;

use App\Models\PromoVideo;
use App\Models\PromoVideoRender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The rules for promo videos (config/promo_videos.php), used by the studio API (the studio's worker) and the admin
 * screen alike: scripts in, renders in, the queue, and publishing. The public only ever sees the live copy.
 */
class PromoStudio
{
    public const KINDS = ['video' => ['mp4'], 'poster' => ['jpg', 'jpeg', 'png'], 'sheet' => ['jpg', 'jpeg', 'png'], 'captions' => ['vtt']];

    private const MIME = ['video' => ['video/mp4', 'application/mp4'], 'poster' => ['image/jpeg', 'image/png'], 'sheet' => ['image/jpeg', 'image/png'],
        'captions' => ['text/plain', 'text/vtt', 'application/octet-stream']];

    public const WORKER_SEEN = 'promo-videos:worker-seen';

    public const MAX_LINE = 400;

    /**
     * The studio sends a video's script (and its whole spec). Words that changed raise script_rev. If an admin edited
     * the words since the studio last synced (base_rev behind), the studio is refused unless it forces, so edits made
     * on the website are never lost silently.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertFromStudio(string $app, string $slug, array $data): PromoVideo
    {
        $script = $this->cleanScript((array) ($data['script'] ?? []));

        return DB::transaction(function () use ($app, $slug, $data, $script) {
            $video = PromoVideo::query()->where('app', $app)->where('slug', $slug)->lockForUpdate()->first();
            $series = (array) ($data['series'] ?? []);
            $fields = [
                'local_id' => isset($data['local_id']) ? mb_substr((string) $data['local_id'], 0, 80) : null,
                'spec' => is_array($data['spec'] ?? null) ? $data['spec'] : null,
                'series' => mb_substr((string) ($series['key'] ?? 'how-it-works'), 0, 60),
                'series_title' => isset($series['title']) ? mb_substr((string) $series['title'], 0, 120) : null,
                'part' => isset($series['part']) ? max(1, (int) $series['part']) : null,
                'parts' => isset($series['total']) ? max(1, (int) $series['total']) : null,
            ];
            $title = mb_substr(trim((string) ($data['title'] ?? $slug)), 0, 160);
            if (! $video) {
                return PromoVideo::query()->create($fields + [
                    'app' => $app, 'slug' => $slug, 'script' => $script, 'script_rev' => 1, 'title' => $title,
                    'tagline' => $this->short($data['tagline'] ?? null, 200), 'description' => $this->short($data['description'] ?? null, 5000),
                ]);
            }
            $changed = $this->texts($script) !== $this->texts($video->script);
            $base = isset($data['base_rev']) ? (int) $data['base_rev'] : null;
            if ($changed && empty($data['force']) && ($base === null || $base < $video->script_rev)) {
                throw new StudioConflict('The script was edited on the website since the studio last synced (revision '.$video->script_rev.'). Pull first.');
            }
            // What an admin sets on the website wins once it exists: the title, tagline, description and part number. The
            // studio fills them only when empty, so a push never undoes an edit made in the admin.
            $video->fill([
                'part' => $video->part ?? $fields['part'], 'parts' => $video->parts ?? $fields['parts'],
                'series_title' => $video->series_title ?? $fields['series_title'],
            ] + $fields + [
                'title' => $video->title ?: $title,
                'tagline' => $video->tagline ?? $this->short($data['tagline'] ?? null, 200),
                'description' => $video->description ?? $this->short($data['description'] ?? null, 5000),
            ]);
            if ($changed || $script !== $video->script) {
                $video->script = $script;
            }
            if ($changed) {
                $video->script_rev++;
            }
            $video->save();

            return $video;
        });
    }

    /**
     * Admins change only words: the same scenes and the same number of lines, each line one sentence or a few.
     *
     * @param  array<int|string, array<int|string, string>>  $texts  [scene index][line index] => text
     */
    public function editScript(PromoVideo $video, array $texts): bool
    {
        $script = $video->script;
        foreach ($script as $si => $scene) {
            foreach ((array) $scene['lines'] as $li => $line) {
                if (! isset($texts[$si][$li])) {
                    continue;
                }
                $new = $this->line((string) $texts[$si][$li]);
                if ($new === '') {
                    throw new \DomainException('Every line needs words. Scene '.$scene['id'].', line '.($li + 1).' is empty.');
                }
                $script[$si]['lines'][$li]['text'] = $new;
            }
        }
        if ($this->texts($script) === $this->texts($video->script)) {
            return false;
        }
        $video->script = $script;
        $video->script_rev++;
        $video->save();

        return true;
    }

    /**
     * One chunk of a file from the studio. Chunks arrive in order; each names where it starts, so a resent chunk or a
     * lost one is answered with the count held ("received N") and the studio goes on from there.
     *
     * @return array{done: bool, received?: int, path?: string}
     */
    public function receiveChunk(PromoVideo $video, string $kind, string $upload, int $offset, int $size, string $sha1, string $ext, string $body): array
    {
        $ext = strtolower($ext);
        if (! isset(self::KINDS[$kind]) || ! in_array($ext, self::KINDS[$kind], true)) {
            throw new \DomainException('Unknown file kind or extension.');
        }
        if (! preg_match('/^[a-f0-9]{32}$/', $upload) || ! preg_match('/^[a-f0-9]{40}$/', $sha1)) {
            throw new \DomainException('Bad upload id or checksum.');
        }
        $max = (int) config("promo_videos.max.{$kind}");
        if ($size < 1 || $size > $max) {
            throw new \DomainException("The {$kind} must be between 1 byte and ".intdiv($max, 1048576).' MB.');
        }
        $dir = storage_path('app/promo-uploads');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $part = $dir.'/'.$upload.'.part';
        clearstatcache(true, $part);
        $have = is_file($part) ? filesize($part) : 0;
        if ($offset !== $have) {
            throw new StudioConflict("Out of order: received {$have}");
        }
        if ($have + strlen($body) > $size) {
            @unlink($part);
            throw new \DomainException('More bytes than the size given.');
        }
        file_put_contents($part, $body, FILE_APPEND | LOCK_EX);
        clearstatcache(true, $part);
        $have = filesize($part);
        if ($have < $size) {
            return ['done' => false, 'received' => $have];
        }
        try {
            if (! hash_equals($sha1, (string) sha1_file($part))) {
                throw new \DomainException('The file arrived damaged (checksum differs). Send it again.');
            }
            $result = $this->storeFile($video, $kind, $part, $sha1, $ext);
        } finally {
            @unlink($part);
        }
        $this->sweepUploads($dir);

        return $result;
    }

    /**
     * A whole file (from the studio, an admin's upload or an import) checked and put in public storage as the video's
     * current $kind. The stored name carries the first 12 characters of $sha1 (the file as sent), so it never changes
     * once written and the web server may cache it for a year. A film may be rewritten in place first (fast start).
     *
     * @return array{done: true, path: string, facts?: array<string, mixed>}
     */
    public function storeFile(PromoVideo $video, string $kind, string $file, string $sha1, string $ext): array
    {
        $ext = strtolower($ext);
        if (! isset(self::KINDS[$kind]) || ! in_array($ext, self::KINDS[$kind], true)) {
            throw new \DomainException('Unknown file kind or extension.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (! in_array($mime, self::MIME[$kind], true)) {
            throw new \DomainException("That is not a {$kind} file ({$mime}).");
        }
        if ($kind === 'captions' && ! preg_match('/^(\xEF\xBB\xBF)?WEBVTT/', (string) file_get_contents($file, false, null, 0, 16))) {
            throw new \DomainException('That is not a captions file: a .vtt file starts with WEBVTT.');
        }
        // A film whose index sits at its end cannot start playing (or seek) until all of it has arrived: move it to the front.
        $facts = $kind === 'video' ? $this->prepareVideo($file) : null;
        $path = config('promo_videos.dir').'/'.$video->app.'/'.$video->slug.'-'.$kind.'-'.substr($sha1, 0, 12).'.'.($ext === 'jpeg' ? 'jpg' : $ext);
        $disk = Storage::disk(config('promo_videos.disk'));
        $stream = fopen($file, 'rb');
        $disk->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        clearstatcache(true, $file);
        $old = $video->{$kind.'_path'};
        $video->{$kind.'_path'} = $path;
        if ($kind === 'video') {
            $video->video_size = (int) filesize($file);
            $video->video_sha1 = $sha1;
        }
        $video->save();
        $this->forget($video, $old);

        return ['done' => true, 'path' => $path] + ($facts ? ['facts' => $facts] : []);
    }

    /** @param array<string, mixed> $data  the render's facts, from the studio's meta.json and timeline */
    public function markRendered(PromoVideo $video, array $data): PromoVideo
    {
        if (! $video->video_path) {
            throw new \DomainException('Send the video file first.');
        }
        $rev = (int) ($data['script_rev'] ?? 0);
        if ($rev < 1 || $rev > $video->script_rev) {
            throw new \DomainException('That script revision does not exist.');
        }
        $transcript = array_values(array_map(fn ($l) => [
            'scene' => mb_substr((string) ($l['scene'] ?? ''), 0, 40), 'start' => round((float) ($l['start'] ?? 0), 2),
            'dur' => round((float) ($l['dur'] ?? 0), 2), 'speaker' => mb_substr((string) ($l['speaker'] ?? 'narrator'), 0, 40),
            'text' => $this->line((string) ($l['text'] ?? '')),
        ], array_slice((array) ($data['transcript'] ?? []), 0, 400)));
        $qa = is_array($data['qa'] ?? null) ? array_intersect_key($data['qa'], array_flip(['pass', 'duration', 'lufs', 'true_peak', 'fails', 'warnings'])) : null;
        $video->fill([
            'rendered_rev' => $rev, 'duration' => isset($data['duration']) ? round((float) $data['duration'], 2) : null,
            'width' => (int) ($data['width'] ?? 1080), 'height' => (int) ($data['height'] ?? 1920),
            'voice' => isset($data['voice']) ? mb_substr((string) $data['voice'], 0, 120) : null,
            'qa' => $qa, 'transcript' => $transcript, 'rendered_at' => now(),
        ]);
        // A studio render brings its own timings, which make the captions; a .vtt uploaded for an older cut would not match.
        $old = $video->captions_path;
        $video->captions_path = null;
        $video->save();
        $this->forget($video, $old);

        return $video;
    }

    public function queueRender(PromoVideo $video, ?int $by): PromoVideoRender
    {
        return DB::transaction(function () use ($video, $by) {
            $busy = PromoVideoRender::query()->where('promo_video_id', $video->id)
                ->whereIn('status', [PromoVideoRender::QUEUED, PromoVideoRender::RUNNING])->lockForUpdate()->exists();
            if ($busy) {
                throw new \DomainException('A render of this video is already waiting or running.');
            }

            return PromoVideoRender::query()->create([
                'promo_video_id' => $video->id, 'status' => PromoVideoRender::QUEUED, 'script_rev' => $video->script_rev, 'requested_by' => $by,
            ]);
        });
    }

    /** The studio's worker asks for the next job; renders left "running" too long are failed first. */
    public function claim(string $worker): ?PromoVideoRender
    {
        Cache::put(self::WORKER_SEEN, ['at' => now()->toIso8601String(), 'worker' => mb_substr($worker, 0, 120)], now()->addDays(30));
        PromoVideoRender::query()->where('status', PromoVideoRender::RUNNING)
            ->where('started_at', '<', now()->subMinutes((int) config('promo_videos.stale_minutes', 90)))
            ->update(['status' => PromoVideoRender::FAILED, 'finished_at' => now(), 'log' => 'The worker stopped answering; queue it again.']);

        return DB::transaction(function () use ($worker) {
            $job = PromoVideoRender::query()->where('status', PromoVideoRender::QUEUED)->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            $job->fill(['status' => PromoVideoRender::RUNNING, 'worker' => mb_substr($worker, 0, 120), 'started_at' => now()])->save();

            return $job;
        });
    }

    public function report(PromoVideoRender $render, string $status, ?string $log): PromoVideoRender
    {
        if (! in_array($status, [PromoVideoRender::RUNNING, PromoVideoRender::DONE, PromoVideoRender::FAILED], true)) {
            throw new \DomainException('Unknown status.');
        }
        if (in_array($render->status, [PromoVideoRender::DONE, PromoVideoRender::FAILED], true)) {
            throw new \DomainException('That render has already finished.');
        }
        $render->fill(['status' => $status, 'log' => $log === null ? $render->log : mb_substr($log, -20000),
            'finished_at' => $status === PromoVideoRender::RUNNING ? null : now()])->save();

        return $render;
    }

    public function publish(PromoVideo $video, ?int $by): void
    {
        if (! $video->renderIsCurrent()) {
            throw new \DomainException('Render the current words first: the latest film does not match the script, or did not pass its checks.');
        }
        $old = [$video->live_video_path, $video->live_poster_path, $video->live_captions_path];
        $video->fill([
            'live_video_path' => $video->video_path, 'live_poster_path' => $video->poster_path, 'live_video_size' => $video->video_size,
            'live_captions_path' => $video->captions_path ?? $this->captionsFromTranscript($video),
            'live_duration' => $video->duration, 'live_width' => $video->width, 'live_height' => $video->height,
            'live_transcript' => $video->transcript, 'live_rev' => $video->rendered_rev,
            'published_at' => now(), 'published_by' => $by,
        ])->save();
        foreach ($old as $p) {
            $this->forget($video, $p);
        }
        $this->refreshSites();
    }

    public function unpublish(PromoVideo $video): void
    {
        $video->published_at = null;
        $video->save();
        $this->refreshSites();
    }

    /**
     * A film uploaded by a platform admin (no studio): it is the current render of the current words, so it can be
     * published as it is. Its length and size come from the file itself.
     *
     * @param  array{duration?: float|null, width?: int|null, height?: int|null}  $facts
     */
    public function acceptUploadedFilm(PromoVideo $video, array $facts): void
    {
        $video->fill([
            'rendered_rev' => $video->script_rev, 'qa' => ['pass' => true, 'fails' => 0, 'warnings' => 0],
            'duration' => $facts['duration'] ?? $video->duration, 'width' => $facts['width'] ?? $video->width ?? 1080,
            'height' => $facts['height'] ?? $video->height ?? 1920, 'rendered_at' => now(),
        ])->save();
    }

    /** A film made outside the studio: an admin names it, then uploads its file. */
    public function create(string $app, string $slug, string $title, ?int $part = null): PromoVideo
    {
        if (PromoVideo::query()->where('app', $app)->where('slug', $slug)->exists()) {
            throw new \DomainException('That app already has a video at /videos/'.$slug.'.');
        }

        return PromoVideo::query()->create(['app' => $app, 'slug' => $slug, 'title' => $title, 'part' => $part, 'script' => [], 'script_rev' => 1]);
    }

    /**
     * The words and place an admin sets (title, tagline, description, app, part, order, featured). They show at once
     * wherever the film is published; no render needed.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(PromoVideo $video, array $data): void
    {
        DB::transaction(function () use ($video, $data) {
            $app = (string) ($data['app'] ?? $video->app);
            if ($app !== $video->app && PromoVideo::query()->where('app', $app)->where('slug', $video->slug)->exists()) {
                throw new \DomainException('That app already has a video at /videos/'.$video->slug.'.');
            }
            $video->fill([
                'app' => $app, 'title' => trim((string) $data['title']), 'tagline' => $this->short($data['tagline'] ?? null, 200),
                'description' => $this->short($data['description'] ?? null, 5000),
                'part' => ($data['part'] ?? null) !== null && $data['part'] !== '' ? max(1, (int) $data['part']) : null,
                'parts' => ($data['parts'] ?? null) !== null && $data['parts'] !== '' ? max(1, (int) $data['parts']) : null,
                'series_title' => $this->short($data['series_title'] ?? null, 120),
                'sort' => (int) ($data['sort'] ?? 0),
            ])->save();
            if (array_key_exists('featured', $data)) {
                $this->feature($video, (bool) $data['featured']);
            }
        });
        $this->refreshSites();
    }

    /** One film per app leads its home page: marking one unmarks the rest of that app's. */
    public function feature(PromoVideo $video, bool $on): void
    {
        DB::transaction(function () use ($video, $on) {
            if ($on) {
                PromoVideo::query()->where('app', $video->app)->where('id', '!=', $video->id)->where('featured', true)->update(['featured' => false]);
            }
            $video->featured = $on;
            $video->save();
        });
    }

    /**
     * The app websites keep some public pages as files (budget-pro-new's `site:static`), which would go on showing the
     * old list of films. Removing them is safe: PHP serves those pages until the next deploy writes them again.
     */
    public function refreshSites(): void
    {
        $root = (string) config('promo_videos.page_cache');
        if ($root === '' || ! is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === 'page.html') {
                @unlink($file->getPathname());
            }
        }
    }

    /** @return array{faststart: bool, duration: float|null, width: int|null, height: int|null, moved: bool} */
    public function prepareVideo(string $file): array
    {
        $moved = Mp4::faststart($file);
        clearstatcache(true, $file);

        return Mp4::inspect($file) + ['moved' => $moved];
    }

    /** The published copy's captions as a file beside the film (served by the web server, cached for a year). */
    private function captionsFromTranscript(PromoVideo $video): ?string
    {
        if (empty($video->transcript)) {
            return null;
        }
        $vtt = PromoVideo::vtt((array) $video->transcript);
        $path = config('promo_videos.dir').'/'.$video->app.'/'.$video->slug.'-captions-'.substr(sha1($vtt), 0, 12).'.vtt';
        Storage::disk(config('promo_videos.disk'))->put($path, $vtt);

        return $path;
    }

    /** @return array{at: string, worker: string}|null */
    public function workerSeen(): ?array
    {
        $seen = Cache::get(self::WORKER_SEEN);

        return is_array($seen) ? $seen : null;
    }

    /** Delete a file no column points at any more. */
    private function forget(PromoVideo $video, ?string $path): void
    {
        if (! $path) {
            return;
        }
        foreach (['video_path', 'poster_path', 'sheet_path', 'captions_path', 'live_video_path', 'live_poster_path', 'live_captions_path'] as $col) {
            if ($video->{$col} === $path) {
                return;
            }
        }
        Storage::disk(config('promo_videos.disk'))->delete($path);
    }

    /** Half-sent files older than a day. */
    private function sweepUploads(string $dir): void
    {
        foreach (glob($dir.'/*.part') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $scenes
     * @return list<array{id: string, template: string|null, lines: list<array{speaker: string, text: string}>}>
     */
    private function cleanScript(array $scenes): array
    {
        if ($scenes === [] || count($scenes) > 60) {
            throw new \DomainException('A script needs 1 to 60 scenes.');
        }
        $out = [];
        foreach (array_values($scenes) as $s) {
            $lines = [];
            foreach (array_slice(array_values((array) ($s['lines'] ?? [])), 0, 40) as $l) {
                $lines[] = ['speaker' => mb_substr((string) ($l['speaker'] ?? 'narrator'), 0, 40), 'text' => $this->line((string) ($l['text'] ?? ''))];
            }
            $out[] = ['id' => mb_substr((string) ($s['id'] ?? ''), 0, 40), 'template' => isset($s['template']) ? mb_substr((string) $s['template'], 0, 60) : null, 'lines' => $lines];
        }

        return $out;
    }

    /** @param array<int, mixed> $script */
    private function texts(array $script): array
    {
        return array_map(fn ($s) => array_column((array) ($s['lines'] ?? []), 'text'), array_values($script));
    }

    private function line(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_LINE);
    }

    private function short(mixed $v, int $max): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
