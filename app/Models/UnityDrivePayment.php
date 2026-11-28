<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: one payment towards a drive, with its receipt number. Rules live in App\Services\Unity. */
class UnityDrivePayment extends Model
{
    use Syncable;

    protected $table = 'unity_drive_payments';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'amount_minor' => 'integer', 'paid_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
