<?php

namespace App\Console\Commands;

use App\Models\PromoVideo;
use App\Services\PromoVideos\PromoStudio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads finished promo films from a folder (an "upload kit" exported on the studio Mac) into promo_videos, with no
 * studio and no ffmpeg on this host. Each sub-folder holds video.mp4, poster.jpg, captions.vtt and meta.json:
 *
 *   {"slug": "the-question", "title": "...", "tagline": "...", "description": "...", "sort": 1,
 *    "series": {"key": "how-it-works", "title": "...", "part": 1, "total": 7},
 *    "script": [{"id": "hook", "lines": [{"speaker": "...", "text": "..."}]}],
 *    "transcript": [{"scene": "hook", "start": 0.6, "dur": 2.4, "speaker": "...", "text": "..."}]}
 *
 * A film is matched by app and slug. What an admin already set (title, tagline, description, part) is kept unless
 * --overwrite; files the film already has (same content) are not copied again. Films are moved to fast start here.
 */
class PromoVideosImport extends Command
{
    protected $signature = 'promo-videos:import {dir : a folder with one sub-folder per film}
        {--app=pledge-and-budget : the app the films belong to}
        {--publish : publish each film once it is in}
        {--feature= : the slug of the film to lead the app\'s home page}
        {--overwrite : replace the title, tagline, description and part an admin already set}';

    protected $description = 'Import (and optionally publish) promo films from an exported folder: video.mp4, poster.jpg, captions.vtt, meta.json';

    public function handle(PromoStudio $studio): int
    {
        $app = (string) $this->option('app');
        if (! preg_match('/^[a-z0-9-]{2,40}$/', $app)) {
            $this->error('--app must be an app key such as pledge-and-budget.');

            return self::FAILURE;
        }
        $dir = rtrim((string) $this->argument('dir'), '/');
        $folders = glob($dir.'/*/meta.json') ?: [];
        sort($folders);
        if (! $folders) {
            $this->error("No film folders (with meta.json) in {$dir}.");

            return self::FAILURE;
        }
        $rows = [];
        $failed = 0;
        foreach ($folders as $metaFile) {
            $folder = dirname($metaFile);
            try {
                $rows[] = $this->importOne($studio, $app, $folder, (array) json_decode((string) file_get_contents($metaFile), true, 32, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = [basename($folder), '', 'FAILED: '.$e->getMessage(), '', ''];
            }
        }
        if ($slug = $this->option('feature')) {
            $film = PromoVideo::query()->where('app', $app)->where('slug', (string) $slug)->first();
            $film ? $studio->feature($film, true) : $this->warn("No film {$slug} to feature.");
        }
        $studio->refreshSites();
        $this->table(['Film', 'Part', 'Files', 'Length', 'State'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $meta */
    private function importOne(PromoStudio $studio, string $app, string $folder, array $meta): array
    {
        $slug = (string) ($meta['slug'] ?? '');
        if (! preg_match('/^[a-z0-9-]{2,80}$/', $slug) || ! is_file($folder.'/video.mp4')) {
            throw new \DomainException('meta.json needs a slug, and the folder a video.mp4.');
        }
        $overwrite = (bool) $this->option('overwrite');
        $series = (array) ($meta['series'] ?? []);
        $text = fn (string $k, int $max) => isset($meta[$k]) && trim((string) $meta[$k]) !== '' ? mb_substr(trim((string) $meta[$k]), 0, $max) : null;

        $video = DB::transaction(function () use ($app, $slug, $meta, $series, $text, $overwrite) {
            $video = PromoVideo::query()->where('app', $app)->where('slug', $slug)->lockForUpdate()->first()
                ?? new PromoVideo(['app' => $app, 'slug' => $slug, 'script' => [], 'script_rev' => 1]);
            $keep = fn (string $col, mixed $new) => ($overwrite || $video->{$col} === null || $video->{$col} === '') ? $new : $video->{$col};
            $video->fill([
                'title' => $keep('title', $text('title', 160) ?? $slug),
                'tagline' => $keep('tagline', $text('tagline', 200)),
                'description' => $keep('description', $text('description', 5000)),
                'series' => mb_substr((string) ($series['key'] ?? $video->series ?? 'how-it-works'), 0, 60),
                'series_title' => $keep('series_title', isset($series['title']) ? mb_substr((string) $series['title'], 0, 120) : null),
                'part' => $keep('part', isset($series['part']) ? max(1, (int) $series['part']) : null),
                'parts' => $keep('parts', isset($series['total']) ? max(1, (int) $series['total']) : null),
                'sort' => $video->exists && ! $overwrite ? $video->sort : (int) ($meta['sort'] ?? 0),
            ]);
            // The spoken words, so the admin can edit them and the studio can re-render; only on a new film.
            if (! $video->exists && is_array($meta['script'] ?? null)) {
                $video->script = array_values($meta['script']);
            }
            $video->save();

            return $video;
        });

        $sent = [];
        foreach (['video' => 'video.mp4', 'poster' => 'poster.jpg', 'captions' => 'captions.vtt'] as $kind => $name) {
            $src = $folder.'/'.$name;
            if (! is_file($src)) {
                continue;
            }
            $sha = (string) sha1_file($src);
            $current = $video->{$kind.'_path'};
            if ($current && str_contains(basename($current), '-'.$kind.'-'.substr($sha, 0, 12).'.')) {
                continue; // already has this very file
            }
            // A copy: the fast-start step rewrites the file it is given, and the kit is left as it was.
            $tmp = tempnam(sys_get_temp_dir(), 'promo');
            copy($src, $tmp);
            try {
                $r = $studio->storeFile($video, $kind, $tmp, $sha, pathinfo($name, PATHINFO_EXTENSION));
            } finally {
                @unlink($tmp);
            }
            $sent[] = $kind;
            if ($kind === 'video') {
                $facts = (array) ($r['facts'] ?? []);
                $video->fill([
                    'rendered_rev' => $video->script_rev, 'qa' => ['pass' => true, 'fails' => 0, 'warnings' => 0], 'rendered_at' => now(),
                    'duration' => isset($meta['duration']) ? round((float) $meta['duration'], 2) : ($facts['duration'] ?? null),
                    'width' => (int) ($meta['width'] ?? $facts['width'] ?? 1080), 'height' => (int) ($meta['height'] ?? $facts['height'] ?? 1920),
                    'transcript' => is_array($meta['transcript'] ?? null) ? array_values($meta['transcript']) : $video->transcript,
                ])->save();
            }
        }
        if ($this->option('publish')) {
            $studio->publish($video, null);
        }
        $video->refresh();

        return [$slug, (string) ($video->part ?? ''), $sent ? implode(', ', $sent) : 'unchanged',
            $video->duration ? gmdate('i:s', (int) round($video->duration)) : '', $video->state()];
    }
}
