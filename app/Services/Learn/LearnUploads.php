<?php

namespace App\Services\Learn;

use App\Models\Learn\LearnLesson;
use Illuminate\Support\Facades\Storage;

/**
 * A lesson's own video file or thumbnail, uploaded from the admin page in chunks (the same protocol as the promo
 * videos: in order, whole, checked by SHA-1), so the host's upload limit never matters. Files go to public storage.
 */
class LearnUploads
{
    public const KINDS = ['video' => ['mp4', 'webm', 'm4v', 'mov'], 'thumbnail' => ['jpg', 'jpeg', 'png', 'webp']];

    /** @return array{done: bool, received?: int, path?: string, url?: string} */
    public function receiveChunk(LearnLesson $lesson, string $kind, string $upload, int $offset, int $size, string $sha1, string $ext, string $body): array
    {
        $ext = strtolower($ext);
        if (! isset(self::KINDS[$kind]) || ! in_array($ext, self::KINDS[$kind], true)) {
            throw new \DomainException('Unknown file kind or extension.');
        }
        if (! preg_match('/^[a-f0-9]{32}$/', $upload) || ! preg_match('/^[a-f0-9]{40}$/', $sha1)) {
            throw new \DomainException('Bad upload id or checksum.');
        }
        $max = (int) config("learn.max.{$kind}");
        if ($size < 1 || $size > $max) {
            throw new \DomainException("The {$kind} must be between 1 byte and ".intdiv($max, 1048576).' MB.');
        }
        $dir = storage_path('app/learn-uploads');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $part = $dir.'/'.$upload.'.part';
        clearstatcache(true, $part);
        $have = is_file($part) ? filesize($part) : 0;
        if ($offset !== $have) {
            throw new UploadConflict("Out of order: received {$have}");
        }
        if ($have + strlen($body) > $size) {
            @unlink($part);
            throw new \DomainException('More bytes than the size given.');
        }
        file_put_contents($part, $body, FILE_APPEND | LOCK_EX);
        clearstatcache(true, $part);
        $have = filesize($part);
        if ($have < $size) {
            return ['done' => false, 'received' => $have];
        }
        try {
            if (! hash_equals($sha1, (string) sha1_file($part))) {
                throw new \DomainException('The file arrived damaged (checksum differs). Send it again.');
            }

            return $this->store($lesson, $kind, $part, $sha1, $ext);
        } finally {
            @unlink($part);
            foreach (glob($dir.'/*.part') ?: [] as $f) {
                if (@filemtime($f) < time() - 86400) {
                    @unlink($f);
                }
            }
        }
    }

    /** @return array{done: true, path: string, url: string} */
    public function store(LearnLesson $lesson, string $kind, string $file, string $sha1, string $ext): array
    {
        $disk = Storage::disk(config('learn.disk'));
        $path = trim((string) config('learn.dir'), '/').'/'.$lesson->id.'/'.$kind.'-'.substr($sha1, 0, 12).'.'.$ext;
        $stream = fopen($file, 'rb');
        $disk->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $old = $kind === 'video' ? $lesson->file_path : $lesson->thumbnail_path;
        if ($kind === 'video') {
            $lesson->forceFill(['file_path' => $path, 'file_size' => filesize($file), 'source_type' => 'file'])->save();
        } else {
            $lesson->forceFill(['thumbnail_path' => $path])->save();
        }
        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        return ['done' => true, 'path' => $path, 'url' => $disk->url($path)];
    }
}
