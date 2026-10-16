<?php

namespace App\Support;

use App\Models\User;
use App\Services\Install\DatabaseBackup;
use App\Services\Install\InstallationBackups;
use App\Services\Team\Permissions;
use App\Support\Licensing\InstalledLicence;

/**
 * The owner's banners on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §7.2, §13, §16): the
 * licence, the address, updates, the scheduler, backups and messaging. Shown to whoever may manage
 * the settings, never to the team, and never a lockout: each one says selling is not affected.
 * One list for the web app's layout and the classic navbar, so they never disagree.
 */
final class OwnerNotices
{
    public const NOT_AFFECTED = 'Selling and your records are not affected.';

    public const CONNECTIONS = 'Password reset and receipts need email, SMS or WhatsApp. Set one up in Settings › Connections.';

    /** A backup older than this (seconds) earns the banner. */
    public const BACKUP_MAX_AGE = 2 * 86400;

    /**
     * @return list<array{key:string, text:string}> nothing on the SaaS or for anyone but the owner
     */
    public static function for(?User $user, ?string $host = null): array
    {
        if (! Edition::isStandalone() || $user === null || ! Permissions::can($user, 'manage_settings')) {
            return [];
        }
        $notices = [];
        $add = function (string $key, string $text, bool $notAffected = true) use (&$notices): void {
            $notices[] = ['key' => $key, 'text' => $notAffected ? $text.' '.self::NOT_AFFECTED : $text];
        };

        $licence = InstalledLicence::current();
        if ($licence->status() === 'missing') {
            $add('licence', 'No licence is installed on this copy.');
        } elseif (! $licence->isValid()) {
            $add('licence', 'The licence on this copy is damaged or has been changed. Put back the licence file you were sent.');
        } else {
            if ($host !== null && $host !== '' && ! $licence->matchesHost($host)) {
                $add('address', 'This copy is licensed for '.implode(', ', $licence->domains()).', but it is open at '.strtolower($host).'.');
            }
            if ($licence->updatesEnded()) {
                $add('updates', 'Updates for this copy ended on '.$licence->updatesUntil()?->format('j M Y').'. Renew to get newer versions.');
            }
        }

        if (! SchedulerHeartbeat::running()) {
            $add('scheduler', 'The scheduler is not running, so nightly backups and reminders are not happening. Add the every-minute cron line from the guide.');
        }

        $age = self::backups()->newestAge();
        if ($age === null && self::installedFor() > self::BACKUP_MAX_AGE) {
            $add('backup', 'No backup has been made yet. Backups are made every night while the scheduler runs.');
        } elseif ($age !== null && $age > self::BACKUP_MAX_AGE) {
            $add('backup', 'The newest backup is '.intdiv($age, 86400).' days old. Backups are made every night while the scheduler runs.');
        }

        try {
            $connected = InstallSettings::readyChannels() !== [];
        } catch (\Throwable) {
            $connected = true;
        }
        if (! $connected) {
            $add('connections', self::CONNECTIONS, false);
        }

        return $notices;
    }

    /** The kernel's backups, beside the install marker (both apps resolve config edition.installed_path to it). */
    public static function backups(): InstallationBackups
    {
        return new InstallationBackups(new DatabaseBackup, directory: dirname((string) config('edition.installed_path')).'/backups');
    }

    /** Seconds since the installer finished (the marker's age); 0 when unknown. */
    private static function installedFor(): int
    {
        $marker = (string) config('edition.installed_path');
        $time = $marker !== '' && is_file($marker) ? (int) @filemtime($marker) : 0;

        return $time > 0 ? max(0, time() - $time) : 0;
    }
}
