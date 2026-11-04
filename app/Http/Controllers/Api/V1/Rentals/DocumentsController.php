<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalDocument;
use App\Services\Rentals\Documents;
use App\Services\Rentals\RentalLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Papers on a tenant, lease, property or landlord (Documents): the file goes up through POST files, purpose rental_document. */
class DocumentsController extends RentalsController
{
    /** GET rentals/documents?owner_type&owner_id */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(app(Documents::class)->list($this->user($request), (string) $request->query('owner_type', ''), (int) $request->query('owner_id'))
            ->map(fn ($d) => $this->shape($d, $request))->all()));
    }

    /** POST rentals/documents {owner_type, owner_id, file_uuid, name, kind: agreement|id|photo|other} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->shape(app(Documents::class)->add($this->user($request), (string) $request->input('owner_type', ''), (int) $request->input('owner_id'),
            $request->only(['file_uuid', 'name', 'kind'])), $request), 'Document added.'));
    }

    /** DELETE rentals/documents/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $doc = RentalDocument::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id) ?? throw $this->notFoundRecord('document');
            app(Documents::class)->remove($this->user($request), $doc);

            return $this->success(null, 'Document removed.');
        });
    }

    /** @return array<string, mixed> */
    private function shape(RentalDocument $d, Request $request): array
    {
        return ['id' => (int) $d->id, 'owner_type' => (string) $d->owner_type, 'owner_id' => (int) $d->owner_id, 'kind' => (string) $d->kind, 'name' => (string) $d->name,
            'file_uuid' => (string) $d->file_uuid, 'url' => RentalLinks::file((int) $d->company_id, (string) $d->file_uuid, 'staff', (int) $this->user($request)->id), 'created_at' => $d->created_at?->toIso8601String()];
    }
}
