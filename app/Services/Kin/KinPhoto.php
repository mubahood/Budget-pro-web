<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A person's photo for the tree: cut to the middle square and made small (SIZE pixels, JPEG) on the server, kept on the
 * public disk as a `files` row (purpose avatar) the way every upload is, and named by the person's photo_file_id. Saving
 * the person moves their server_seq on, so the tree's ETag changes.
 */
class KinPhoto
{
    public const SIZE = 320;

    public const QUALITY = 82;

    public function store(Company $company, SaccoMember $person, UploadedFile $file, User $by, ?string $deviceId = null): SaccoMember
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $img = $raw !== '' ? @imagecreatefromstring($raw) : false;
        if ($img === false) {
            throw BusinessRuleException::make('photo_unreadable', 'That picture could not be read. Send a JPG, PNG or WebP photo.', ['photo' => ['Not a readable picture.']]);
        }
        $img = $this->upright($img, $raw);
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // a transparent PNG goes on white
        imagecopyresampled($out, $img, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($out, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        (new \App\Services\Billing\Quotas())->assertCanStore($company, strlen($jpeg));
        $uuid = (string) Str::uuid();
        $path = 'files/'.$company->id.'/'.now()->format('Y/m').'/'.$uuid.'.jpg';
        Storage::disk('public')->put($path, $jpeg);
        $stored = StoredFile::withoutGlobalScopes()->create(['company_id' => $company->id, 'uuid' => $uuid, 'purpose' => 'avatar', 'path' => $path,
            'mime' => 'image/jpeg', 'size_bytes' => strlen($jpeg), 'uploaded_by_id' => $by->id, 'device_id' => $deviceId]);
        $person->photo_file_id = $stored->id;
        $person->save();

        return $person->fresh();
    }

    /** A phone's JPEG says which way up it is (EXIF Orientation): turn it so. */
    private function upright(\GdImage $img, string $raw): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($raw, "\xFF\xD8")) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($raw));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $img : (imagerotate($img, $angle, 0) ?: $img);
    }
}
