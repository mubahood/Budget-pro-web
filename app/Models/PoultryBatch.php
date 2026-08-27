<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoultryBatch extends Model
{
    use AuditLogger, HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'acquired_date' => 'date',
        'is_main_farm' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function farmType()
    {
        return $this->belongsTo(PoultryFarmType::class, 'type', 'slug');
    }

    public function dailyRecords()
    {
        return $this->hasMany(PoultryDailyRecord::class, 'batch_id');
    }

    public function sales()
    {
        return $this->hasMany(PoultrySale::class, 'batch_id');
    }

    public function expenses()
    {
        return $this->hasMany(PoultryExpense::class, 'batch_id');
    }

    public function mortalityEvents()
    {
        return $this->hasMany(PoultryMortalityEvent::class, 'batch_id');
    }

    public function healthEvents()
    {
        return $this->hasMany(PoultryHealthEvent::class, 'batch_id');
    }

    public function vaccinationEvents()
    {
        return $this->hasMany(PoultryVaccinationEvent::class, 'batch_id');
    }
}
