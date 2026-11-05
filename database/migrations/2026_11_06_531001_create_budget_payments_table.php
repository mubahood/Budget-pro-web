<?php

use App\Support\Sync\SyncSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The spending ledger (the review's PB-13, decision D6): one append-only row per payment out of an event's money. A mistake is put
 * right with a reversal (a new negative row with a reason), never an edit, and budget_items.invested_amount is the sum of these rows
 * (App\Services\Budget\BudgetPayments).
 *
 * Backfill: every line that shows money paid out gets one opening row for exactly that amount, a removed line too, so every line, category
 * and event total reads the same after the migration as before it. Safe to run again: a line with any row is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budget_payments')) {
            Schema::create('budget_payments', function (Blueprint $t) {
                $t->id();
                $t->string('uuid', 36);
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('budget_program_id');
                $t->unsignedBigInteger('budget_item_id');
                $t->decimal('amount', 20, 2); // negative for a reversal or a lowered figure
                $t->date('paid_on');
                $t->string('paid_to', 120)->nullable(); // the vendor
                $t->string('method', 30)->default('cash');
                $t->unsignedBigInteger('paid_by_id')->nullable(); // admin_users.id: who handed the money over
                $t->unsignedBigInteger('approved_by_id')->nullable(); // admin_users.id: who agreed it
                $t->string('reference', 100)->nullable();
                $t->string('notes', 255)->nullable();
                $t->string('source', 20)->default('payment'); // payment | reversal | opening | item_edit | moved
                $t->unsignedBigInteger('reverses_id')->nullable();
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unsignedBigInteger('server_seq')->default(0);
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->string('device_id', 100)->nullable();

                $t->unique(['company_id', 'uuid'], 'budget_payments_company_uuid_unique');
                $t->unique('reverses_id', 'budget_payments_reverses_unique'); // a payment is reversed once
                $t->index(['company_id', 'server_seq'], 'budget_payments_company_seq_idx');
                $t->index(['budget_item_id', 'is_deleted'], 'budget_payments_item_idx');
                $t->index(['budget_program_id', 'paid_on'], 'budget_payments_program_idx');
            });
        }

        $this->backfill();
    }

    public function backfill(): void
    {
        $now = now();
        DB::table('budget_items')
            ->where('invested_amount', '!=', 0)
            ->whereNotNull('company_id')->whereNotNull('budget_program_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('budget_payments')->whereColumn('budget_payments.budget_item_id', 'budget_items.id'))
            ->orderBy('id')
            ->select(['id', 'company_id', 'budget_program_id', 'invested_amount', 'changed_by_id', 'created_by_id', 'created_at', 'updated_at'])
            ->chunkById(500, function ($items) use ($now) {
                $rows = [];
                foreach ($items as $i) {
                    $ms = SyncSequence::nowMs();
                    $rows[] = [
                        'uuid' => (string) Str::uuid(), 'company_id' => $i->company_id, 'budget_program_id' => $i->budget_program_id, 'budget_item_id' => $i->id,
                        'amount' => (string) $i->invested_amount, 'paid_on' => substr((string) ($i->updated_at ?? $i->created_at ?? $now), 0, 10), 'method' => 'other',
                        'paid_by_id' => $i->changed_by_id ?: $i->created_by_id, 'source' => 'opening', 'notes' => 'Paid before payment history was kept',
                        'created_at' => $now, 'updated_at' => $now, 'server_seq' => SyncSequence::next(), 'client_created_at' => $ms, 'client_updated_at' => $ms, 'version' => 1, 'is_deleted' => 0,
                    ];
                }
                if ($rows !== []) {
                    DB::table('budget_payments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_payments');
    }
};
