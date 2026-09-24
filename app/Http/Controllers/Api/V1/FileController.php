<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `POST /files` (plan A.6, P1-10): multipart upload keyed by a client uuid, so a
 * retried upload from the device's file queue never duplicates the file.
 */
class FileController extends Controller
{
    use ApiResponse;

    public const PURPOSES = ['product_image', 'receipt', 'avatar', 'logo', 'adjustment_photo', 'document', 'other'];

    public function store(Request $request)
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'purpose' => ['required', 'in:'.implode(',', self::PURPOSES)],
            'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);
        $companyId = (int) $request->user()->company_id;

        $existing = StoredFile::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['uuid'])->first();
        if ($existing) {
            return $this->success($existing->payload(), 'File already uploaded.');
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs("files/{$companyId}/".now()->format('Y/m'), $data['uuid'].'.'.$ext, 'public');

        $stored = StoredFile::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'uuid' => $data['uuid'], 'purpose' => $data['purpose'], 'path' => $path,
            'mime' => $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by_id' => $request->user()->id,
            'device_id' => $request->header('X-Device-Id'),
        ]);

        return $this->created($stored->payload(), 'File uploaded.');
    }
}
