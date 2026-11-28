<?php

namespace App\Models\Learn;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One training video. It is for all apps (all_apps) or for the apps listed in learn_lesson_apps; for the people its
 * audience names; and, when it has areas, it is the "How this works" help for those screens.
 * The video is a YouTube id, a link to a file elsewhere, or a file uploaded to public storage.
 */
class LearnLesson extends Model
{
    public const AUDIENCES = ['public' => 'Anyone, signed out too', 'everyone' => 'Everyone signed in', 'officers' => 'Officers and staff', 'members' => 'Members only'];

    public const SOURCES = ['youtube', 'file', 'url'];

    protected $table = 'learn_lessons';

    protected $guarded = ['id'];

    protected $casts = [
        'all_apps' => 'boolean', 'published' => 'boolean', 'featured' => 'boolean',
        'areas' => 'array', 'roles' => 'array', 'permissions' => 'array',
        'duration_seconds' => 'integer', 'file_size' => 'integer', 'sort' => 'integer', 'section_id' => 'integer',
        'published_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(LearnSection::class, 'section_id');
    }

    public function appRows(): HasMany
    {
        return $this->hasMany(LearnLessonApp::class, 'lesson_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LearnProgress::class, 'lesson_id');
    }

    /** @return array<int, string> */
    public function apps(): array
    {
        return $this->appRows->pluck('app')->sort()->values()->all();
    }

    /** @param  array<int, string>  $apps */
    public function syncApps(array $apps): void
    {
        $apps = array_values(array_unique(array_filter($apps)));
        $this->appRows()->whereNotIn('app', $apps ?: ['-'])->delete();
        foreach ($apps as $app) {
            LearnLessonApp::query()->firstOrCreate(['lesson_id' => $this->id, 'app' => $app]);
        }
        $this->unsetRelation('appRows');
    }

    /** Published lessons for one app (its own, plus those for all apps). */
    public function scopeForApp(Builder $q, string $app): Builder
    {
        return $q->where('published', true)->where(fn (Builder $w) => $w->where('all_apps', true)
            ->orWhereIn('id', LearnLessonApp::query()->select('lesson_id')->where('app', $app)));
    }

    public function thumbnail(): ?string
    {
        if ($this->thumbnail_path) {
            return Storage::disk(config('learn.disk'))->url($this->thumbnail_path);
        }
        if ($this->thumbnail_url) {
            return $this->thumbnail_url;
        }

        return $this->source_type === 'youtube' && $this->youtube_id ? 'https://i.ytimg.com/vi/'.$this->youtube_id.'/hqdefault.jpg' : null;
    }

    /** Where the video plays from: a YouTube watch link, the uploaded file, or the link given. */
    public function playUrl(): ?string
    {
        return match ($this->source_type) {
            'youtube' => $this->youtube_id ? 'https://www.youtube.com/watch?v='.$this->youtube_id : null,
            'file' => $this->file_path ? Storage::disk(config('learn.disk'))->url($this->file_path) : null,
            default => $this->video_url,
        };
    }

    public function length(): ?string
    {
        $s = (int) $this->duration_seconds;

        if ($s <= 0) {
            return null;
        }

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
