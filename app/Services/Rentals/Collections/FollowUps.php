<?php

namespace App\Services\Rentals\Collections;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\User;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalAccess;
use App\Support\LocalDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** The collections log: each call, reminder, visit or note about a lease's arrears, with the tenant's promise date. */
final class FollowUps
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'call' => 'Call', 'visit' => 'Visit', 'note' => 'Note'];

    /** $uuid: the phone's own id for this follow-up, so a resend from its outbox is the same one (replayed()). */
    public function log(User $by, RentalLease $lease, string $channel, ?string $note = null, ?string $promiseOn = null, ?string $uuid = null): RentalFollowUp
    {
        RentalAccess::authorize($by, 'rentals_collect');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        if ($uuid !== null && ! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The follow-up id is not valid.');
        }
        if ($uuid !== null && ($same = self::replayed($lease, $uuid)) !== null) {
            return $same;
        }
        if (! array_key_exists($channel, self::CHANNELS)) {
            throw BusinessRuleException::make('channel_invalid', 'Choose WhatsApp, SMS, call, visit or note.');
        }
        $cid = (int) $lease->company_id;
        $promise = $promiseOn ? LocalDate::date($cid, $promiseOn) : null;
        if ($promise !== null && $promise->lt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_invalid', 'A promise date is today or later.');
        }

        try {
            return RentalFollowUp::create(($uuid !== null ? ['uuid' => $uuid] : []) + ['company_id' => $cid, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'channel' => $channel,
                'note' => $note !== null ? mb_substr(trim($note), 0, 500) : null, 'owed_minor' => Leases::position($lease)['owed_minor'],
                'promise_on' => $promise?->toDateString(), 'created_by_id' => $by->id, 'created_at' => now()]);
        } catch (UniqueConstraintViolationException $e) {
            // Two sends of the same follow-up at once: the first one stands.
            return ($uuid !== null ? self::replayed($lease, $uuid) : null) ?? throw $e;
        }
    }

    /** The follow-up already written under this id, on this lease. The id used for another lease is refused. */
    public static function replayed(RentalLease $lease, string $uuid): ?RentalFollowUp
    {
        $same = RentalFollowUp::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('uuid', $uuid)->first();
        if ($same !== null && (int) $same->lease_id !== (int) $lease->id) {
            throw BusinessRuleException::make('idempotency_conflict', 'This follow-up id was already used for another lease.');
        }

        return $same;
    }

    /** @return \Illuminate\Support\Collection<int, RentalFollowUp> newest first */
    public static function forLease(RentalLease $lease, int $limit = 50)
    {
        return RentalFollowUp::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->orderByDesc('id')->limit($limit)->get();
    }
}
