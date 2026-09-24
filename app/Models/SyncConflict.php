<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** Conflict-inbox item (Appendix E): something a person must look at, never silently dropped. */
class SyncConflict extends Model
{
    protected $fillable = ['company_id', 'device_id', 'table_name', 'row_uuid', 'code', 'title', 'local_json', 'server_json', 'state', 'resolution', 'resolved_by_id', 'resolved_at'];

    protected $casts = ['local_json' => 'array', 'server_json' => 'array', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
