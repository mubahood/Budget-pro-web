<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OB-06, as the owner's fuller brief has it: a pledge too has who took it, separate from who typed it in
 * (treasurer_id). A new nullable column and nothing rewritten: an old row reads as taken by whoever entered it, and a
 * pledge from an older phone is filled in the same way by the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contribution_records', 'received_by_id')) {
            Schema::table('contribution_records', fn (Blueprint $t) => $t->unsignedBigInteger('received_by_id')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contribution_records', 'received_by_id')) {
            Schema::table('contribution_records', fn (Blueprint $t) => $t->dropColumn('received_by_id'));
        }
    }
};
