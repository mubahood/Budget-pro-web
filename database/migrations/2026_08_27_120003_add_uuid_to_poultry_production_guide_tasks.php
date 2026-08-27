<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Unlike farm types (whose slug already IS the mobile client's uuid),
     * a guide task has no natural stable business key — the mobile client's
     * own ProductionGuideTask.save() falls back to a real generated uuid,
     * so the server needs the same kind of column to sync against.
     */
    public function up(): void
    {
        Schema::table('poultry_production_guide_tasks', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->after('id');
        });

        foreach (DB::table('poultry_production_guide_tasks')->whereNull('uuid')->select('id')->get() as $row) {
            DB::table('poultry_production_guide_tasks')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('poultry_production_guide_tasks', function (Blueprint $t) {
            $t->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('poultry_production_guide_tasks', function (Blueprint $t) {
            $t->dropColumn('uuid');
        });
    }
};
