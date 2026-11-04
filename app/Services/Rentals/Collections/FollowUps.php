<?php

namespace App\Services\Rentals\Collections;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\User;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalAccess;
use App\Support\LocalDate;

/** The collections log: each call, reminder, visit or note about a lease's arrears, with the tenant's promise date. */
final class FollowUps
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'call' => 'Call', 'visit' => 'Visit', 'note' => 'Note'];

    public function log(User $by, RentalLease $lease, string $channel, ?string $note = null, ?string $promiseOn = null): RentalFollowUp
    {
        RentalAccess::authorize($by, 'rentals_collect');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        if (! array_key_exists($channel, self::CHANNELS)) {
            throw BusinessRuleException::make('channel_invalid', 'Choose WhatsApp, SMS, call, visit or note.');
        }
        $cid = (int) $lease->company_id;
        $promise = $promiseOn ? LocalDate::date($cid, $promiseOn) : null;
        if ($promise !== null && $promise->lt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_invalid', 'A promise date is today or later.');
        }

        return RentalFollowUp::create(['company_id' => $cid, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'channel' => $channel,
            'note' => $note !== null ? mb_substr(trim($note), 0, 500) : null, 'owed_minor' => Leases::position($lease)['owed_minor'],
            'promise_on' => $promise?->toDateString(), 'created_by_id' => $by->id, 'created_at' => now()]);
    }

    /** @return \Illuminate\Support\Collection<int, RentalFollowUp> newest first */
    public static function forLease(RentalLease $lease, int $limit = 50)
    {
        return RentalFollowUp::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->orderByDesc('id')->limit($limit)->get();
    }
}
