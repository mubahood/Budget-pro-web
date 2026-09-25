<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 foundation (plan C6/C7, P3-1/P3-5): database queue, message log,
 * in-app notifications + preferences, OTP codes, and identity columns on users.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('queue')->index();
                $t->longText('payload');
                $t->unsignedTinyInteger('attempts');
                $t->unsignedInteger('reserved_at')->nullable();
                $t->unsignedInteger('available_at');
                $t->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('message_log')) {
            Schema::create('message_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('channel', 20); // sms | whatsapp | mail | log
                $t->string('to', 191);
                $t->string('purpose', 40)->nullable(); // otp | invite | welcome | daily_summary | ...
                $t->text('body');
                $t->string('status', 20)->default('queued'); // queued | sent | failed | skipped
                $t->string('provider_id', 191)->nullable();
                $t->string('error', 500)->nullable();
                $t->timestamps();
                $t->index(['company_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('user_id')->nullable(); // null = everyone in the company with the right role
                $t->string('type', 40);
                $t->string('title', 191);
                $t->text('body')->nullable();
                $t->json('data')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'user_id', 'read_at']);
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('key', 40); // daily_summary | low_stock | unsynced_device | cash_variance | billing
                $t->json('channels'); // ["whatsapp","in_app"]
                $t->boolean('enabled')->default(true);
                $t->timestamps();
                $t->unique(['user_id', 'key']);
            });
        }

        if (! Schema::hasTable('otp_codes')) {
            Schema::create('otp_codes', function (Blueprint $t) {
                $t->id();
                $t->string('identifier', 191); // E.164 phone or lower-case email
                $t->string('purpose', 20); // login | register | reset | verify
                $t->string('code_hash', 100);
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at');
                $t->timestamp('consumed_at')->nullable();
                $t->string('channel', 20)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
                $t->index(['identifier', 'purpose']);
            });
        }

        Schema::table('admin_users', function (Blueprint $t) {
            if (! Schema::hasColumn('admin_users', 'phone_e164')) {
                $t->string('phone_e164', 20)->nullable()->after('phone_number');
                $t->timestamp('phone_verified_at')->nullable()->after('phone_e164');
            }
            if (! Schema::hasColumn('admin_users', 'email_verified_at')) {
                $t->timestamp('email_verified_at')->nullable();
            }
            if (! Schema::hasColumn('admin_users', 'locale')) {
                $t->string('locale', 8)->default('en');
                $t->timestamp('last_login_at')->nullable();
            }
        });

        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'country')) {
                $t->string('country', 2)->default('UG')->after('currency');
                $t->string('locale', 8)->default('en')->after('country');
                $t->string('business_type', 40)->nullable()->after('locale');
                $t->json('onboarding_state')->nullable();
                $t->json('enabled_modules')->nullable();
            }
        });

        // Backfill E.164 phones where the number is recognisable (unique index only when clean).
        foreach (DB::table('admin_users')->whereNotNull('phone_number')->where('phone_number', '!=', '')->get(['id', 'phone_number', 'company_id']) as $u) {
            $country = DB::table('companies')->where('id', $u->company_id)->value('currency') === 'KES' ? 'KE' : 'UG';
            $e164 = \App\Support\Phone::e164((string) $u->phone_number, $country);
            if ($e164 && ! DB::table('admin_users')->where('phone_e164', $e164)->exists()) {
                DB::table('admin_users')->where('id', $u->id)->update(['phone_e164' => $e164]);
            }
        }
        $exists = DB::selectOne("SELECT COUNT(*) c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND index_name = 'admin_users_phone_e164_unique'");
        if ((int) $exists->c === 0) {
            DB::statement('ALTER TABLE admin_users ADD UNIQUE admin_users_phone_e164_unique (phone_e164)');
        }
        DB::table('companies')->where('currency', 'KES')->update(['country' => 'KE']);
        DB::table('companies')->where('currency', 'TZS')->update(['country' => 'TZ']);
        DB::table('companies')->where('currency', 'RWF')->update(['country' => 'RW']);
    }

    public function down(): void
    {
        foreach (['otp_codes', 'notification_preferences', 'app_notifications', 'message_log'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
