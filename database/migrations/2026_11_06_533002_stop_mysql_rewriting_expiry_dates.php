<?php

use App\Services\Team\TeamService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Why every invite read "Expired" as soon as it was sent. MySQL gives the first plain TIMESTAMP column of a table ON UPDATE
 * CURRENT_TIMESTAMP, so any change to the row replaced the expiry with the database's clock, which is hours behind the app's on the
 * host. Sending an invite counts it as sent, and that one change expired it. One-time codes had the same fault: one wrong digit
 * killed the code. These columns become plain DATETIMEs, and hold what the app writes.
 *
 * Open invites get their 7 days back. Their tokens were replaced in the migration before this one, so nobody holds their links yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `invites` MODIFY `expires_at` DATETIME NOT NULL');
            DB::statement('ALTER TABLE `otp_codes` MODIFY `expires_at` DATETIME NOT NULL');
        }
        DB::table('invites')->where('status', 'pending')->update(['expires_at' => now()->addDays(TeamService::INVITE_DAYS), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Back to the fault would help no one: a DATETIME reads the same to the app.
    }
};
