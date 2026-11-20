<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo videos managed without the studio (App\Services\PromoVideos\PromoStudio): a captions file (.vtt) uploaded by a
 * platform admin, the copy the public sees (written at publish: the uploaded file, or one made from the studio's
 * timings), and one film per app marked as featured for that app's home page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_videos', function (Blueprint $table) {
            if (! Schema::hasColumn('promo_videos', 'captions_path')) {
                $table->string('captions_path')->nullable()->after('sheet_path');
            }
            if (! Schema::hasColumn('promo_videos', 'live_captions_path')) {
                $table->string('live_captions_path')->nullable()->after('live_poster_path');
            }
            if (! Schema::hasColumn('promo_videos', 'featured')) {
                $table->boolean('featured')->default(false)->after('sort')->comment("the film on the app's home page");
            }
        });
    }

    public function down(): void
    {
        Schema::table('promo_videos', function (Blueprint $table) {
            $table->dropColumn(['captions_path', 'live_captions_path', 'featured']);
        });
    }
};
