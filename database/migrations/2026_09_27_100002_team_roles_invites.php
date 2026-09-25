<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan C5 / P3-4: per-company permission overrides, invites, membership backfill, admin roles per shop role. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_role_permissions')) {
            Schema::create('company_role_permissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('role', 30);
                $t->string('permission', 40);
                $t->boolean('allowed');
                $t->timestamps();
                $t->unique(['company_id', 'role', 'permission'], 'crp_unique');
            });
        }
        if (! Schema::hasTable('invites')) {
            Schema::create('invites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('token_hash', 64)->unique();
                $t->string('name', 150)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('email', 191)->nullable();
                $t->string('role', 30);
                $t->unsignedBigInteger('invited_by_id');
                $t->string('status', 12)->default('pending'); // pending | accepted | revoked
                $t->timestamp('expires_at');
                $t->unsignedBigInteger('accepted_user_id')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->unsignedInteger('sent_count')->default(0);
                $t->timestamps();
                $t->index(['company_id', 'status']);
            });
        }
        // Roles and statuses are now open vocabularies (config/permissions.php), not enums.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE company_members MODIFY role VARCHAR(30) NOT NULL DEFAULT 'viewer'");
            DB::statement("ALTER TABLE company_members MODIFY status VARCHAR(12) NOT NULL DEFAULT 'active'");
        }
        // Ping Pin's 'admin'/'member' rows are left as-is; Permissions maps them (admin→manager, member→viewer).
        Schema::table('company_members', function (Blueprint $t) {
            if (! Schema::hasColumn('company_members', 'deactivated_at')) {
                $t->timestamp('deactivated_at')->nullable();
            }
        });

        // Every existing user gets a membership: owners stay owners; existing staff keep today's
        // full access as managers (nobody loses what they can do on upgrade — DECISIONS E30).
        $now = now();
        foreach (DB::table('admin_users')->whereNotNull('company_id')->get(['id', 'company_id']) as $u) {
            if (! DB::table('companies')->where('id', $u->company_id)->exists()) {
                continue;
            }
            if (DB::table('company_members')->where('company_id', $u->company_id)->where('user_id', $u->id)->exists()) {
                continue;
            }
            $owner = (int) DB::table('companies')->where('id', $u->company_id)->value('owner_id') === (int) $u->id;
            DB::table('company_members')->insert(['company_id' => $u->company_id, 'user_id' => $u->id, 'role' => $owner ? 'owner' : 'manager', 'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }

        if (DB::table('admin_roles')->exists()) {
            AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
        Schema::dropIfExists('company_role_permissions');
    }
};
