<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ping Pin's multi-member organisation model (PLAN.md §2 / DECISIONS.md D1).
 * `companies` stays the tenant; this is the missing membership pivot that
 * lets more than one person belong to one, with a role. Purely additive —
 * every existing company gets exactly one backfilled 'owner' row below, so
 * nothing that already relies on `companies.owner_id` breaks.
 *
 * Column types are matched deliberately, not copy-pasted: admin_users.id is
 * `int unsigned` and companies.id is `bigint unsigned` (confirmed via
 * SHOW COLUMNS before writing this) — the audit flagged the existing schema
 * for exactly this kind of FK-blocking mismatch, so this table gets real,
 * type-correct foreign keys instead of bare unconstrained integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('user_id');
            $table->enum('role', ['owner', 'admin', 'member'])->default('member');
            $table->unsignedInteger('invited_by_id')->nullable();
            $table->string('invited_email')->nullable();
            $table->string('invited_phone')->nullable();
            $table->enum('status', ['active', 'invited', 'revoked'])->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('admin_users')->cascadeOnDelete();
            $table->foreign('invited_by_id')->references('id')->on('admin_users')->nullOnDelete();
        });

        // Backfill: one 'active' owner row per existing company. Skips (does
        // not fail on) any company whose owner_id doesn't resolve to a real
        // admin_users row — confirmed 0 such rows locally, but production
        // data isn't assumed to match, and a migration must not crash a
        // deploy over a handful of already-broken legacy rows.
        $companies = DB::table('companies')->select('id', 'owner_id', 'created_at')->get();
        $now = now();

        foreach ($companies as $company) {
            if (empty($company->owner_id)) {
                continue;
            }
            $ownerExists = DB::table('admin_users')->where('id', $company->owner_id)->exists();
            if (! $ownerExists) {
                continue;
            }

            DB::table('company_members')->insert([
                'company_id' => $company->id,
                'user_id' => $company->owner_id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => $company->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_members');
    }
};
