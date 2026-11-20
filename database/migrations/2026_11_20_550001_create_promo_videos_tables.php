<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo videos for every app of the family (App\Models\PromoVideo): the script and the latest render come from the video
 * studio (its worker talks to /api/studio-api), and a platform admin edits the words, queues a render and publishes it.
 * The public sees only the `live_*` copy, so a new render never replaces a published film until it is published itself.
 * promo_video_renders is the queue the studio's worker takes jobs from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_videos', function (Blueprint $table) {
            $table->id();
            $table->string('app', 40)->comment('the app key (budget-pro-new config/apps.php)');
            $table->string('slug', 80);
            $table->string('series', 60)->default('how-it-works');
            $table->string('series_title', 120)->nullable();
            $table->unsignedSmallInteger('part')->nullable();
            $table->unsignedSmallInteger('parts')->nullable()->comment('how many parts the series has');
            $table->string('title', 160);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('local_id', 80)->nullable()->comment("the studio's folder for it");
            $table->json('spec')->nullable()->comment('the whole video.yaml, as last sent by the studio');
            $table->json('script')->comment('scenes and their spoken lines: what admins may edit');
            $table->unsignedInteger('script_rev')->default(1);
            $table->unsignedInteger('rendered_rev')->nullable()->comment('the script_rev the current render was made from');
            $table->string('video_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('sheet_path')->nullable();
            $table->unsignedBigInteger('video_size')->nullable();
            $table->char('video_sha1', 40)->nullable();
            $table->decimal('duration', 7, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('voice', 120)->nullable();
            $table->json('qa')->nullable();
            $table->json('transcript')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->string('live_video_path')->nullable();
            $table->string('live_poster_path')->nullable();
            $table->unsignedBigInteger('live_video_size')->nullable();
            $table->decimal('live_duration', 7, 2)->nullable();
            $table->unsignedSmallInteger('live_width')->nullable();
            $table->unsignedSmallInteger('live_height')->nullable();
            $table->json('live_transcript')->nullable();
            $table->unsignedInteger('live_rev')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['app', 'slug']);
            $table->index(['app', 'published_at']);
        });

        Schema::create('promo_video_renders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promo_video_id')->index();
            $table->string('status', 12)->default('queued')->comment('queued, running, done, failed');
            $table->unsignedInteger('script_rev');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('worker', 120)->nullable();
            $table->text('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_video_renders');
        Schema::dropIfExists('promo_videos');
    }
};
