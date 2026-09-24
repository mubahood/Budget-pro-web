<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Uploaded files referenced by uuid from synced rows (plan A.6, P1-10). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('files')) {
            return;
        }
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->char('uuid', 36);
            $table->string('purpose', 40);
            $table->string('path', 255);
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->char('device_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'uuid'], 'files_company_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
