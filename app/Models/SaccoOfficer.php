<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One term in one office of a SACCO (AK-21): written by App\Services\Sacco\OfficerService, ended (never deleted).
 */
class SaccoOfficer extends Model
{
    use AuditLogger;

    public const POSITIONS = ['chairperson', 'vice_chair', 'treasurer', 'secretary', 'manager', 'board_member', 'loans_committee', 'supervisory',
        'key_holder', 'money_counter', 'teller', 'custom'];

    public const LABELS = ['chairperson' => 'Chairperson', 'vice_chair' => 'Vice chairperson', 'treasurer' => 'Treasurer', 'secretary' => 'Secretary',
        'manager' => 'Manager', 'board_member' => 'Board member', 'loans_committee' => 'Loans committee', 'supervisory' => 'Supervisory board (auditor)',
        'key_holder' => 'Key holder', 'money_counter' => 'Money counter', 'teller' => 'Teller', 'custom' => 'Other office'];

    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'approval_limit_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('officer_history', 'An officer\'s term is ended, never deleted: the register keeps who held each office.');
        });
    }

    /** Terms running on a day (today by default). */
    public function scopeActiveOn(Builder $q, ?Carbon $day = null): Builder
    {
        $d = ($day ?? Carbon::today())->toDateString();

        return $q->where('starts_on', '<=', $d)->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $d));
    }

    public function label(): string
    {
        return $this->position === 'custom' && $this->title ? (string) $this->title : self::LABELS[$this->position] ?? $this->position;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
