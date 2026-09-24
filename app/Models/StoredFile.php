<?php

namespace App\Models;

use App\Scopes\CompanyScope;
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

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function payload(): array
    {
        return ['file_uuid' => $this->uuid, 'path' => $this->path, 'url' => $this->url(), 'purpose' => $this->purpose, 'size_bytes' => (int) $this->size_bytes];
    }
}
