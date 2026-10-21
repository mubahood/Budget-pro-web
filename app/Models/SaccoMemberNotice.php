<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A notice in a member's own app or web page (a loan request waits for them, their loan was approved). */
class SaccoMemberNotice extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['member_id' => 'integer', 'loan_id' => 'integer', 'read_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
