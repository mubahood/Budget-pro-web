<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PB-02: "has this mobile money or bank reference been recorded on this event already?" answered by an index.
 * Additive and safe to run twice.
 */
return new class extends Migration
{
    private const INDEX = 'contribution_payments_program_reference_idx';

    public function up(): void
    {
        if (! $this->hasIndex('contribution_payments', self::INDEX)) {
            DB::statement('ALTER TABLE contribution_payments ADD INDEX '.self::INDEX.' (budget_program_id, reference(40))');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('contribution_payments', self::INDEX)) {
            Schema::table('contribution_payments', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i) => $i['name'] === $index);
    }
};
