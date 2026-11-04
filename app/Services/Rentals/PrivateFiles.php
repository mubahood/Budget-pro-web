<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Logins\RentalLogins;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tenants' photos and papers (`files` with a purpose below) live under one private folder, `rentals.private_root`,
 * which both apps resolve to the same absolute path. It is outside every public folder and storage symlink, so no
 * address reaches a file directly: it opens only through a RentalLinks signed link, and each open checks the account
 * and the person the link was made for. Every other purpose stays on the public disk, as before.
 */
final class PrivateFiles
{
    public const PURPOSES = ['rental_photo', 'rental_document'];

    /** What may be kept: the type read from the content => the extension it is saved under (never the client's own name). */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public static function isPrivate(?string $purpose): bool
    {
        return in_array($purpose, self::PURPOSES, true);
    }

    public static function root(): string
    {
        $root = rtrim((string) config('rentals.private_root'), '/\\');
        if ($root === '' || preg_match('~[/\\\\](public|public_html)([/\\\\]|$)~', $root)) {
            throw new \RuntimeException('rentals.private_root must be set, outside any public folder.');
        }

        return $root;
    }

    /** Keep an upload for an account. @return string the path to save on the file's row, relative to the root */
    public static function store(UploadedFile $file, int $companyId, string $uuid): string
    {
        $ext = self::TYPES[(string) $file->getMimeType()] ?? throw BusinessRuleException::make('file_type', 'Send a photo (jpg, png or webp) or a PDF.');
        $path = self::writer()->putFileAs("files/{$companyId}/".now()->format('Y/m'), $file, $uuid.'.'.$ext, ['visibility' => 'private']);
        if (! is_string($path) || $path === '') {
            throw BusinessRuleException::make('file_not_saved', 'The file could not be saved. Please try again.');
        }

        return $path;
    }

    /** Keep bytes the system made itself (the demo's sample papers, a file moved in from the public disk). */
    public static function put(string $relative, string $bytes): bool
    {
        return self::writer()->put($relative, $bytes, ['visibility' => 'private']);
    }

    /** The absolute path of a kept file, or null when it is not there. */
    public static function path(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $absolute = self::root().'/'.ltrim($relative, '/');

        return is_file($absolute) ? $absolute : null;
    }

    public static function delete(string $relative): void
    {
        if (($absolute = self::path($relative)) !== null) {
            @unlink($absolute);
        }
    }

    /** All of an account's files, when the account itself goes. */
    public static function deleteAccount(int $companyId): void
    {
        File::deleteDirectory(self::root().'/files/'.$companyId);
    }

    /**
     * The person a link was made for may still see this file: a staff login of the account with rentals_view (for a
     * paper on a record, by the same rule as Documents::list: their properties, an ID copy only if they manage
     * tenants), or an open sign-in of a tenant or landlord of the account (who are never shown papers).
     */
    public static function maySee(StoredFile $file, string $viewer, int $viewerId): bool
    {
        $cid = (int) $file->company_id;
        $papers = RentalDocument::withoutGlobalScopes()->where('company_id', $cid)->where('file_uuid', $file->uuid)->get();
        if ($viewer === 'staff') {
            $user = User::withoutGlobalScopes()->find($viewerId);
            if ($user === null || (int) $user->company_id !== $cid || ! RentalAccess::can($user, 'rentals_view')) {
                return false;
            }

            return $papers->isEmpty() || $papers->contains(fn (RentalDocument $d) => self::staffMaySee($user, $d));
        }
        if ($viewer === 'portal') {
            $login = app(RentalLogins::class)->resolve($viewerId);

            return $login !== null && (int) $login->company_id === $cid && $papers->isEmpty();
        }

        return false;
    }

    /**
     * Answer an already signature-checked link, or null (a 404 for the caller) when the person may not see the file or
     * it is not there. Never kept by a shared cache or a browser; shown in the page only when it is an image or a PDF.
     */
    public static function respond(StoredFile $file, Request $request): ?BinaryFileResponse
    {
        if (! self::maySee($file, (string) $request->query('by'), (int) $request->query('by_id'))) {
            return null;
        }
        $absolute = self::path((string) $file->path);
        if ($absolute === null) {
            return null;
        }
        $ext = self::TYPES[(string) $file->mime] ?? null;

        // Not response()->file(): BinaryFileResponse marks itself public unless told otherwise, whatever Cache-Control it is given.
        return new BinaryFileResponse($absolute, 200, ['Content-Type' => $ext !== null ? (string) $file->mime : 'application/octet-stream',
            'Content-Disposition' => ($ext !== null ? 'inline' : 'attachment').'; filename="'.$file->uuid.'.'.($ext ?? 'bin').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], false);
    }

    private static function staffMaySee(User $user, RentalDocument $paper): bool
    {
        try {
            return app(Documents::class)->list($user, (string) $paper->owner_type, (int) $paper->owner_id)->contains('id', $paper->id);
        } catch (BusinessRuleException) {
            return false;
        }
    }

    /** The folder for writing: created on first use, kept to this account's user wherever the server allows it. */
    private static function writer(): FilesystemAdapter
    {
        $root = self::root();
        if (! is_dir($root)) {
            @mkdir($root, 0700, true);
        }
        @chmod($root, 0700);

        $disk = Storage::build(['driver' => 'local', 'root' => $root, 'visibility' => 'private',
            'permissions' => ['file' => ['public' => 0600, 'private' => 0600], 'dir' => ['public' => 0700, 'private' => 0700]]]);
        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('The private folder needs a local disk.');
        }

        return $disk;
    }
}
