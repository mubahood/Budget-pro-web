<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone releases (docs/STANDALONE_EDITION_PLAN.md §10, §17): one row per version a platform
 * admin stages. queued → building → staged (built, signed and proven by a clean install) →
 * published (offered to buyers) → yanked (withdrawn); or failed. Both editions run every migration;
 * only the SaaS ever writes a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 40)->unique();
            $table->char('commit_kernel', 40)->nullable();
            $table->char('commit_web', 40)->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->text('notes')->nullable();
            $table->json('progress')->nullable(); // {"step": "…", "percent": 0-100}
            $table->text('failure')->nullable();
            $table->string('archive_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->timestamp('staged_at')->nullable();
            $table->timestamp('proved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('built_by')->nullable(); // admin_users.id of whoever staged it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_releases');
    }
};
