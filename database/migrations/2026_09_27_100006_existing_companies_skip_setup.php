<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shops that existed before the wizard are already set up: never send them to /setup (they keep the
 * checklist card), and they keep every module they could use before module enablement existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')->whereNull('onboarding_state')->update([
            'onboarding_state' => json_encode(['step' => 'done', 'completed_steps' => ['account', 'business'], 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(), 'legacy' => true]),
        ]);
        DB::table('companies')->whereNull('enabled_modules')->update(['enabled_modules' => json_encode(['shop', 'finance', 'budget', 'poultry'])]);
    }

    public function down(): void
    {
        // Data-only; nothing to undo.
    }
};
