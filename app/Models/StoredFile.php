<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\RentalLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StoredFile extends Model
{
    protected $table = 'files';

    protected $fillable = ['company_id', 'uuid', 'purpose', 'path', 'mime', 'size_bytes', 'uploaded_by_id', 'device_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function isPrivate(): bool
    {
        return PrivateFiles::isPrivate($this->purpose);
    }

    /**
     * Where to open the file. A private one (PrivateFiles) has no public address: only a signed link for the person
     * asking ($viewer, $viewerId as RentalLinks::VIEWERS), and null when no person is named.
     */
    public function url(?string $viewer = null, ?int $viewerId = null): ?string
    {
        if ($this->isPrivate()) {
            return RentalLinks::file((int) $this->company_id, (string) $this->uuid, $viewer, $viewerId);
        }

        return Storage::disk('public')->url($this->path);
    }

    public function payload(?string $viewer = null, ?int $viewerId = null): array
    {
        return ['file_uuid' => $this->uuid, 'path' => $this->path, 'url' => $this->url($viewer, $viewerId), 'purpose' => $this->purpose, 'size_bytes' => (int) $this->size_bytes];
    }
}
