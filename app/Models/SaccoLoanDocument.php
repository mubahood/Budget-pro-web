<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A loan's stored paper (04 §3.4): the key-facts sheet, the contract, the signed contract, a demand notice. What
 * the member was shown is what they signed: the file and its hash never change, and a signed document is final.
 */
class SaccoLoanDocument extends Model
{
    public const TYPES = ['key_facts', 'contract', 'signed_contract', 'witness_statement', 'demand_notice', 'stop_letter', 'consent_form'];

    protected $guarded = ['id'];

    protected $casts = ['generated_at' => 'datetime', 'signed_by_member_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $doc) {
            if ($doc->isDirty(['file_id', 'path', 'sha256', 'type', 'loan_id']) || ($doc->getOriginal('signed_by_member_at') !== null && $doc->isDirty())) {
                throw BusinessRuleException::make('document_final', 'A loan document is final: what was signed is what was shown.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('document_final', 'A loan document is never deleted.');
        });
    }
}
