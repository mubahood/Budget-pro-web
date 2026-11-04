<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalDocument;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Onboarding\PublicDemo;
use App\Services\Rentals\Directory\Tenants;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Papers kept on a tenant, lease, property or landlord (docs/estate-ledger/04-architecture.md §3.1, §18): a signed agreement,
 * an ID copy, a photo. The file itself goes up through `files` with purpose `rental_document`; this names it and hangs
 * it on its owner. Adding and removing need rentals_manage, reading rentals_view; a caretaker reads their properties'
 * papers only, and an ID copy is for those who may manage tenants (§3.0: personal identifiers), never a viewer.
 */
final class Documents
{
    public const OWNERS = ['tenant', 'lease', 'property', 'landlord'];

    public const KINDS = ['agreement', 'id', 'photo', 'other'];

    public const FILE_PURPOSE = 'rental_document';

    /** $data: file_uuid, name, kind. The same file added to the same owner again is the one document (a retry). */
    public function add(User $by, string $ownerType, int $ownerId, array $data): RentalDocument
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        PublicDemo::guard($cid, 'changing documents');
        $owner = $this->owner($by, $ownerType, $ownerId);
        $uuid = (string) ($data['file_uuid'] ?? '');
        $file = StoredFile::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->where('purpose', self::FILE_PURPOSE)->first();
        if ($file === null) {
            throw BusinessRuleException::make('file_invalid', 'Upload the file first (as a document), then add it here.');
        }
        $same = RentalDocument::withoutGlobalScopes()->where('company_id', $cid)->where('owner_type', $ownerType)->where('owner_id', $owner->getKey())->where('file_uuid', $uuid)->first();
        if ($same !== null) {
            return $same;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw BusinessRuleException::make('name_required', 'Say what the document is, for example "Signed agreement".');
        }
        $kind = in_array($data['kind'] ?? 'other', self::KINDS, true) ? (string) ($data['kind'] ?? 'other') : 'other';
        $doc = RentalDocument::create(['company_id' => $cid, 'owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'kind' => $kind, 'name' => mb_substr($name, 0, 150),
            'file_uuid' => $uuid, 'uploaded_by_id' => $by->id]);
        RentalAudit::record($cid, 'document.added', $doc, 'Added a document to a '.$ownerType, ['owner_type' => $ownerType, 'owner_id' => (int) $owner->getKey(), 'kind' => $kind], 'staff', (int) $by->id);

        return $doc;
    }

    /** Take a document off: its row and, when nothing else uses it, the file. */
    public function remove(User $by, RentalDocument $document): void
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        if ((int) $document->company_id !== $cid) {
            throw BusinessRuleException::make('not_found', 'That document was not found.');
        }
        PublicDemo::guard($cid, 'changing documents');
        $this->owner($by, (string) $document->owner_type, (int) $document->owner_id);
        $meta = ['owner_type' => (string) $document->owner_type, 'owner_id' => (int) $document->owner_id, 'kind' => (string) $document->kind];
        $uuid = (string) $document->file_uuid;
        RentalAudit::record($cid, 'document.removed', $document, 'Removed a document from a '.$document->owner_type, $meta, 'staff', (int) $by->id);
        $document->delete();
        if (! RentalDocument::withoutGlobalScopes()->where('company_id', $cid)->where('file_uuid', $uuid)->exists()) {
            $file = StoredFile::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
            if ($file !== null) {
                $file->isPrivate() ? PrivateFiles::delete((string) $file->path) : Storage::disk('public')->delete((string) $file->path);
                $file->delete();
            }
        }
    }

    /** An owner's documents, oldest first. @return Collection<int, RentalDocument> */
    public function list(User $by, string $ownerType, int $ownerId): Collection
    {
        RentalAccess::authorize($by, 'rentals_view');
        $owner = $this->owner($by, $ownerType, $ownerId);
        $q = RentalDocument::withoutGlobalScopes()->where('company_id', $by->company_id)->where('owner_type', $ownerType)->where('owner_id', $owner->getKey());
        if (! RentalAccess::can($by, 'rentals_manage')) {
            $q->where('kind', '!=', 'id');
        }

        return $q->orderBy('id')->get();
    }

    /** The record a document hangs on, in the caller's account and properties. */
    private function owner(User $by, string $type, int $id): RentalTenant|RentalLease|RentalProperty|RentalLandlord
    {
        if (! in_array($type, self::OWNERS, true)) {
            throw BusinessRuleException::make('owner_invalid', 'A document belongs to a tenant, a lease, a property or a landlord.');
        }
        $cid = (int) $by->company_id;
        $ids = RentalAccess::propertyIds($by);
        $found = match ($type) {
            'tenant' => Tenants::visibleTo(RentalTenant::withoutGlobalScopes()->where('company_id', $cid), $by)->find($id),
            'lease' => RentalAccess::scope(RentalLease::withoutGlobalScopes()->where('company_id', $cid), $by)->find($id),
            'property' => RentalAccess::scope(RentalProperty::withoutGlobalScopes()->where('company_id', $cid), $by, 'id')->find($id),
            'landlord' => RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)
                ->when($ids !== null, fn ($q) => $q->whereIn('id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids ?: [0])->select('landlord_id')))->find($id),
        };

        return $found ?? throw BusinessRuleException::make('not_found', "That {$type} was not found.");
    }
}
