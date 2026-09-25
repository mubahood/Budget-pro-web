<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The first platform administrator for a fresh install, from ADMIN_EMAIL and
 * ADMIN_PASSWORD (never a built-in default password). Skipped when unset.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (! $email || ! $password || strlen((string) $password) < 10) {
            $this->command?->warn('PlatformAdminSeeder: set ADMIN_EMAIL and ADMIN_PASSWORD (10+ characters) to create the first platform admin.');

            return;
        }
        $user = User::withoutGlobalScopes()->where('email', $email)->first() ?? new User();
        $user->forceFill(['email' => $email, 'username' => $email, 'name' => 'Platform Admin', 'first_name' => 'Platform', 'last_name' => 'Admin', 'status' => 'Active', 'password' => Hash::make((string) $password)])->save();
        $role = DB::table('admin_roles')->where('slug', 'admin')->value('id');
        if ($role && ! DB::table('admin_role_users')->where('user_id', $user->id)->where('role_id', $role)->exists()) {
            DB::table('admin_role_users')->insert(['role_id' => $role, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
