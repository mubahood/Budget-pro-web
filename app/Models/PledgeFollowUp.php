<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time someone opened a reminder, a thank-you or a call for a pledge, or noted what the person said (the review's
 * PB-19). "Opened" is all it means: nobody can know the message was sent. Written by App\Services\Budget\PledgeFollowUps.
 */
class PledgeFollowUp extends Model
{
    use Syncable;

    public const KINDS = ['reminder', 'thanks', 'call', 'note'];

    public const CHANNELS = ['whatsapp', 'sms', 'call', 'in_person'];

    public const OUTCOMES = ['will_pay', 'said_no', 'wrong_number', 'skipped'];

    protected $table = 'pledge_follow_ups';

    protected $guarded = ['id'];

    protected $casts = [
        'promised_on' => 'date:Y-m-d',
        'is_deleted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function contributionRecord(): BelongsTo
    {
        return $this->belongsTo(ContributionRecord::class, 'contribution_record_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
