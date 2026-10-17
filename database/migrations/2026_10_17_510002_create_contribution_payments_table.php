<?php

use App\Support\Sync\SyncSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pledge payment history (roadmap 02 §6): one append-only row per payment received against a pledge.
 * A mistake is put right with a reversal (a new negative row with a reason), never an edit, and
 * contribution_records.paid_amount is the sum of these rows (App\Services\Budget\ContributionPayments).
 *
 * Backfill: every pledge that already shows money paid gets one opening row for exactly that amount,
 * so every pledge and event total reads the same after the migration as before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contribution_payments')) {
            Schema::create('contribution_payments', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36);
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('budget_program_id');
                $table->unsignedBigInteger('contribution_record_id');
                $table->decimal('amount', 20, 2); // negative for a reversal or a lowered figure
                $table->date('paid_on');
                $table->string('method', 30)->default('cash');
                $table->unsignedBigInteger('received_by_id')->nullable(); // admin_users.id
                $table->string('receipt_no', 40)->nullable();
                $table->string('reference', 100)->nullable(); // mobile money or bank reference
                $table->string('notes', 255)->nullable();
                $table->string('source', 20)->default('payment'); // payment | reversal | opening | pledge_edit
                $table->unsignedBigInteger('reverses_id')->nullable(); // the payment a reversal cancels
                $table->string('reason', 255)->nullable(); // why it was reversed
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->timestamps();
                // Sync columns (ADR 0001).
                $table->unsignedBigInteger('server_seq')->default(0);
                $table->unsignedBigInteger('client_created_at')->nullable();
                $table->unsignedBigInteger('client_updated_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_deleted')->default(false);
                $table->string('device_id', 100)->nullable();

                $table->unique(['company_id', 'uuid'], 'contribution_payments_company_uuid_unique');
                $table->unique('reverses_id', 'contribution_payments_reverses_unique'); // a payment is reversed once
                $table->index(['company_id', 'server_seq'], 'contribution_payments_company_seq_idx');
                $table->index(['contribution_record_id', 'is_deleted'], 'contribution_payments_record_idx');
                $table->index(['budget_program_id', 'paid_on'], 'contribution_payments_program_idx');
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();
        DB::table('contribution_records')
            ->where('paid_amount', '>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('contribution_payments')->whereColumn('contribution_payments.contribution_record_id', 'contribution_records.id'))
            ->orderBy('id')
            ->select(['id', 'company_id', 'budget_program_id', 'paid_amount', 'treasurer_id', 'created_at', 'updated_at'])
            ->chunkById(500, function ($records) use ($now) {
                $rows = [];
                foreach ($records as $r) {
                    if (! $r->company_id) {
                        continue;
                    }
                    $at = $r->updated_at ?? $r->created_at ?? $now;
                    $ms = SyncSequence::nowMs();
                    $rows[] = [
                        'uuid' => (string) Str::uuid(), 'company_id' => $r->company_id, 'budget_program_id' => (int) $r->budget_program_id,
                        'contribution_record_id' => $r->id, 'amount' => (string) $r->paid_amount, 'paid_on' => substr((string) $at, 0, 10),
                        'method' => 'other', 'received_by_id' => $r->treasurer_id, 'source' => 'opening',
                        'notes' => 'Paid before payment history was kept', 'created_at' => $now, 'updated_at' => $now,
                        'server_seq' => SyncSequence::next(), 'client_created_at' => $ms, 'client_updated_at' => $ms, 'version' => 1, 'is_deleted' => 0,
                    ];
                }
                if ($rows !== []) {
                    DB::table('contribution_payments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_payments');
    }
};
