<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member guaranteeing part of a loan (SI 108 reg. 10, AK-48, AK-49): written by Loans\Guarantors only. */
class SaccoLoanGuarantor extends Model
{
    use AuditLogger;

    public const STATUSES = ['requested', 'consented', 'declined', 'released'];

    public const CHANNELS = ['signed_form', 'sms_link', 'member_app'];

    protected $guarded = ['id'];

    protected $hidden = ['consent_token_hash'];

    protected $casts = ['notice_sent_at' => 'datetime', 'token_expires_at' => 'datetime', 'consented_at' => 'datetime', 'declined_at' => 'datetime',
        'released_at' => 'datetime', 'guaranteed_minor' => 'integer', 'held_minor' => 'integer', 'recovered_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('guarantee_history', 'A guarantee is released or declined, never deleted.');
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'guarantor_member_id');
    }
}
