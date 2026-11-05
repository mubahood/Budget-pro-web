<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLease;
use App\Models\RentalMaintenance;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Str;

/**
 * Repairs and problems (rentals_maintenance): reported by staff, a tenant (portal) or a landlord, followed from open
 * to done. A cost at "done" becomes an expense charged to the landlord or the agency. A phone's retry (same uuid) is
 * one request. A quote can be noted on the way: above the landlord's limit it needs their answer (Portal\LandlordApprovals, LC-17). Nothing posts
 * until the expense does.
 */
final class Maintenance
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    /**
     * Open a request. $data: property_id, unit_id, title, details, priority, photos (file uuids), assigned_to_id,
     * assignee_name, uuid. $reporter: [type staff|tenant|landlord, id].
     */
    public function open(?User $by, int $companyId, array $data, array $reporter = ['staff', null]): RentalMaintenance
    {
        if ($by !== null) {
            RentalAccess::authorize($by, 'rentals_maintenance');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw BusinessRuleException::make('title_required', 'Say what the problem is, for example "Kitchen tap leaking".');
        }
        $unit = ! empty($data['unit_id']) ? RentalUnit::withoutGlobalScopes()->where('company_id', $companyId)->find((int) $data['unit_id']) : null;
        if (! empty($data['unit_id']) && $unit === null) {
            throw BusinessRuleException::make('not_found', 'That unit was not found.');
        }
        if ($unit !== null && ! empty($data['property_id']) && (int) $data['property_id'] !== (int) $unit->property_id) {
            throw BusinessRuleException::make('not_found', 'That unit is not in this property.');
        }
        $propertyId = (int) ($unit?->property_id ?? ($data['property_id'] ?? 0));
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $companyId)->find($propertyId)
            ?? throw BusinessRuleException::make('not_found', 'Choose the property.');
        if ($by !== null) {
            RentalAccess::assertInScope($by, (int) $property->id, 'property');
        }
        $priority = array_key_exists($data['priority'] ?? 'normal', self::PRIORITIES) ? ($data['priority'] ?? 'normal') : 'normal';
        $uuid = (string) ($data['uuid'] ?? Str::uuid());
        if (($same = RentalMaintenance::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first()) !== null) {
            return $same;
        }
        $lease = $unit !== null ? RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->where('unit_id', $unit->id)->whereIn('status', RentalLease::LIVE)->first() : null;
        if (! empty($data['assigned_to_id']) && ! User::withoutGlobalScopes()->where('company_id', $companyId)->whereKey((int) $data['assigned_to_id'])->exists()) {
            throw BusinessRuleException::make('not_found', 'Choose someone from your own team.');
        }

        return RentalLedger::locked($companyId, function () use ($by, $companyId, $data, $title, $unit, $property, $priority, $uuid, $lease, $reporter) {
            if (($same = RentalMaintenance::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first()) !== null) {
                return $same; // two sends at once: the second waited for the lock, and finds the first
            }
            $m = RentalMaintenance::create(['company_id' => $companyId, 'uuid' => $uuid, 'number' => RentalsModule::nextNumber($companyId, 'ticket'),
                'property_id' => $property->id, 'unit_id' => $unit?->id, 'lease_id' => $lease?->id, 'tenant_id' => $reporter[0] === 'tenant' ? $reporter[1] : $lease?->tenant_id,
                'title' => mb_substr($title, 0, 150), 'details' => isset($data['details']) ? mb_substr((string) $data['details'], 0, 5000) : null,
                'priority' => $priority, 'status' => 'open', 'reported_by' => $reporter[0], 'reported_by_id' => $reporter[1] ?? $by?->id,
                'assigned_to_id' => ! empty($data['assigned_to_id']) ? (int) $data['assigned_to_id'] : null, 'assignee_name' => isset($data['assignee_name']) ? mb_substr((string) $data['assignee_name'], 0, 150) : null,
                'photos' => self::ownPhotos($companyId, (array) ($data['photos'] ?? [])) ?: null,
                'created_by_id' => $by?->id]);
            RentalAudit::record($companyId, 'maintenance.opened', $m, "Opened {$m->number}: {$m->title}", ['priority' => $priority], $reporter[0], $reporter[1] ?? $by?->id);

            return $m;
        });
    }

    /** Change status, priority, who does it, title or details (not done/cancelled: those have their own steps). */
    public function update(User $by, RentalMaintenance $m, array $data): RentalMaintenance
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        RentalAccess::assertInScope($by, (int) $m->property_id, 'request');
        if (in_array($m->status, ['done', 'cancelled'], true)) {
            throw BusinessRuleException::make('ticket_closed', 'This request is closed.');
        }
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw BusinessRuleException::make('title_required', 'Say what the problem is, for example "Kitchen tap leaking".');
        }
        $fill = [];
        foreach (['title' => 150, 'details' => 5000, 'assignee_name' => 150] as $k => $max) {
            if (array_key_exists($k, $data)) {
                $fill[$k] = $data[$k] === null ? null : mb_substr((string) $data[$k], 0, $max);
            }
        }
        if (isset($data['priority']) && array_key_exists($data['priority'], self::PRIORITIES)) {
            $fill['priority'] = $data['priority'];
        }
        if (isset($data['status']) && in_array($data['status'], ['open', 'in_progress'], true)) {
            $fill['status'] = $data['status'];
        }
        if (array_key_exists('assigned_to_id', $data)) {
            $who = $data['assigned_to_id'];
            if (! in_array($who, [null, ''], true) && ! User::withoutGlobalScopes()->where('company_id', $m->company_id)->whereKey((int) $who)->exists()) {
                throw BusinessRuleException::make('not_found', 'Choose someone from your own team.');
            }
            $fill['assigned_to_id'] = in_array($who, [null, ''], true) ? null : (int) $who;
        }
        if (isset($data['photos'])) {
            $fill['photos'] = array_values(array_unique(array_merge((array) $m->photos, self::ownPhotos((int) $m->company_id, (array) $data['photos']))));
        }
        $m->forceFill($fill)->save();
        RentalAudit::record((int) $m->company_id, 'maintenance.updated', $m, "Updated {$m->number}", ['fields' => array_keys($fill)], 'staff', $by->id);

        return $m->fresh();
    }

    /**
     * The quote for a repair still open, or none. A new figure is a new question, so an answer about the old one no longer stands.
     */
    public function quote(User $by, RentalMaintenance $m, ?int $quoteMinor): RentalMaintenance
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        RentalAccess::assertInScope($by, (int) $m->property_id, 'request');
        $cid = (int) $m->company_id;
        $quote = $quoteMinor === null ? null : Minor::positive($quoteMinor, 'quote');

        return RentalLedger::locked($cid, function () use ($by, $m, $cid, $quote) {
            $t = RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($m->id);
            if (in_array($t->status, ['done', 'cancelled'], true)) {
                throw BusinessRuleException::make('ticket_closed', 'This request is closed.');
            }
            if ($t->quote_minor === $quote) {
                return $t;
            }
            $t->forceFill(['quote_minor' => $quote, 'approval_status' => 'none', 'approval_via' => null, 'approval_note' => null, 'approval_at' => null, 'approval_by_id' => null, 'approval_landlord_id' => null])->save();
            RentalAudit::record($cid, 'maintenance.quoted', $t, $quote === null ? "Took the quote off {$t->number}" : "Quote on {$t->number}: ".Minor::format($quote, $cid), ['quote_minor' => $quote], 'staff', $by->id);

            return $t->fresh();
        });
    }

    /** Done: with a cost, the expense is recorded (charged to the landlord by default) and linked. */
    public function complete(User $by, RentalMaintenance $m, array $data): RentalMaintenance
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        RentalAccess::assertInScope($by, (int) $m->property_id, 'request');
        $cid = (int) $m->company_id;
        if (in_array($m->status, ['done', 'cancelled'], true)) {
            throw BusinessRuleException::make('ticket_closed', 'This request is already closed.');
        }
        $cost = isset($data['cost_minor']) && (int) $data['cost_minor'] > 0 ? Minor::positive($data['cost_minor'], 'cost') : 0;

        return RentalLedger::locked($cid, function () use ($by, $m, $data, $cid, $cost) {
            $expenseId = null;
            if ($cost > 0) {
                $e = app(Expenses::class)->record($by, ['property_id' => $m->property_id, 'unit_id' => $m->unit_id, 'category' => (string) ($data['category'] ?? 'Repairs'),
                    'description' => "{$m->number}: {$m->title}", 'vendor' => $data['vendor'] ?? $m->assignee_name, 'amount_minor' => $cost,
                    'method' => $data['method'] ?? 'cash', 'paid_on' => $data['paid_on'] ?? null, 'charged_to' => $data['charged_to'] ?? 'landlord',
                    'receipt_uuid' => $data['receipt_uuid'] ?? null, 'maintenance_id' => $m->id, 'over_limit_reason' => $data['over_limit_reason'] ?? null]);
                $expenseId = $e->id;
            }
            $m->forceFill(['status' => 'done', 'cost_minor' => $cost ?: null, 'expense_id' => $expenseId, 'resolved_on' => LocalDate::date($cid, $data['resolved_on'] ?? null)->toDateString(),
                'resolution' => isset($data['resolution']) ? mb_substr((string) $data['resolution'], 0, 5000) : null])->save();
            RentalAudit::record($cid, 'maintenance.done', $m, "Closed {$m->number}: {$m->title}", ['cost_minor' => $cost], 'staff', $by->id);

            return $m->fresh();
        });
    }

    /** The files of this account among those named: a photo of another account's is no photo of this repair. @param array<int|string, mixed> $uuids @return list<string> */
    private static function ownPhotos(int $companyId, array $uuids): array
    {
        $list = array_values(array_filter(array_map('strval', $uuids), fn ($u) => Str::isUuid($u)));

        return $list === [] ? [] : array_values(array_intersect($list, StoredFile::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('uuid', $list)->pluck('uuid')->map(fn ($u) => (string) $u)->all()));
    }

    public function cancel(User $by, RentalMaintenance $m, string $reason): RentalMaintenance
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        RentalAccess::assertInScope($by, (int) $m->property_id, 'request');
        if (in_array($m->status, ['done', 'cancelled'], true)) {
            throw BusinessRuleException::make('ticket_closed', 'This request is already closed.');
        }
        $m->forceFill(['status' => 'cancelled', 'resolution' => mb_substr(trim($reason) ?: 'Cancelled', 0, 5000), 'resolved_on' => LocalDate::today((int) $m->company_id)->toDateString()])->save();
        RentalAudit::record((int) $m->company_id, 'maintenance.cancelled', $m, "Cancelled {$m->number}", ['reason' => $reason], 'staff', $by->id);

        return $m->fresh();
    }
}
