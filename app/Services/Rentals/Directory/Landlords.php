<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalProperty;
use App\Models\RentalPropertyOwner;
use App\Models\User;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;

/** Landlords (agency mode): who owns the properties the account manages, and how they are paid. */
final class Landlords extends Directory
{
    public const WRITABLE = ['name', 'phone', 'phone_2', 'email', 'address', 'id_number', 'payout_method', 'payout_details', 'commission_type', 'commission_bp',
        'commission_minor', 'notes', 'status', 'expense_limit_minor', 'withholding_bp'];

    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'phone_2' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'payout_method' => ['sometimes', 'in:cash,bank,mobile_money'],
            'payout_details' => ['nullable', 'string', 'max:500'],
            'commission_type' => ['nullable', 'in:percent,flat,none'],
            'commission_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'commission_minor' => ['nullable', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'expense_limit_minor' => ['nullable', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'withholding_bp' => ['nullable', 'integer', 'min:0', 'max:10000'], // the agency's own percent to hold back (LC-23): no rate comes from here
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
            throw BusinessRuleException::make('landlord_has_property', 'This landlord still has active properties. Archive them or hand them over to another landlord first.');
        }
        $why = [];
        if (($d['status'] ?? null) === 'inactive' && $landlord->status !== 'inactive') {
            $why = $this->leavingReason($landlord, $data);
        }
        $landlord->forceFill($this->fill($d, $cid))->save();
        RentalAudit::record($cid, 'landlord.updated', $landlord, "Updated landlord {$landlord->name}", ['fields' => array_keys($d)] + $why, 'staff', $by->id);

        return $landlord->fresh();
    }

    public function find(User $by, int $id): RentalLandlord
    {
        RentalAccess::authorize($by, 'rentals_view');

        return RentalLandlord::withoutGlobalScopes()->where('company_id', $by->company_id)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That landlord was not found.');
    }

    /**
     * Marking a landlord "No longer with us": a handover waiting to give them a property stops it; a balance not at zero only warns (LC-01): it goes
     * through with a reason, kept in the audit line. @return array{reason?: string, balance_minor?: int}
     */
    private function leavingReason(RentalLandlord $landlord, array $data): array
    {
        $cid = (int) $landlord->company_id;
        if (RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)->whereNull('applied_at')->whereNull('cancelled_at')->exists()) {
            throw BusinessRuleException::make('landlord_has_property', 'A property is waiting to be handed over to this landlord. Cancel that first.');
        }
        $balance = LandlordAccounts::balance($landlord);
        if ($balance === 0) {
            return [];
        }
        $reason = trim((string) ($data['inactive_reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('landlord_has_balance', ($balance > 0 ? 'You still owe '.$landlord->name.' '.Minor::format($balance, $cid) : $landlord->name.' still owes you '.Minor::format(-$balance, $cid)).'. Settle it first, or say why they can go anyway.');
        }

        return ['reason' => mb_substr($reason, 0, 255), 'balance_minor' => $balance];
    }

    private function fill(array $d, int $cid): array
    {
        if (array_key_exists('phone', $d)) {
            $d['phone_e164'] = self::phone($d['phone'], self::country($cid));
        }
        if (array_key_exists('phone_2', $d)) {
            $d['phone_2_e164'] = self::phone($d['phone_2'], self::country($cid));
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
