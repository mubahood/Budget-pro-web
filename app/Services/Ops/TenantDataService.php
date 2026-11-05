<?php

namespace App\Services\Ops;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalLogin;
use App\Models\User;
use App\Services\Auth\GoogleSignIn;
use App\Services\Notifications\Notifier;
use App\Services\Onboarding\SiblingAccounts;
use App\Services\Rentals\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * The shop owns its data (plan Part D): export everything as CSV files in a
 * zip, or delete the shop after a 30-day grace period that can be cancelled.
 */
class TenantDataService
{
    /** Tables that hold secrets or plumbing, never exported. */
    private const NOT_EXPORTED = ['otp_codes', 'message_log', 'api_idempotency_keys', 'web_link_tokens', 'scheduled_notices', 'sync_batches', 'number_sequences', 'personal_access_tokens', 'data_requests', 'error_events', 'jobs', 'failed_jobs', 'sync_sequence', 'rental_search_entries', 'rental_search_keys', 'rental_search_marks'];

    /** Tables with no company_id that still belong to a login. */
    private const KEPT_BY_USER = ['admin_role_users', 'admin_user_permissions', 'notification_preferences', 'auth_one_time_tokens', 'admin_operation_log', 'chat_participants'];

    private const SECRET_COLUMNS = ['password', 'remember_token', 'token_hash', 'code_hash', 'pin_hash', 'pos_pin_hash', 'password_pending_at', 'sessions_valid_from'];

    /** @return array<int, string> */
    public static function tenantTables(): array
    {
        return collect(DB::select("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'company_id'"))
            ->pluck('t')->unique()->reject(fn ($t) => in_array($t, self::NOT_EXPORTED, true))->sort()->values()->all();
    }

    public function export(int $companyId, int $userId): int
    {
        $id = (int) DB::table('data_requests')->insertGetId(['company_id' => $companyId, 'requested_by_id' => $userId, 'kind' => 'export', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $dir = rtrim((string) config('backup.exports_path'), '/');
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $file = "{$dir}/company-{$companyId}-".now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.zip';
            $zip = new ZipArchive();
            $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $manifest = ['company_id' => $companyId, 'exported_at' => now()->toIso8601String(), 'tables' => []];
            foreach (array_merge(self::tenantTables(), ['admin_users', 'companies']) as $table) {
                $q = $table === 'companies' ? DB::table($table)->where('id', $companyId) : DB::table($table)->where('company_id', $companyId);
                $csv = fopen('php://temp', 'w+');
                $n = 0;
                $header = null;
                foreach ($q->cursor() as $row) {
                    $row = array_diff_key((array) $row, array_flip(self::SECRET_COLUMNS));
                    if ($header === null) {
                        $header = array_keys($row);
                        fputcsv($csv, $header);
                    }
                    fputcsv($csv, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), array_values($row)));
                    $n++;
                }
                if ($n > 0) {
                    rewind($csv);
                    $zip->addFromString("{$table}.csv", (string) stream_get_contents($csv));
                    $manifest['tables'][$table] = $n;
                }
                fclose($csv);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->close();
            DB::table('data_requests')->where('id', $id)->update(['status' => 'ready', 'file' => basename($file), 'completed_at' => now(), 'updated_at' => now()]);
            app(Notifier::class)->notify($companyId, 'team', 'Your data export is ready', 'Download it from Your data within 7 days.', [], [User::withoutGlobalScopes()->find($userId)]);
        } catch (\Throwable $e) {
            DB::table('data_requests')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            throw $e;
        }

        return $id;
    }

    public function exportPath(object $request): ?string
    {
        $path = rtrim((string) config('backup.exports_path'), '/').'/'.basename((string) $request->file);

        return $request->status === 'ready' && $request->file && is_file($path) && \Illuminate\Support\Carbon::parse($request->completed_at)->diffInDays(now()) <= 7 ? $path : null;
    }

    public function requestDeletion(Company $company, User $owner, string $password): int
    {
        $this->assertMayDelete($company, $owner);
        if (! Hash::check($password, $owner->password)) {
            throw BusinessRuleException::make('wrong_password', 'Your password is not correct.');
        }

        return $this->scheduleDeletion($company, $owner);
    }

    /** The same request from someone who proved who they are with Google: if they signed up that way, they have no password to type. */
    public function requestDeletionWithGoogle(Company $company, User $owner, string $idToken): int
    {
        $this->assertMayDelete($company, $owner);
        $proved = app(GoogleSignIn::class)->verify($idToken)['email'];
        $theirs = strtolower(trim((string) SiblingAccounts::signInOf($owner)->email));
        if ($theirs === '' || ! hash_equals($theirs, $proved)) {
            throw BusinessRuleException::make('wrong_google', 'That Google account is not the one on this account.');
        }

        return $this->scheduleDeletion($company, $owner);
    }

    private function assertMayDelete(Company $company, User $owner): void
    {
        \App\Services\Onboarding\PublicDemo::guard($company, 'deleting the account');
        if ((int) $company->owner_id !== (int) $owner->id) {
            throw BusinessRuleException::make('owner_only', 'Only the owner can delete this account.');
        }
    }

    private function scheduleDeletion(Company $company, User $owner): int
    {
        $open = DB::table('data_requests')->where('company_id', $company->id)->where('kind', 'delete')->where('status', 'scheduled')->value('id');
        if ($open) {
            return (int) $open;
        }
        $days = (int) config('backup.deletion_grace_days', 30);
        $id = (int) DB::table('data_requests')->insertGetId(['company_id' => $company->id, 'requested_by_id' => $owner->id, 'kind' => 'delete', 'status' => 'scheduled',
            'purge_after' => now()->addDays($days), 'created_at' => now(), 'updated_at' => now()]);
        $when = now()->addDays($days)->toFormattedDateString();
        app(Notifier::class)->notify((int) $company->id, 'billing', 'Deletion scheduled', \App\Support\Edition::isStandalone()
            ? "You asked to close and wipe this installation. Nothing is deleted automatically: from {$when} it can be wiped by hand. Cancel any time in Your data."
            : "{$company->name} and all its data will be deleted on {$when}. Cancel any time before then in Your data.");

        return $id;
    }

    public function cancelDeletion(int $companyId): bool
    {
        return DB::table('data_requests')->where('company_id', $companyId)->where('kind', 'delete')->where('status', 'scheduled')
            ->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
    }

    /**
     * Daily: delete shops whose grace period is over. Never on a standalone copy
     * (docs/STANDALONE_EDITION_PLAN.md §5.4): its request is kept, but closing and wiping the
     * installation is done by hand, never by a scheduled job.
     */
    public function purgeDue(): int
    {
        if (\App\Support\Edition::isStandalone()) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('data_requests')->where('kind', 'delete')->where('status', 'scheduled')->where('purge_after', '<=', now())->get() as $r) {
            try {
                $this->purge((int) $r->company_id);
                DB::table('data_requests')->where('id', $r->id)->update(['status' => 'done', 'completed_at' => now(), 'updated_at' => now()]);
                $n++;
            } catch (\Throwable $e) {
                DB::table('data_requests')->where('id', $r->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            }
        }

        return $n;
    }

    /** Remove every row of the company (all of it goes, so foreign-key order does not matter inside this session). */
    public function purge(int $companyId): void
    {
        $users = DB::table('admin_users')->where('company_id', $companyId)->pluck('id')->all();
        $people = DB::table('rental_logins')->where('company_id', $companyId)->pluck('id')->all();
        $images = $this->ownImages($companyId, $users);
        $tables = collect(DB::select("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'company_id'"))->pluck('t')->unique()
            ->reject(fn ($t) => $t === 'data_requests')->all();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($companyId, $users, $tables, $people) {
                // Before the logins go: the table loop below takes them too.
                $heirs = SiblingAccounts::leaving($users);
                $who = DB::table('admin_users')->whereIn('id', $users)->get(['email', 'phone_e164']);
                $emails = $who->pluck('email')->filter()->all();
                DB::table('otp_codes')->whereIn('identifier', [...$emails, ...$who->pluck('phone_e164')->filter()->all()])->delete();
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                $records = DB::table('stock_records')->where('company_id', $companyId)->pluck('id');
                foreach ($records->chunk(1000) as $chunk) {
                    DB::table('stock_record_batches')->whereIn('stock_record_id', $chunk->all())->delete();
                }
                foreach ($tables as $t) {
                    DB::table($t)->where('company_id', $companyId)->delete();
                }
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', RentalLogin::class)->whereIn('tokenable_id', $people)->delete();
                foreach (self::KEPT_BY_USER as $table) {
                    DB::table($table)->whereIn('user_id', $users)->delete();
                }
                DB::table('admin_users')->whereIn('id', $users)->delete();
                DB::table('companies')->where('id', $companyId)->delete();
                SiblingAccounts::takeOver($heirs);
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        foreach (glob(rtrim((string) config('backup.exports_path'), '/')."/company-{$companyId}-*.zip") ?: [] as $f) {
            @unlink($f);
        }
        // The rows are gone for good by now: a file that won't go is reported, never a reason to call the deletion failed.
        try {
            PrivateFiles::deleteAccount($companyId);
            Storage::disk('public')->deleteDirectory("files/{$companyId}");
            foreach ($images as $image) {
                @unlink(public_path('storage/'.$image));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The logo and the people's pictures of an account, as far as they are its own: an uploaded file, not used by anybody else.
     *
     * @param  array<int, int|string>  $users
     * @return list<string>
     */
    private function ownImages(int $companyId, array $users): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['logo', 'logo_wide']);
        $paths = array_merge([$company->logo ?? null, $company->logo_wide ?? null], DB::table('admin_users')->whereIn('id', $users)->pluck('avatar')->all());
        $own = [];
        foreach (array_unique(array_filter($paths, fn ($p) => is_string($p) && preg_match('~^images/[\w.-]+$~', $p))) as $path) {
            $shared = DB::table('companies')->where('id', '<>', $companyId)->where(fn ($q) => $q->where('logo', $path)->orWhere('logo_wide', $path))->exists()
                || DB::table('admin_users')->whereNotIn('id', $users)->where('avatar', $path)->exists();
            if (! $shared) {
                $own[] = $path;
            }
        }

        return $own;
    }
}
