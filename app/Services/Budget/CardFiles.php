<?php

namespace App\Services\Budget;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Where invitation card designs are kept: one private folder under the kernel's storage, per account and event, which
 * both apps resolve to the same absolute path. Outside every public folder and symlink, so a card opens only through
 * the portal's signed-in route, which checks the account. Files take the name we give them, never the uploader's.
 * A path "sample:<file>" is a card that ships with the system, kept in resources: the try-it design here, and the demo's
 * two real cards in the web portal's own resources, because they show real people and this repository is public.
 */
final class CardFiles
{
    public const SAMPLE = 'sample:';

    public static function root(): string
    {
        return rtrim((string) (config('budget.cards_root') ?: dirname(__DIR__, 3).'/storage/app/event-cards'), '/\\');
    }

    /** Keep an upload. @return string the path to save on the row, relative to the root */
    public static function store(UploadedFile $file, int $companyId, int $eventId, string $name, string $ext): string
    {
        $folder = self::root()."/{$companyId}/{$eventId}";
        File::ensureDirectoryExists($folder, 0750);
        $relative = "{$companyId}/{$eventId}/{$name}.{$ext}";
        if (! File::copy((string) $file->getRealPath(), self::root().'/'.$relative)) {
            throw new \RuntimeException('The card could not be saved.');
        }

        return $relative;
    }

    /** The absolute path of a kept file, or null when it is not there. */
    public static function path(?string $relative): ?string
    {
        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return null;
        }
        if (str_starts_with($relative, self::SAMPLE)) {
            $file = basename(substr($relative, strlen(self::SAMPLE)));
            // The kernel's own, then the portal's: whichever app is asking, the portal sits beside the kernel.
            foreach ([dirname(__DIR__, 3), base_path(), dirname(__DIR__, 4).'/budget-pro-new'] as $app) {
                if (is_file($app.'/resources/data/event-cards/'.$file)) {
                    return $app.'/resources/data/event-cards/'.$file;
                }
            }

            return null;
        }
        $absolute = self::root().'/'.ltrim($relative, '/');

        return is_file($absolute) ? $absolute : null;
    }

    public static function delete(?string $relative): void
    {
        if ($relative !== null && ! str_starts_with($relative, self::SAMPLE) && ($absolute = self::path($relative)) !== null) {
            @unlink($absolute);
        }
    }
}
