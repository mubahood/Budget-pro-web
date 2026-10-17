<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** Idempotency log for pushed batches (A.2); `held` batches wait for a renewal (Appendix E). */
class SyncBatch extends Model
{
    protected $fillable = ['company_id', 'device_id', 'user_id', 'batch_uuid', 'kind', 'status', 'ops_count', 'payload', 'result', 'applied_at'];

    protected $casts = ['payload' => 'array', 'result' => 'array', 'applied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
