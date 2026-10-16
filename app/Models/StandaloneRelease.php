<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A standalone release (docs/STANDALONE_EDITION_PLAN.md §10). SaaS only.
 *
 * queued → building → staged (signed ZIP built from the deployed commits and proven by a clean
 * install) → published (offered to buyers and the update feed) → yanked (withdrawn); or failed.
 *
 * @property int $id
 * @property string $version
 * @property string|null $commit_kernel
 * @property string|null $commit_web
 * @property string $status
 * @property string|null $notes
 * @property array|null $progress {"step": string, "percent": int}
 * @property string|null $failure
 * @property string|null $archive_path
 * @property string|null $sha256
 * @property int|null $size
 * @property int|null $file_count
 */
class StandaloneRelease extends Model
{
    public const QUEUED = 'queued';

    public const BUILDING = 'building';

    public const STAGED = 'staged';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const YANKED = 'yanked';

    /** A build that has not moved for this long has lost its worker. */
    public const STALE_MINUTES = 40;

    protected $fillable = [
        'version', 'commit_kernel', 'commit_web', 'status', 'notes', 'progress', 'failure', 'archive_path', 'sha256',
        'size', 'file_count', 'staged_at', 'proved_at', 'published_at', 'built_by',
    ];

    protected $casts = [
        'progress' => 'array', 'size' => 'integer', 'file_count' => 'integer', 'built_by' => 'integer',
        'staged_at' => 'datetime', 'proved_at' => 'datetime', 'published_at' => 'datetime',
    ];

    /** What buyers and the update feed are offered: published, never withdrawn. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)->whereNotNull('published_at');
    }

    /** Built, proven by a clean install, and its archive still on disk with the hash it was built with. */
    public function isPublishable(): bool
    {
        return $this->status === self::STAGED && $this->proved_at !== null && is_string($this->archive_path)
            && is_file($this->archive_path) && is_string($this->sha256)
            && hash_equals($this->sha256, (string) hash_file('sha256', $this->archive_path));
    }

    public function step(string $step, int $percent): void
    {
        $this->forceFill(['progress' => ['step' => $step, 'percent' => max(0, min(100, $percent))]])->save();
    }

    /** Builds whose worker died: failed, so they can be retried. @return int how many */
    public static function failStale(): int
    {
        return self::query()->where('status', self::BUILDING)->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => self::FAILED, 'progress' => json_encode(['step' => 'The build worker stopped', 'percent' => 0]),
                'failure' => 'The build worker did not finish within '.self::STALE_MINUTES.' minutes. Check the release worker cron (releases:work), then retry.',
                'updated_at' => now(),
            ]);
    }

    public function shortCommit(string $side): string
    {
        return substr((string) ($side === 'web' ? $this->commit_web : $this->commit_kernel), 0, 7);
    }
}
