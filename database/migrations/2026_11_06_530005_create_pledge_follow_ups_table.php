<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-19: chasing with memory. One row each time someone opens a reminder, a thank-you or a call for a pledge, or
 * notes what the person said (App\Services\Budget\PledgeFollowUps). "Opened" is all it means: nobody can know
 * the message was sent. A new table with the standard sync columns, for the phones later (PB-32).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pledge_follow_ups')) {
            return;
        }
        Schema::create('pledge_follow_ups', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('budget_program_id');
            $t->unsignedBigInteger('contribution_record_id');
            $t->string('kind', 20)->default('reminder'); // reminder | thanks | call | note
            $t->string('channel', 20)->nullable(); // whatsapp | sms | call | in_person
            $t->string('outcome', 20)->nullable(); // will_pay | said_no | wrong_number | skipped
            $t->date('promised_on')->nullable();
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
            $t->unsignedBigInteger('server_seq')->default(0);
            $t->unsignedBigInteger('client_created_at')->nullable();
            $t->unsignedBigInteger('client_updated_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_deleted')->default(false);
            $t->string('device_id', 100)->nullable();

            $t->unique(['company_id', 'uuid'], 'pledge_follow_ups_company_uuid_unique');
            $t->index(['company_id', 'server_seq'], 'pledge_follow_ups_company_seq_idx');
            $t->index(['contribution_record_id', 'created_at'], 'pledge_follow_ups_record_idx');
            $t->index(['budget_program_id', 'promised_on'], 'pledge_follow_ups_program_promised_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledge_follow_ups');
    }
};
