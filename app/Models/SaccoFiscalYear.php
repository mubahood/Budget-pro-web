<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's financial year (P4.7, AK-41): open until its close is approved and posted (close:{year}), then closed
 * with its income, expenses and net surplus as the closing entry moved them to 3410. Written by YearEnd\Closing.
 */
class SaccoFiscalYear extends Model
{
    public const STATUSES = ['open', 'closing', 'closed'];

    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_on' => 'date:Y-m-d', 'appropriated_on' => 'date:Y-m-d', 'year' => 'integer',
        'income_minor' => 'integer', 'expense_minor' => 'integer', 'net_surplus_minor' => 'integer', 'close_entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $y) {
            if ($y->getOriginal('status') === 'closed' && array_diff(array_keys($y->getDirty()), ['appropriated_on', 'updated_at']) !== []) {
                throw BusinessRuleException::make('year_closed', 'A closed year is final.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('year_closed', 'A financial year is never deleted.');
        });
    }

    public function label(): string
    {
        return $this->starts_on->format('j M Y').' to '.$this->ends_on->format('j M Y');
    }
}
