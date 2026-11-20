<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One render of a promo video, queued by an admin and taken by the studio's worker: queued → running → done | failed.
 *
 * @property int $id
 * @property int $promo_video_id
 * @property string $status
 * @property int $script_rev
 * @property string|null $worker
 * @property string|null $log
 */
class PromoVideoRender extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = ['script_rev' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function video(): BelongsTo
    {
        return $this->belongsTo(PromoVideo::class, 'promo_video_id');
    }
}
