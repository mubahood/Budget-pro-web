<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use App\Services\Rentals\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves tenants' photos and papers kept before they went private off the public folders: the kernel's public disk and
 * `public/storage` (the web portal wrote there, and on some servers it is not a link to the disk). Safe to run again.
 */
class RentalsFilesPrivate extends Command
{
    protected $signature = 'rentals:files-private';

    protected $description = 'Estate Ledger: move tenants\' photos and documents from the public folders to private storage';

    public function handle(): int
    {
        $roots = array_values(array_unique(array_filter(array_map('realpath', [Storage::disk('public')->path(''), public_path('storage')]))));
        $moved = $done = $missing = $failed = 0;

        StoredFile::withoutGlobalScopes()->whereIn('purpose', PrivateFiles::PURPOSES)->chunkById(200, function ($files) use ($roots, &$moved, &$done, &$missing, &$failed) {
            foreach ($files as $file) {
                $path = ltrim((string) $file->path, '/');
                $copies = str_contains($path, '..') ? [] : array_values(array_filter(array_map(fn ($root) => $root.'/'.$path, $roots), 'is_file'));
                $kept = PrivateFiles::path($path) !== null;
                if (! $kept && $copies === []) {
                    $missing++;

                    continue;
                }
                if (! $kept && ! PrivateFiles::put($path, (string) file_get_contents($copies[0]))) {
                    $failed++;
                    $this->error("Could not keep {$path}");

                    continue;
                }
                foreach ($copies as $copy) {
                    if (! @unlink($copy)) {
                        $failed++;
                        $this->error("Could not remove {$copy}");
                    }
                }
                $copies === [] ? $done++ : $moved++;
            }
        });

        $this->info("Moved {$moved}, already private {$done}, no file found {$missing}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
