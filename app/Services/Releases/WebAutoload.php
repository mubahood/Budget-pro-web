<?php

namespace App\Services\Releases;

/**
 * The web app loads the kernel's classes through Composer: composer.json maps `App\` to
 * ["app/", "../budget-pro/app/"], and `composer dump-autoload` bakes that sibling folder's NAME
 * into vendor/composer (the PSR-4 fallback and ~380 classmap entries).
 *
 * In a standalone package the kernel folder is `budget_pro`, so a staged web app is relinked
 * before packing: every "/../budget-pro/" in the generated autoloader (and composer.json, so a
 * technician's own dump-autoload agrees) becomes "/../budget_pro/". Idempotent; it refuses a stage
 * whose autoloader does not reach the kernel at all. BUDGETPRO_PATH (config budgetpro.path) then
 * only tells the web app where to read the kernel's config files and views.
 */
final class WebAutoload
{
    private const GENERATED = ['vendor/composer/autoload_psr4.php', 'vendor/composer/autoload_static.php', 'vendor/composer/autoload_classmap.php'];

    /** @return int how many files were changed */
    public static function relink(string $webStage, string $from = 'budget-pro', string $to = PackageLayout::KERNEL): int
    {
        $changed = 0;
        foreach ([...self::GENERATED, 'composer.json'] as $relative) {
            $file = $webStage.'/'.$relative;
            if (! is_file($file)) {
                if ($relative === 'vendor/composer/autoload_psr4.php') {
                    throw new \RuntimeException('The web app has no Composer autoloader (vendor/composer/autoload_psr4.php).');
                }

                continue;
            }
            $contents = (string) file_get_contents($file);
            $updated = str_replace(["/../{$from}/", "\"../{$from}/"], ["/../{$to}/", "\"../{$to}/"], $contents);
            if ($updated !== $contents) {
                file_put_contents($file, $updated);
                $changed++;
            }
        }

        $psr4 = (string) file_get_contents($webStage.'/vendor/composer/autoload_psr4.php');
        if (! str_contains($psr4, "/../{$to}/app")) {
            throw new \RuntimeException("The web app's autoloader does not load the kernel from ../{$to}/app.");
        }

        return $changed;
    }
}
