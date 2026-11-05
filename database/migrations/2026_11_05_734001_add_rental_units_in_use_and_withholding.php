<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A room the landlord or caretaker uses (LC-21): who uses it. The percent an agency holds back for a landlord (LC-23), the account's switch for it, and
 * which month a recorded amount was for. Additive and safe on the live database: every column is empty or off until someone uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_units', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_units', 'in_use_by')) {
                $t->string('in_use_by', 100)->nullable();
            }
        });
        Schema::table('rental_landlords', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_landlords', 'withholding_bp')) {
                $t->unsignedInteger('withholding_bp')->nullable();
            }
        });
        Schema::table('rental_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_settings', 'withholding_on')) {
                $t->boolean('withholding_on')->default(false);
            }
        });
        Schema::table('rental_expenses', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_expenses', 'for_month')) {
                $t->string('for_month', 7)->nullable();
            }
        });
        // SchemaIntegrity leaves a CHECK that already exists alone, so where the database enforces one the old list would refuse `in_use` and `paid_landlord`. It goes, and run() adds the wider one.
        if (SchemaIntegrity::checksSupported()) {
            $maria = str_contains(strtolower((string) (DB::selectOne('select version() as v')->v ?? '')), 'mariadb');
            foreach (['rental_units' => 'chk_rental_units_status', 'rental_payments' => 'chk_rental_payments_method'] as $table => $name) {
                $has = DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('constraint_name', $name)->exists();
                if ($has) {
                    DB::statement('ALTER TABLE `'.$table.'` DROP '.($maria ? 'CONSTRAINT' : 'CHECK').' `'.$name.'`');
                }
            }
        }
        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        foreach (['rental_units' => 'in_use_by', 'rental_landlords' => 'withholding_bp', 'rental_settings' => 'withholding_on', 'rental_expenses' => 'for_month'] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
