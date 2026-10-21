<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A maker-checker request (04 §7): written by App\Services\Sacco\SaccoApprovals. What was asked (action, subject,
 * payload, its hash, amount) never changes once made; only the workflow moves, forward.
 */
class SaccoApproval extends Model
{
    use AuditLogger;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'executed'];

    /** Statuses a request leaves only to these. */
    public const NEXT = ['pending' => ['approved', 'rejected', 'cancelled', 'expired'], 'approved' => ['executed', 'expired', 'cancelled'],
        'rejected' => [], 'cancelled' => [], 'expired' => [], 'executed' => []];

    /** Fixed when the request is made (AK-22: the checker approves exactly what executes). */
    public const FROZEN = ['uuid', 'action', 'subject_type', 'subject_id', 'payload', 'payload_hash', 'amount_minor', 'required_approvals', 'requested_by_id', 'requested_at', 'company_id'];

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'result' => 'array', 'requested_at' => 'datetime', 'requested_on' => 'date:Y-m-d', 'expires_at' => 'datetime',
        'decided_at' => 'datetime', 'executed_at' => 'datetime', 'self_approved' => 'boolean', 'amount_minor' => 'integer', 'required_approvals' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $a) {
            foreach (self::FROZEN as $column) {
                if ($a->isDirty($column)) {
                    throw BusinessRuleException::make('approval_frozen', 'A request cannot be changed once made: cancel it and ask again.');
                }
            }
            if ($a->isDirty('status') && ! in_array($a->status, self::NEXT[$a->getOriginal('status')] ?? [], true)) {
                throw BusinessRuleException::make('approval_closed', "This request is {$a->getOriginal('status')}.");
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('approval_history', 'A request is never deleted: cancel it instead.');
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function votes(): HasMany
    {
        return $this->hasMany(SaccoApprovalVote::class, 'approval_id');
    }
}
