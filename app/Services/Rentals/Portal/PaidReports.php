<?php

namespace App\Services\Rentals\Portal;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalPaidReport;
use App\Models\RentalPayment;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "I have paid" (docs/estate-ledger/03-improvements.md §12). A tenant says what they paid, by which method, with its
 * reference and an optional photo (TenantRequests::photo keeps the photo); it waits in a queue for the staff of that
 * property. A report is a claim: it touches no bill, journal row or balance. Confirming records the real receipt
 * through Payments::receive under the report's own uuid, inside the account's lock, so it can happen once; declining
 * keeps the report and its reason so the tenant can read why.
 */
final class PaidReports
{
    /**
     * For the tenant's own sign-in. $data: uuid, amount_minor, method, reference, paid_on, note, lease_id (needed when
     * they have more than one home), photo_uuid. The same uuid twice is one report.
     *
     * @return array{report: RentalPaidReport, replayed: bool}
     */
    public function report(RentalLogin $login, array $data): array
    {
        if ($login->subject_type !== 'tenant') {
            throw BusinessRuleException::make('not_found', 'This is for tenants.');
        }
        $cid = (int) $login->company_id;
        $uuid = (string) ($data['uuid'] ?? Str::uuid());
        if (! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The report id is not valid.');
        }
        $same = RentalPaidReport::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($same !== null) {
            if ((int) $same->tenant_id !== (int) $login->subject_id) {
                throw BusinessRuleException::make('idempotency_conflict', 'This report id was already used.');
            }

            return ['report' => $same, 'replayed' => true];
        }
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, RentalPayment::CASH_METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, mobile money, bank or cheque.');
        }
        $paidOn = LocalDate::date($cid, $data['paid_on'] ?? null);
        if ($paidOn->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A payment cannot be dated after today.');
        }
        $lease = $this->leaseOf($login, isset($data['lease_id']) ? (int) $data['lease_id'] : null);
        $photo = self::text($data['photo_uuid'] ?? null, 36);
        if ($photo !== null && ! StoredFile::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $photo)->where('device_id', 'portal:'.$login->id)->exists()) {
            throw BusinessRuleException::make('file_not_found', 'That photo was not found. Please send it again.');
        }
        $report = RentalPaidReport::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'login_id' => $login->id, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
            'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id, 'amount_minor' => $amount, 'method' => $method, 'reference' => self::text($data['reference'] ?? null, 100),
            'paid_on' => $paidOn->toDateString(), 'note' => self::text($data['note'] ?? null, 255), 'photo_uuid' => $photo, 'status' => 'pending']);
        RentalAudit::record($cid, 'paid_report.made', $report, "The tenant reported a payment on lease {$lease->number}", ['amount_minor' => $amount, 'method' => $method], 'tenant', (int) $lease->tenant_id);

        return ['report' => $report, 'replayed' => false];
    }

    /** Confirm: the real receipt, once. A second try is refused and names the receipt. */
    public function confirm(User $by, RentalPaidReport $report): RentalPayment
    {
        RentalAccess::authorize($by, 'rentals_collect');
        RentalAccess::assertInScope($by, (int) $report->property_id, 'report');
        $cid = (int) $report->company_id;

        return RentalLedger::locked($cid, function () use ($by, $report, $cid) {
            $r = RentalPaidReport::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($report->id);
            $this->assertPending($r);
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($r->lease_id);
            $notes = trim('Reported by the tenant.'.($r->note ? ' '.$r->note : ''));
            // Its month has closed since the tenant reported it: a late receipt, dated today and marked for that month (D7).
            $paidOn = $r->paid_on->toDateString();
            $through = RentalLedger::closedThrough($cid);
            if ($through !== null && $paidOn <= $through) {
                $today = LocalDate::today($cid);
                $notes = RentalLedger::lateMark($paidOn, $today).'. '.$notes;
                $paidOn = $today->toDateString();
            }
            $p = app(Payments::class)->receive($by, $lease, ['amount_minor' => (int) $r->amount_minor, 'method' => (string) $r->method, 'paid_on' => $paidOn,
                'reference' => $r->reference, 'notes' => $notes, 'uuid' => (string) $r->uuid], 'portal');
            $r->forceFill(['status' => 'confirmed', 'payment_id' => $p->id, 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
            RentalAudit::record($cid, 'paid_report.confirmed', $r, "Confirmed a tenant's payment as {$p->number}", ['amount_minor' => (int) $r->amount_minor], 'staff', (int) $by->id);

            return $p;
        });
    }

    /** Decline, with the reason the tenant will read. Nothing moves. */
    public function decline(User $by, RentalPaidReport $report, string $reason): RentalPaidReport
    {
        RentalAccess::authorize($by, 'rentals_collect');
        RentalAccess::assertInScope($by, (int) $report->property_id, 'report');
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why, so the tenant knows what to do next.');
        }
        $cid = (int) $report->company_id;

        return RentalLedger::locked($cid, function () use ($by, $report, $reason, $cid) {
            $r = RentalPaidReport::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($report->id);
            $this->assertPending($r);
            $r->forceFill(['status' => 'declined', 'decided_by_id' => $by->id, 'decided_at' => now(), 'decline_reason' => mb_substr($reason, 0, 255)])->save();
            RentalAudit::record($cid, 'paid_report.declined', $r, "Declined a tenant's reported payment", ['reason' => mb_substr($reason, 0, 255)], 'staff', (int) $by->id);

            return $r;
        });
    }

    /**
     * What waits for this person (their properties), oldest first, each with who it is from and any receipt or other
     * report that already has its reference.
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(int $companyId, ?User $by = null, int $limit = 20): array
    {
        $rows = self::queue($companyId, $by)->join('rental_tenants as t', 't.id', '=', 'r.tenant_id')->join('rental_units as u', 'u.id', '=', 'r.unit_id')
            ->join('rental_properties as p', 'p.id', '=', 'r.property_id')->orderBy('r.id')->limit($limit)
            ->get(['r.id', 'r.lease_id', 'r.tenant_id', 'r.amount_minor', 'r.method', 'r.reference', 'r.paid_on', 'r.note', 'r.photo_uuid', 'r.created_at', 't.name as tenant', 'u.code as unit', 'p.name as property']);
        $refs = $rows->pluck('reference')->filter(fn ($v) => trim((string) $v) !== '')->map(fn ($v) => mb_strtolower(trim((string) $v)))->unique()->values()->all();
        $used = $refs === [] ? collect() : RentalPayment::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('reference', $refs)->whereNull('reversed_at')->orderBy('id')
            ->get(['number', 'reference'])->keyBy(fn ($p) => mb_strtolower(trim((string) $p->reference)));
        $reported = $refs === [] ? [] : array_count_values(RentalPaidReport::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'pending')->whereIn('reference', $refs)
            ->pluck('reference')->map(fn ($v) => mb_strtolower(trim((string) $v)))->all());
        $out = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string) $r->reference));
            $receipt = $key !== '' ? $used->get($key) : null;
            $out[] = ['id' => (int) $r->id, 'lease_id' => (int) $r->lease_id, 'tenant_id' => (int) $r->tenant_id, 'tenant' => (string) $r->tenant, 'unit' => (string) $r->unit, 'property' => (string) $r->property,
                'amount_minor' => (int) $r->amount_minor, 'method' => (string) $r->method, 'reference' => $r->reference, 'paid_on' => (string) $r->paid_on, 'note' => $r->note, 'photo_uuid' => $r->photo_uuid,
                'reported_at' => $r->created_at, 'same_receipt' => $receipt?->number, 'same_report' => $key !== '' && ($reported[$key] ?? 0) > 1];
        }

        return $out;
    }

    public static function pendingCount(int $companyId, ?User $by = null): int
    {
        return (int) self::queue($companyId, $by)->count();
    }

    /** One report in the queue's scope (the account, and a caretaker's properties). */
    public static function find(int $companyId, ?User $by, int $id): ?RentalPaidReport
    {
        $r = RentalPaidReport::withoutGlobalScopes()->where('company_id', $companyId)->find($id);

        return $r !== null && ($by === null || RentalAccess::inScope($by, (int) $r->property_id)) ? $r : null;
    }

    /** The tenant's own reports, newest first, for their portal. @return Collection<int, RentalPaidReport> */
    public static function forLogin(RentalLogin $login, int $limit = 20): Collection
    {
        if ($login->subject_type !== 'tenant') {
            return collect();
        }

        return RentalPaidReport::withoutGlobalScopes()->where('company_id', (int) $login->company_id)->where('tenant_id', (int) $login->subject_id)->orderByDesc('id')->limit($limit)->get();
    }

    private static function queue(int $companyId, ?User $by): \Illuminate\Database\Query\Builder
    {
        return RentalAccess::scope(DB::table('rental_paid_reports as r')->where('r.company_id', $companyId)->where('r.status', 'pending'), $by, 'r.property_id');
    }

    private function assertPending(RentalPaidReport $r): void
    {
        if ($r->status === 'confirmed') {
            $number = RentalPayment::withoutGlobalScopes()->where('company_id', $r->company_id)->whereKey($r->payment_id)->value('number');
            throw BusinessRuleException::make('already_confirmed', 'This payment was already confirmed'.($number ? ': receipt '.$number : '').'.');
        }
        if ($r->status === 'declined') {
            throw BusinessRuleException::make('already_declined', 'This report was already declined.');
        }
    }

    /** The tenant's lease it is for: the one named, or their only live one. A lease of someone else's answers as not found. */
    private function leaseOf(RentalLogin $login, ?int $leaseId): RentalLease
    {
        $q = RentalLease::withoutGlobalScopes()->where('company_id', (int) $login->company_id)->where('tenant_id', (int) $login->subject_id);
        if ($leaseId !== null) {
            $lease = (clone $q)->where('status', '!=', 'cancelled')->find($leaseId);
            if ($lease === null) {
                throw BusinessRuleException::make('not_found', 'That home was not found.');
            }

            return $lease;
        }
        $live = (clone $q)->whereIn('status', RentalLease::LIVE)->get();
        if ($live->count() === 1) {
            return $live->first();
        }
        throw BusinessRuleException::make($live->isEmpty() ? 'not_found' : 'lease_required', $live->isEmpty() ? 'You have no current home here.' : 'Choose which home this payment is for.');
    }

    private static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
