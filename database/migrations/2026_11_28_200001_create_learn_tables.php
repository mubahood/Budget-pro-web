<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learn: training videos that teach people how to use each app (docs/LEARN_VIDEOS.md).
 * One set of tables for every app: a lesson names the apps it is for (learn_lesson_apps) or is for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learn_sections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 120);
            $table->string('description', 300)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('learn_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->nullable()->unique()->comment('stable name for learn:import; null for lessons made in the admin');
            $table->unsignedBigInteger('section_id')->nullable()->index();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            $table->boolean('all_apps')->default(false)->comment('true: every app shows it; false: only the apps in learn_lesson_apps');
            $table->json('areas')->nullable()->comment('screen keys for "How this works" links, e.g. ["contributions"]');
            $table->string('audience', 12)->default('everyone')->comment('public, everyone, officers, members');
            $table->json('roles')->nullable()->comment('optional: only these role keys');
            $table->json('permissions')->nullable()->comment('optional: only people holding one of these permission keys');
            $table->string('language', 8)->default('en');
            $table->string('source_type', 10)->default('youtube')->comment('youtube, file, url');
            $table->string('youtube_id', 20)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['published', 'all_apps']);
        });

        Schema::create('learn_lesson_apps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lesson_id');
            $table->string('app', 40);
            $table->unique(['lesson_id', 'app']);
            $table->index('app');
        });

        // One row per person per lesson (not one per sitting): small, and all the admin's numbers come from it.
        Schema::create('learn_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('app', 40)->nullable();
            $table->unsignedInteger('position_seconds')->default(0)->comment('where the person stopped: resume here');
            $table->unsignedInteger('furthest_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0)->comment('time actually played, capped per report');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('views')->default(0)->comment('sittings started');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'user_id']);
            $table->index('user_id');
        });

        // When a person last opened Learn in an app: lessons published after it are "new".
        Schema::create('learn_seen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('app', 40);
            $table->timestamp('seen_at');
            $table->unique(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learn_seen');
        Schema::dropIfExists('learn_progress');
        Schema::dropIfExists('learn_lesson_apps');
        Schema::dropIfExists('learn_lessons');
        Schema::dropIfExists('learn_sections');
    }
};
