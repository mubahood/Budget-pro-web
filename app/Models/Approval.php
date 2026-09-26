<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supervisor's PIN approval (supermarket plan A5): who asked, who approved, for what. Written by ApprovalService only. */
class Approval extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'approvals';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
