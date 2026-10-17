<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Every use of the master password (App\Support\MasterPassword, roadmap 02 §2): on the classic
| sign-in, the web portal's sign-in and "Sign in as" from the platform's Companies screen. A row is
| written whenever the master password itself was right, whether the use was allowed or refused
| (an address that is not allowed, a platform admin, a switched-off account). A wrong master
| password is just a failed sign-in. Kept for good: it is the audit trail.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_password_uses')) {
            return;
        }
        Schema::create('master_password_uses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('used_by_admin_id')->nullable()->index(); // the platform admin, for "Sign in as"
            $table->unsignedBigInteger('target_user_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('where', 20); // classic | web | sign_in_as
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('allowed')->default(false);
            $table->string('refusal_reason', 40)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_password_uses');
    }
};
