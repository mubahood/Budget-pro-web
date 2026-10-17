<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Each tap on a "WhatsApp us" link that goes through /go/whatsapp (docs/roadmap/06 §4,
| App\Services\Support\SupportClicks): which message (context), where the link was (app: site,
| admin, classic, mail, api or a phone app flavor), the page path, and the signed-in shop when there
| is one. No IP address, no name, no phone number: it counts taps, it does not follow people.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_clicks')) {
            return;
        }
        Schema::create('support_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('context', 64);
            $table->string('app', 32);
            $table->string('page', 191)->nullable();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['context', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_clicks');
    }
};
