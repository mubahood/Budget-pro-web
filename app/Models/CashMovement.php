<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash in or out of a till drawer during a shift (supermarket plan E1/E4): a drop to the safe, a
 * pickup from it, a paid-in, a paid-out, or a no-sale drawer opening (amount 0). Written only by
 * ShiftService::cashMovement; a movement is never edited or deleted (money is corrected by a new one).
 */
class CashMovement extends Model
{
    public const UPDATED_AT = null;

    /** type => [label, sign in the drawer] */
    public const TYPES = [
        'drop' => ['Cash drop to the safe', -1],
        'pickup' => ['Cash from the safe', 1],
        'paid_in' => ['Paid in', 1],
        'paid_out' => ['Paid out', -1],
        'no_sale' => ['No sale (drawer opened)', 0],
    ];

    protected $table = 'cash_movements';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        // Phones pull cash movements by server_seq (sync table `cash_movements`); older schemas have no column.
        static::creating(function (CashMovement $m) {
            if (self::hasSeq() && empty($m->server_seq)) {
                $m->server_seq = \App\Support\Sync\SyncSequence::next();
            }
        });
        static::updating(fn () => throw BusinessRuleException::make('cash_movement_locked', 'A cash movement cannot be changed. Record a new one to correct it.'));
        static::deleting(fn () => throw BusinessRuleException::make('cash_movement_locked', 'A cash movement cannot be deleted. Record a new one to correct it.'));
    }

    public static function hasSeq(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn('cash_movements', 'server_seq');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function label(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }
}
