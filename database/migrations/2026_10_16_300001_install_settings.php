<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Settings › Connections on a standalone copy (docs/STANDALONE_EDITION_PLAN.md §13): the buyer's own
| SMTP, Africa's Talking, WhatsApp and Flutterwave keys. One row per key; the value is encrypted
| with the shared data key (App\Support\DataCrypt) so both apps can read it. Empty on the SaaS.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('install_settings')) {
            return;
        }
        Schema::create('install_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_settings');
    }
};
