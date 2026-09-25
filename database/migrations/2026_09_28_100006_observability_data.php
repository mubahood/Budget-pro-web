<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** P4-6 (plan Part D): grouped error events, backup runs, tenant data exports and deletion requests. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events')) {
            Schema::create('error_events', function (Blueprint $t) {
                $t->id();
                $t->string('fingerprint', 64)->unique();
                $t->string('class', 191);
                $t->string('message', 500);
                $t->string('file', 255)->nullable();
                $t->unsignedInteger('line')->nullable();
                $t->unsignedInteger('count')->default(1);
                $t->json('last_context')->nullable(); // request_id, company_id, device_id, url, user_id
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('resolved_at')->nullable();
            });
        }
        if (! Schema::hasTable('backup_runs')) {
            Schema::create('backup_runs', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 20); // backup | drill
                $t->string('status', 20); // ok | failed
                $t->string('file', 255)->nullable();
                $t->unsignedBigInteger('bytes')->nullable();
                $t->json('details')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('data_requests')) {
            Schema::create('data_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('requested_by_id');
                $t->string('kind', 10); // export | delete
                $t->string('status', 20)->default('pending'); // pending | ready | scheduled | cancelled | done | failed
                $t->string('file', 255)->nullable();
                $t->timestamp('purge_after')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->string('error', 500)->nullable();
                $t->timestamps();
            });
        }
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if ($admin && ! DB::table('admin_menu')->where('uri', 'system-health')->exists()) {
            DB::table('admin_menu')->insert(['parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->max('order') + 1,
                'title' => 'System health', 'icon' => 'fa-heartbeat', 'uri' => 'system-health', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('error_events');
    }
};
