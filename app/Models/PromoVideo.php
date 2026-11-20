<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promo video of one app (config/promo_videos.php). The studio sends the script and each render; a platform admin
 * edits the spoken words (script_rev goes up), queues a render, and publishes: the current render is copied to the
 * `live_*` columns, which are all the public ever sees.
 *
 * @property int $id
 * @property string $app
 * @property string $slug
 * @property string $series
 * @property string|null $series_title
 * @property int|null $part
 * @property int|null $parts
 * @property string $title
 * @property string|null $tagline
 * @property string|null $description
 * @property array<int, array{id: string, template?: string|null, lines: list<array{speaker: string, text: string}>}> $script
 * @property int $script_rev
 * @property int|null $rendered_rev
 * @property string|null $video_path
 * @property string|null $poster_path
 * @property string|null $sheet_path
 * @property array<string, mixed>|null $qa
 * @property list<array{scene: string, start: float, dur: float, speaker: string, text: string}>|null $transcript
 * @property list<array{scene: string, start: float, dur: float, speaker: string, text: string}>|null $live_transcript
 * @property string|null $live_video_path
 * @property string|null $live_poster_path
 * @property string|null $captions_path
 * @property string|null $live_captions_path
 * @property bool $featured
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class PromoVideo extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'spec' => 'array', 'script' => 'array', 'qa' => 'array', 'transcript' => 'array', 'live_transcript' => 'array',
        'part' => 'integer', 'parts' => 'integer', 'script_rev' => 'integer', 'rendered_rev' => 'integer', 'live_rev' => 'integer',
        'duration' => 'float', 'live_duration' => 'float', 'video_size' => 'integer', 'live_video_size' => 'integer',
        'width' => 'integer', 'height' => 'integer', 'live_width' => 'integer', 'live_height' => 'integer', 'sort' => 'integer',
        'featured' => 'boolean', 'rendered_at' => 'datetime', 'published_at' => 'datetime',
    ];

    public function renders(): HasMany
    {
        return $this->hasMany(PromoVideoRender::class)->orderByDesc('id');
    }

    /** @param Builder<PromoVideo> $q */
    public function scopePublished(Builder $q): void
    {
        $q->whereNotNull('published_at')->whereNotNull('live_video_path');
    }

    /** @param Builder<PromoVideo> $q */
    public function scopeForApp(Builder $q, string $app): void
    {
        $q->where('app', $app)->orderBy('series')->orderByRaw('part is null')->orderBy('part')->orderBy('sort')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->live_video_path !== null;
    }

    /** The current render matches the current script and passed the studio's checks. */
    public function renderIsCurrent(): bool
    {
        return $this->video_path !== null && $this->rendered_rev === $this->script_rev && (bool) ($this->qa['pass'] ?? false);
    }

    /**
     * Publishing would change what the public sees: the film is not live, or its file, poster or uploaded captions
     * differ from the live copy's.
     */
    public function hasUnpublishedRender(): bool
    {
        return $this->renderIsCurrent() && (! $this->isPublished() || $this->video_path !== $this->live_video_path
            || $this->poster_path !== $this->live_poster_path || ($this->captions_path !== null && $this->captions_path !== $this->live_captions_path));
    }

    /** One word for lists: draft (words changed, no render of them yet), queued, rendering, ready (to publish), live. */
    public function state(): string
    {
        $r = $this->relationLoaded('renders') ? $this->renders->first() : $this->renders()->first();
        if ($r && in_array($r->status, [PromoVideoRender::QUEUED, PromoVideoRender::RUNNING], true)) {
            return $r->status === PromoVideoRender::QUEUED ? 'queued' : 'rendering';
        }
        if (! $this->renderIsCurrent()) {
            return 'draft';
        }

        return $this->isPublished() && ! $this->hasUnpublishedRender() ? 'live' : 'ready';
    }

    /** "Part 3 of 7", or null outside a series. */
    public function partLabel(): ?string
    {
        return $this->part ? 'Part '.$this->part.($this->parts ? ' of '.$this->parts : '') : null;
    }

    /** Every spoken line, in order, as plain text. */
    public function spokenLines(): array
    {
        return collect($this->script)->flatMap(fn ($s) => array_column((array) ($s['lines'] ?? []), 'text'))->values()->all();
    }

    /**
     * What a public reader (an app's website, a phone app) gets: only the published copy.
     *
     * @param  string  $media  the public-storage base URL to build file links on (no trailing slash)
     * @return array<string, mixed>
     */
    public function toPublicArray(string $media): array
    {
        // An APP_URL with a trailing slash makes the disk's url "…//storage": one slash between parts, the scheme's kept.
        $url = fn (?string $p) => $p ? preg_replace('#(?<!:)/{2,}#', '/', rtrim($media, '/').'/'.ltrim($p, '/')) : null;

        return [
            'app' => $this->app, 'slug' => $this->slug, 'title' => $this->title, 'tagline' => $this->tagline,
            'description' => $this->description, 'series' => $this->series, 'series_title' => $this->series_title,
            'part' => $this->part, 'parts' => $this->parts,
            'duration' => $this->live_duration, 'width' => $this->live_width, 'height' => $this->live_height,
            'video_url' => $url($this->live_video_path), 'poster_url' => $url($this->live_poster_path),
            'captions_file_url' => $url($this->live_captions_path), 'featured' => (bool) $this->featured,
            'size' => $this->live_video_size, 'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /** WebVTT for the published film: its captions file, or one made from the studio's own timings. */
    public function captionsVtt(): string
    {
        if ($this->live_captions_path) {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('promo_videos.disk'));
            if ($disk->exists($this->live_captions_path)) {
                return (string) $disk->get($this->live_captions_path);
            }
        }

        return self::vtt((array) $this->live_transcript);
    }

    /**
     * WebVTT from timed lines (start and length in seconds from the film's start).
     *
     * @param  array<int, array{start?: float|int, dur?: float|int, text?: string}>  $lines
     */
    public static function vtt(array $lines): string
    {
        $t = function (float $s): string {
            $ms = (int) round($s * 1000);

            return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
        };
        $out = "WEBVTT\n";
        foreach (array_values($lines) as $i => $l) {
            $start = (float) ($l['start'] ?? 0);
            $out .= "\n".($i + 1)."\n".$t($start).' --> '.$t($start + (float) ($l['dur'] ?? 0) + 0.25)."\n"
                .str_replace(["\r", "\n", '-->'], [' ', ' ', '->'], (string) ($l['text'] ?? ''))."\n";
        }

        return $out;
    }
}
