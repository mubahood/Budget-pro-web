<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A shop's tax class (supermarket plan F1): standard, reduced, zero-rated or exempt. Written by TaxClassService only. */
class TaxClass extends Model
{
    public const CODES = ['standard' => 'Standard', 'reduced' => 'Reduced', 'zero' => 'Zero-rated', 'exempt' => 'Exempt'];

    protected $table = 'tax_classes';

    protected $guarded = ['id'];

    protected $casts = ['rate' => 'float', 'is_default' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /** "Standard 18%", "Exempt". */
    public function label(): string
    {
        return $this->code === 'exempt' ? (string) $this->name : $this->name.' '.\App\Services\Shop\TaxClassService::pct((float) $this->rate);
    }
}
