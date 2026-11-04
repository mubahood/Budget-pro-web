<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalExpense;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\CashGuard;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;

/**
 * Money paid out (rentals_expenses): for a property and charged to its landlord (cash −, landlord −), or the agency's
 * own cost (cash −, expense +). In owner mode every expense is the owner's.
 */
final class Expenses
{
    public const METHODS = ['cash', 'bank', 'mobile_money', 'cheque'];

    /** $data: property_id, unit_id, category, description, vendor, amount_minor, method, paid_on, charged_to, receipt_uuid, maintenance_id. */
    public function record(User $by, array $data): RentalExpense
    {
        RentalAccess::authorize($by, 'rentals_expenses');
        $cid = (int) $by->company_id;
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $method = (string) ($data['method'] ?? 'cash');
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, bank, mobile money or cheque.');
        }
        $category = trim((string) ($data['category'] ?? ''));
        if ($category === '') {
            throw BusinessRuleException::make('category_required', 'Choose what the expense was for.');
        }
        $on = LocalDate::date($cid, $data['paid_on'] ?? null);
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'An expense cannot be dated after today.');
        }
        $owner = RentalsModule::isOwnerMode($cid);
        $chargedTo = $owner ? 'landlord' : (($data['charged_to'] ?? 'landlord') === 'agency' ? 'agency' : 'landlord');
        $property = null;
        if (! empty($data['property_id'])) {
            $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->find((int) $data['property_id'])
                ?? throw BusinessRuleException::make('not_found', 'That property was not found.');
            RentalAccess::assertInScope($by, (int) $property->id, 'property');
        }
        if ($chargedTo === 'landlord' && $property === null) {
            if (! $owner) {
                throw BusinessRuleException::make('property_required', 'Choose the property: its landlord pays this expense.');
            }
        }
        $unitId = null;
        if (! empty($data['unit_id'])) {
            $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find((int) $data['unit_id']);
            if ($unit === null || ($property !== null && (int) $unit->property_id !== (int) $property->id)) {
                throw BusinessRuleException::make('not_found', 'That unit is not in this property.');
            }
            $unitId = (int) $unit->id;
        }
        if (! empty($data['maintenance_id']) && ! \App\Models\RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereKey((int) $data['maintenance_id'])->exists()) {
            throw BusinessRuleException::make('not_found', 'That repair request was not found.');
        }
        $landlordId = $chargedTo === 'landlord' ? ($property?->landlord_id ?? RentalsModule::selfLandlord($cid)->id) : null;

        return RentalLedger::locked($cid, function () use ($by, $data, $cid, $amount, $method, $category, $on, $chargedTo, $property, $unitId, $landlordId) {
            CashGuard::assertCovers($cid, $method, $amount);
            $e = RentalExpense::create(['company_id' => $cid, 'number' => RentalsModule::nextNumber($cid, 'expense'), 'property_id' => $property?->id,
                'unit_id' => $unitId, 'landlord_id' => $landlordId, 'category' => mb_substr($category, 0, 60),
                'description' => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
                'vendor' => isset($data['vendor']) ? mb_substr((string) $data['vendor'], 0, 150) : null, 'amount_minor' => $amount, 'method' => $method,
                'paid_on' => $on->toDateString(), 'charged_to' => $chargedTo, 'receipt_uuid' => $data['receipt_uuid'] ?? null,
                'maintenance_id' => $data['maintenance_id'] ?? null, 'created_by_id' => $by->id]);
            $row = ['occurred_on' => $on->toDateString(), 'method' => $method, 'cash_minor' => -$amount, 'expense_id' => $e->id, 'property_id' => $property?->id,
                'unit_id' => $unitId, 'description' => trim($category.($e->description ? ': '.$e->description : ''))];
            $row += $chargedTo === 'landlord' ? ['type' => 'expense_landlord', 'landlord_minor' => -$amount, 'landlord_id' => $landlordId] : ['type' => 'expense_agency', 'expense_minor' => $amount];
            RentalLedger::post($cid, RentalLedger::group(), [$row], $by->id);
            RentalAudit::record($cid, 'expense.recorded', $e, "Recorded expense {$e->number}: {$category}", ['amount_minor' => $amount, 'charged_to' => $chargedTo], 'staff', $by->id);

            return $e->fresh();
        });
    }

    public function reverse(User $by, RentalExpense $expense, string $reason): RentalExpense
    {
        RentalAccess::authorize($by, 'rentals_reverse');
        $cid = (int) $expense->company_id;
        if (trim($reason) === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this expense is reversed.');
        }

        return RentalLedger::locked($cid, function () use ($by, $expense, $reason, $cid) {
            $e = RentalExpense::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($expense->id);
            if ($e->reversed_at !== null) {
                throw BusinessRuleException::make('already_reversed', 'This expense was already reversed.');
            }
            $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('expense_id', $e->id)->whereNull('reversed_at')->whereNull('reverses_id')->get();
            RentalLedger::reverse($cid, $rows, LocalDate::today($cid)->toDateString(), $reason, $by->id);
            $e->forceFill(['reversed_at' => now(), 'reversed_by_id' => $by->id, 'reverse_reason' => mb_substr($reason, 0, 255)])->save();
            RentalAudit::record($cid, 'expense.reversed', $e, "Reversed expense {$e->number}", ['reason' => $reason], 'staff', $by->id);

            return $e->fresh();
        });
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_values((array) config('rentals.expense_categories'));
    }
}
