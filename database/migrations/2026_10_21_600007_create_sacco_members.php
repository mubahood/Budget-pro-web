<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.9 (docs/akiba/04-architecture.md §3.1, AK-31, AK-47, AK-61): a SACCO's members, their nominees and
 * next of kin, their documents, and the sign-in a member may have for the web self-service.
 *
 * - sacco_members carries the sync columns (a group registers members offline; ADR 0001, merged not refused:
 *   ADR 0007). The NIN is stored encrypted, with an HMAC hash for the duplicate lookup and its last four
 *   characters for display; neither the NIN nor its hash ever leaves through the API, sync or the audit log.
 * - Relations and documents are replaced (ended), never deleted.
 * - sacco_member_logins: one per membership, apart from the staff logins (admin_users), so a person may be a member
 *   of several SACCOs and also staff of a shop (AK-19, AK-61).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_members')) {
            Schema::create('sacco_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('member_no', 20)->nullable(); // M-000123 (NumberSequencer kind mem); offline rows get one on sync
                $t->string('kind', 12)->default('individual'); // individual | group | institution
                $t->string('first_name', 80);
                $t->string('last_name', 80)->nullable();
                $t->string('other_names', 80)->nullable();
                $t->string('gender', 8)->nullable(); // female | male | other
                $t->date('date_of_birth')->nullable();
                $t->text('nin_encrypted')->nullable(); // DataCrypt
                $t->char('nin_hash', 64)->nullable(); // HMAC-SHA256 with config('sacco.nin_pepper')
                $t->string('nin_last4', 4)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('phone_alt_e164', 20)->nullable();
                $t->string('email', 190)->nullable();
                $t->string('address', 255)->nullable();
                $t->string('district', 80)->nullable();
                $t->string('subcounty', 80)->nullable();
                $t->string('parish', 80)->nullable();
                $t->string('village', 80)->nullable();
                $t->string('occupation', 120)->nullable();
                $t->string('employer_name', 120)->nullable();
                $t->unsignedBigInteger('employer_id')->nullable(); // sacco_employers (P3)
                $t->string('payroll_no', 40)->nullable();
                $t->date('joined_on');
                $t->string('status', 12)->default('applicant'); // applicant | active | dormant | suspended | exited | deceased | rejected
                $t->date('status_changed_on')->nullable();
                $t->string('exit_reason', 255)->nullable();
                $t->string('kyc_status', 10)->default('pending'); // pending | verified | rejected
                $t->unsignedBigInteger('kyc_verified_by_id')->nullable();
                $t->timestamp('kyc_verified_at')->nullable();
                $t->string('kyc_note', 255)->nullable();
                $t->timestamp('consent_data_at')->nullable(); // DPPA s.7/s.9, with the hosting clause (AK-47)
                $t->string('consent_version', 20)->nullable();
                $t->timestamp('consent_messages_at')->nullable();
                $t->timestamp('messages_opt_out_at')->nullable();
                $t->timestamp('consent_credit_bureau_at')->nullable();
                $t->string('guardian_name', 150)->nullable();
                $t->string('guardian_phone_e164', 20)->nullable();
                $t->boolean('is_insider')->default(false);
                $t->unsignedBigInteger('photo_file_id')->nullable();
                $t->unsignedBigInteger('signature_file_id')->nullable();
                $t->unsignedBigInteger('merged_into_id')->nullable(); // ADR 0007 tombstone target
                $t->char('import_key', 64)->nullable(); // a CSV row's fingerprint: importing it again changes nothing
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                // Sync columns (ADR 0001).
                $t->string('uuid', 36);
                $t->unsignedBigInteger('server_seq')->default(0);
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->string('device_id', 64)->nullable();

                $t->unique(['company_id', 'uuid'], 'sacco_members_company_uuid_unique');
                $t->unique(['company_id', 'member_no'], 'sacco_members_company_no_unique');
                $t->index(['company_id', 'server_seq'], 'sacco_members_company_seq_idx');
                $t->index(['company_id', 'status'], 'sacco_members_company_status_idx');
                $t->index(['company_id', 'last_name', 'first_name'], 'sacco_members_company_name_idx');
                $t->index(['company_id', 'nin_hash'], 'sacco_members_company_nin_idx');
                $t->index(['company_id', 'phone_e164'], 'sacco_members_company_phone_idx');
                $t->index(['company_id', 'employer_id', 'payroll_no'], 'sacco_members_company_payroll_idx');
                $t->index(['company_id', 'import_key'], 'sacco_members_company_import_idx');
            });
        }

        if (! Schema::hasTable('sacco_member_relations')) {
            Schema::create('sacco_member_relations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->string('role', 12); // nominee | next_of_kin
                $t->string('name', 150);
                $t->string('relationship', 60)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->text('nin_encrypted')->nullable();
                $t->unsignedInteger('share_bp')->nullable(); // a nominee's portion; a member's nominees total 10000
                $t->string('witness1_name', 150)->nullable();
                $t->string('witness2_name', 150)->nullable();
                $t->date('signed_on')->nullable();
                $t->unsignedBigInteger('document_file_id')->nullable();
                $t->date('ended_on')->nullable(); // replaced, never deleted
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'member_id', 'role'], 'sacco_member_relations_member_idx');
            });
        }

        if (! Schema::hasTable('sacco_member_documents')) {
            Schema::create('sacco_member_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->string('type', 16); // national_id | passport | photo | signature | application | nomination | other
                $t->unsignedBigInteger('file_id'); // files.id (StoredFile, counted by Quotas::assertCanStore at upload)
                $t->unsignedBigInteger('captured_by_id')->nullable();
                $t->unsignedBigInteger('verified_by_id')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamp('removed_at')->nullable(); // consent withdrawn: the file is unlinked, the row stays
                $t->timestamps();

                $t->index(['company_id', 'member_id'], 'sacco_member_documents_member_idx');
            });
        }

        if (! Schema::hasTable('sacco_member_logins')) {
            Schema::create('sacco_member_logins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->string('email', 190)->nullable();
                $t->string('phone_e164', 20)->nullable();
                $t->string('password', 255)->nullable(); // null = codes only
                $t->timestamp('email_verified_at')->nullable();
                $t->timestamp('last_login_at')->nullable();
                $t->unsignedTinyInteger('failed_attempts')->default(0);
                $t->timestamp('locked_until')->nullable();
                $t->timestamp('disabled_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'member_id'], 'sacco_member_logins_member_unique');
                $t->index('email', 'sacco_member_logins_email_idx');
                $t->index('phone_e164', 'sacco_member_logins_phone_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_member_logins');
        Schema::dropIfExists('sacco_member_documents');
        Schema::dropIfExists('sacco_member_relations');
        Schema::dropIfExists('sacco_members');
    }
};
