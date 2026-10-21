<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One committee member's vote (or recusal) on one loan in one round (04 §6.2): written once by Loans\Committee. */
class SaccoLoanVote extends Model
{
    public const UPDATED_AT = null;

    public const VOTES = ['approve', 'reject', 'abstain'];

    public const ROUNDS = ['committee', 'appeal'];

    protected $guarded = ['id'];

    protected $casts = ['recused' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('vote_final', 'A vote is final once cast.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('vote_final', 'A vote is final once cast.');
        });
    }
}
