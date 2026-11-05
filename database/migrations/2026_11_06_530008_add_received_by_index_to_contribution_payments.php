<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OB-06 Received by. The columns are already there: received_by_id (who took the money) and created_by_id (who
 * recorded it). Until now the first always copied the second; old rows keep that value, because the person who
 * recorded the payment is the best answer they have. This only adds the index that totals money by who holds it.
 * Additive and safe to run twice.
 */
return new class extends Migration
{
    private const INDEX = 'contribution_payments_program_received_idx';

    public function up(): void
    {
        if (! $this->hasIndex('contribution_payments', self::INDEX)) {
            Schema::table('contribution_payments', fn (Blueprint $t) => $t->index(['budget_program_id', 'received_by_id'], self::INDEX));
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
