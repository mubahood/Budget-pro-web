<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalProperty;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;

/** Landlords (agency mode): who owns the properties the account manages, and how they are paid. */
final class Landlords extends Directory
{
    public const WRITABLE = ['name', 'phone', 'email', 'address', 'id_number', 'payout_method', 'payout_details', 'commission_type', 'commission_bp',
        'commission_minor', 'notes', 'status'];

    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'payout_method' => ['sometimes', 'in:cash,bank,mobile_money'],
            'payout_details' => ['nullable', 'string', 'max:500'],
            'commission_type' => ['nullable', 'in:percent,flat,none'],
            'commission_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'commission_minor' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    public function create(User $by, array $data): RentalLandlord
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('owner_mode', 'This account manages its own property. To manage property for landlords, switch to agency in Settings.');
        }
        $d = self::validated($data, self::WRITABLE, self::rules());

        return RentalLedger::locked($cid, function () use ($by, $d, $cid) {
            $l = RentalLandlord::create($this->fill($d, $cid) + ['company_id' => $cid, 'code' => RentalsModule::nextNumber($cid, 'landlord'),
                'status' => 'active', 'payout_method' => $d['payout_method'] ?? 'bank', 'created_by_id' => $by->id]);
            RentalAudit::record($cid, 'landlord.created', $l, "Added landlord {$l->name}", [], 'staff', $by->id);

            return $l;
        });
    }

    public function update(User $by, RentalLandlord $landlord, array $data): RentalLandlord
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $landlord->company_id;
        $d = self::validated($data, self::WRITABLE, self::rules(true));
        if (($d['status'] ?? null) === 'inactive' && RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)->where('status', 'active')->exists()) {
            throw BusinessRuleException::make('landlord_has_property', 'This landlord still has active properties. Archive them or give them to another landlord first.');
        }
        $landlord->forceFill($this->fill($d, $cid))->save();
        RentalAudit::record($cid, 'landlord.updated', $landlord, "Updated landlord {$landlord->name}", ['fields' => array_keys($d)], 'staff', $by->id);

        return $landlord->fresh();
    }

    public function find(User $by, int $id): RentalLandlord
    {
        RentalAccess::authorize($by, 'rentals_view');

        return RentalLandlord::withoutGlobalScopes()->where('company_id', $by->company_id)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That landlord was not found.');
    }

    private function fill(array $d, int $cid): array
    {
        if (array_key_exists('phone', $d)) {
            $d['phone_e164'] = self::phone($d['phone'], self::country($cid));
        }
        if (array_key_exists('id_number', $d)) {
            $d['id_number'] = self::encrypt($d['id_number']);
        }
        if (($d['commission_type'] ?? null) === null && array_key_exists('commission_type', $d)) {
            $d['commission_bp'] = null;
            $d['commission_minor'] = null;
        }

        return $d;
    }
}
