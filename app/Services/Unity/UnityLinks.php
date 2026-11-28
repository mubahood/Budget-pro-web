<?php

namespace App\Services\Unity;

use App\Models\StoredFile;

/** Links the API hands out for a group's files: members' photos (public files, like a product picture). */
final class UnityLinks
{
    /**
     * Photo links for many stored_files ids at once (one query). Unknown ids and other groups' files get none.
     *
     * @param  array<int, int|string|null>  $fileIds
     * @return array<int, string> file id => url
     */
    public static function photos(int $companyId, array $fileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (StoredFile::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->get() as $f) {
            if (($url = $f->url()) !== null) {
                $out[(int) $f->id] = $url;
            }
        }

        return $out;
    }

    public static function photo(int $companyId, ?int $fileId): ?string
    {
        return $fileId ? (self::photos($companyId, [$fileId])[$fileId] ?? null) : null;
    }
}
