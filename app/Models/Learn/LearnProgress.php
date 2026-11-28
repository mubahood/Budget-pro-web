<?php

namespace App\Models\Learn;

use Illuminate\Database\Eloquent\Model;

/** One person's progress on one lesson: where they stopped, how far they got, whether they finished. */
class LearnProgress extends Model
{
    protected $table = 'learn_progress';

    protected $guarded = ['id'];

    protected $casts = [
        'position_seconds' => 'integer', 'furthest_seconds' => 'integer', 'watched_seconds' => 'integer',
        'duration_seconds' => 'integer', 'views' => 'integer',
        'completed_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime',
    ];

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'position' => $this->position_seconds, 'furthest' => $this->furthest_seconds,
            'watched_seconds' => $this->watched_seconds, 'completed' => $this->completed_at !== null,
            'last_watched_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
