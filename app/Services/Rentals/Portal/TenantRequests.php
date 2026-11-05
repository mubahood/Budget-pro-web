<?php

namespace App\Services\Rentals\Portal;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalActivity;
use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\StoredFile;
use App\Services\Billing\Quotas;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\RentalAudit;
use App\Support\LocalDate;
use App\Support\Rentals\Day;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * What a signed-in tenant asks of their agency (KR-16, docs/estate-ledger/04-architecture.md §10.3), for the API's
 * `rentals/me/*` and the web portal alike, keyed by the RentalLogin:
 *
 * - **Report a problem**: a maintenance request on their home (Maintenance::open, reported by the tenant), with
 *   photos they uploaded. The same uuid twice is one request.
 * - **Give notice**: recorded as a request for the staff, not applied: a `tenant.notice_given` activity and a note in
 *   the lease's follow-ups with the day they plan to leave. Staff confirm it with Leases::notice (rentals_manage),
 *   which is what stops the billing; until then nothing about the lease or its bills changes.
 */
final class TenantRequests
{
    public const NOTICE_ACTION = 'tenant.notice_given';

    /**
     * Keep a tenant's photo of a problem, for maintenance(), in private storage (PrivateFiles; the type is read from
     * the content, never the upload's own name): the account's storage quota applies and the same uuid twice is one file.
     *
     * @return array{file: StoredFile, replayed: bool}
     */
    public function photo(RentalLogin $login, string $uuid, UploadedFile $file): array
    {
        $cid = (int) $login->company_id;
        $this->tenantId($login);
        if (! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The file id is not valid.');
        }
        $existing = StoredFile::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ($existing->purpose !== 'rental_photo' || $existing->uploaded_by_id !== null || $existing->device_id !== 'portal:'.$login->id) {
                throw BusinessRuleException::make('idempotency_conflict', 'This file id was already used.');
            }

            return ['file' => $existing, 'replayed' => true];
        }
        if (! isset(PrivateFiles::TYPES[(string) $file->getMimeType()])) {
            throw BusinessRuleException::make('file_type', 'Send a photo (jpg, png or webp) or a PDF.');
        }
        (new Quotas())->assertCanStore(Company::withoutGlobalScopes()->findOrFail($cid), (int) $file->getSize());
        $path = PrivateFiles::store($file, $cid, $uuid);
        try {
            $stored = StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => 'rental_photo', 'path' => $path,
                'mime' => $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by_id' => null, 'device_id' => 'portal:'.$login->id]);
        } catch (UniqueConstraintViolationException) {
            // Two sends at once: the first one stands, and this one kept the same name on the disk.
            $first = StoredFile::withoutGlobalScopes()->where('uuid', $uuid)->where('company_id', $cid)->where('device_id', 'portal:'.$login->id)->first();

            return $first !== null ? ['file' => $first, 'replayed' => true] : throw BusinessRuleException::make('idempotency_conflict', 'This file id was already used.');
        }

        return ['file' => $stored, 'replayed' => false];
    }

    /**
     * $data: uuid, lease_id (needed when the tenant has more than one home), title, details, priority, photos (their
     * file uuids). @return array{maintenance: RentalMaintenance, replayed: bool}
     */
    public function maintenance(RentalLogin $login, array $data): array
    {
        $cid = (int) $login->company_id;
        $tenantId = $this->tenantId($login);
        $uuid = isset($data['uuid']) && $data['uuid'] !== '' ? (string) $data['uuid'] : null;
        if ($uuid !== null && ! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The request id is not valid.');
        }
        if ($uuid !== null && ($same = RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first()) !== null) {
            if ((int) $same->tenant_id !== $tenantId || $same->reported_by !== 'tenant') {
                throw BusinessRuleException::make('idempotency_conflict', 'This request id was already used.');
            }

            return ['maintenance' => $same, 'replayed' => true];
        }
        $lease = $this->liveLease($login, isset($data['lease_id']) ? (int) $data['lease_id'] : null);
        $photos = array_values(array_filter(array_map('strval', (array) ($data['photos'] ?? [])), fn ($u) => Str::isUuid($u)));
        $photos = $photos === [] ? [] : StoredFile::withoutGlobalScopes()->where('company_id', $cid)->whereIn('uuid', $photos)->whereNull('uploaded_by_id')->where('device_id', 'portal:'.$login->id)->pluck('uuid')->map(fn ($u) => (string) $u)->all();
        $priority = in_array($data['priority'] ?? 'normal', array_keys(Maintenance::PRIORITIES), true) ? (string) ($data['priority'] ?? 'normal') : 'normal';
        $m = app(Maintenance::class)->open(null, $cid, ['unit_id' => $lease->unit_id, 'title' => $data['title'] ?? '', 'details' => $data['details'] ?? null,
            'priority' => $priority, 'photos' => $photos, 'uuid' => $uuid ?? (string) Str::uuid()], ['tenant', $tenantId]);

        return ['maintenance' => $m->fresh(), 'replayed' => false];
    }

    /**
     * The tenant gives notice: $data lease_id, move_out_on (Y-m-d, today or later), notes, uuid. Recorded for the
     * staff to confirm (see the class); the same uuid twice is one notice.
     *
     * @return array{lease_id: int, move_out_on: string, notes: ?string, status: string, recorded_at: string, replayed: bool}
     */
    public function notice(RentalLogin $login, array $data): array
    {
        $cid = (int) $login->company_id;
        $tenantId = $this->tenantId($login);
        $uuid = isset($data['uuid']) && $data['uuid'] !== '' ? (string) $data['uuid'] : null;
        if ($uuid !== null && ! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The notice id is not valid.');
        }
        if ($uuid !== null && ($done = RentalActivity::withoutGlobalScopes()->where('company_id', $cid)->where('action', self::NOTICE_ACTION)
            ->where('actor_type', 'tenant')->where('actor_id', $tenantId)->where('meta->uuid', $uuid)->first()) !== null) {
            return self::answer($done, true);
        }
        $lease = $this->liveLease($login, isset($data['lease_id']) ? (int) $data['lease_id'] : null);
        $raw = trim((string) ($data['move_out_on'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw BusinessRuleException::make('date_required', 'Choose the day you plan to move out.');
        }
        $out = LocalDate::date($cid, Day::strict($raw, 'the day you plan to move out'));
        $today = LocalDate::today($cid);
        if ($out->lt($today) || $out->lt($lease->start_date)) {
            throw BusinessRuleException::make('date_invalid', 'Choose a move-out day from today on.');
        }
        if ($lease->status === 'notice') {
            throw BusinessRuleException::make('notice_given', 'Your notice is already recorded: moving out on '.$lease->move_out_on?->format('j M Y').'. Get in touch to change it.');
        }
        $notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 300) : null;

        return RentalLedger::locked($cid, function () use ($cid, $tenantId, $lease, $out, $notes, $uuid) {
            $words = 'Gave notice in their own app: moving out on '.$out->format('j M Y').'. Confirm it under Notice.';
            RentalFollowUp::create(['company_id' => $cid, 'lease_id' => $lease->id, 'tenant_id' => $tenantId, 'channel' => 'note',
                'note' => mb_substr($words.($notes ? ' '.$notes : ''), 0, 500), 'owed_minor' => Leases::position($lease)['owed_minor'], 'promise_on' => null,
                'created_by_id' => null, 'created_at' => now()]);
            RentalAudit::record($cid, self::NOTICE_ACTION, $lease, "Notice from the tenant on lease {$lease->number}: moving out on {$out->format('j M Y')}",
                ['move_out_on' => $out->toDateString(), 'notes' => $notes, 'uuid' => $uuid], 'tenant', $tenantId);
            $done = RentalActivity::withoutGlobalScopes()->where('company_id', $cid)->where('action', self::NOTICE_ACTION)->where('subject_id', $lease->id)->orderByDesc('id')->firstOrFail();

            return self::answer($done, false);
        });
    }

    /** The tenant's notices waiting for the staff to confirm, per lease (the latest each). @return array<int, array<string, mixed>> lease id => notice */
    public static function pendingNotices(int $companyId, array $leaseIds): array
    {
        $out = [];
        foreach (RentalActivity::withoutGlobalScopes()->where('company_id', $companyId)->where('action', self::NOTICE_ACTION)->where('subject_type', 'Lease')
            ->whereIn('subject_id', $leaseIds ?: [0])->orderBy('id')->get() as $a) {
            $out[(int) $a->subject_id] = self::answer($a, false) + ['_at' => (int) $a->id];
        }
        foreach ($out as $leaseId => $n) {
            $dealt = RentalActivity::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('action', ['lease.notice', 'lease.notice_withdrawn'])->where('subject_type', 'Lease')
                ->where('subject_id', $leaseId)->where('id', '>', $n['_at'])->exists();
            if ($dealt) {
                unset($out[$leaseId]);
            } else {
                unset($out[$leaseId]['_at']);
            }
        }

        return $out;
    }

    private static function answer(RentalActivity $a, bool $replayed): array
    {
        $meta = (array) ($a->meta ?? []);

        return ['lease_id' => (int) $a->subject_id, 'move_out_on' => (string) ($meta['move_out_on'] ?? ''), 'notes' => $meta['notes'] ?? null, 'status' => 'requested',
            'recorded_at' => $a->created_at?->toIso8601String() ?? now()->toIso8601String(), 'replayed' => $replayed];
    }

    private function tenantId(RentalLogin $login): int
    {
        if ($login->subject_type !== 'tenant') {
            throw BusinessRuleException::make('not_found', 'This is for tenants.');
        }

        return (int) $login->subject_id;
    }

    /** The tenant's live lease: the one named, or their only one. */
    private function liveLease(RentalLogin $login, ?int $leaseId): RentalLease
    {
        $q = RentalLease::withoutGlobalScopes()->where('company_id', $login->company_id)->where('tenant_id', $this->tenantId($login))->whereIn('status', RentalLease::LIVE);
        if ($leaseId !== null && $leaseId > 0) {
            return (clone $q)->find($leaseId) ?? throw BusinessRuleException::make('not_found', 'That home was not found among yours.');
        }
        $live = $q->orderBy('id')->get();
        if ($live->count() !== 1) {
            throw $live->isEmpty() ? BusinessRuleException::make('lease_not_live', 'You have no current home on this account.')
                : BusinessRuleException::make('lease_required', 'Choose which of your homes this is about.');
        }

        return $live->first();
    }
}
