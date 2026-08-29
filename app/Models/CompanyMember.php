<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The multi-member organisation model Ping Pin needs and budget-pro's
 * `Company` never had (PLAN.md §2, DECISIONS.md D1). One row per
 * (company, user) pair. No CompanyScope here deliberately — this table
 * itself is *how* tenant membership is determined, so scoping it by
 * company would be circular; it's always queried directly by
 * company_id/user_id instead.
 */
class CompanyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'role', 'invited_by_id',
        'invited_email', 'invited_phone', 'status', 'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
