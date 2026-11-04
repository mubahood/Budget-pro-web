<?php

namespace App\Services\Rentals;

use App\Models\RentalActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * The Estate Ledger audit trail (`rental_activities`): who did what and when. Meta never holds an ID number, a password
 * or a phone number in full.
 */
final class RentalAudit
{
    public static function record(int $companyId, string $action, ?Model $subject, string $summary, array $meta = [], string $actorType = 'staff', ?int $actorId = null): void
    {
        RentalActivity::create([
            'company_id' => $companyId,
            'actor_type' => $actorType,
            'actor_id' => $actorId ?? ($actorType === 'staff' ? auth()->id() : null),
            'action' => $action,
            'subject_type' => $subject !== null ? str_replace('App\\Models\\Rental', '', $subject::class) : null,
            'subject_id' => $subject?->getKey(),
            'summary' => mb_substr($summary, 0, 255),
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
