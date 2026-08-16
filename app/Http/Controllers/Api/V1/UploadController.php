<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Authenticated, validated file upload. Replaces the old unauthenticated
 * Utils::file_upload path that accepted any extension into the web root.
 */
class UploadController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $maxKb = (int) config('saas.uploads.max_kb', 5120);
        $mimes = implode(',', config('saas.uploads.allowed_mimes', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']));

        $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}", "mimes:{$mimes}"],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Generate a non-guessable, safe filename — never trust the client's name.
        $filename = date('Ymd').'_'.Str::random(24).'.'.$extension;

        $destination = public_path('storage/images');
        if (! is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }

        $file->move($destination, $filename);

        $path = 'images/'.$filename;

        return $this->created([
            'file_name' => $path,
            'url' => url('storage/'.$path),
        ], 'File uploaded successfully.');
    }
}
