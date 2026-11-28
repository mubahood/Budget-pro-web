<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My Family Roots 360, round 2 (doc 13): gatherings, memories and dues paid by mobile money.
 *
 * - kin_events: meetings and family gatherings (meeting | gathering | wedding | burial | introduction | other), with an
 *   agenda, minutes, a status (planned | held | cancelled), perhaps one branch only, perhaps a linked one-time
 *   contribution (dues_programme_id).
 * - kin_event_people: each person's answer (rsvp going | maybe | no) and the register (attendance present | apology |
 *   absent); unique (event, member).
 * - kin_memories: stories, photos, voice notes and documents (soft deleted); kin_memory_people tags people;
 *   kin_memory_files names `files` rows (the original, and a small picture for photos: thumb_file_id).
 * - momo_requests: dues_period and sacco_dues_payment_id, for a contribution paid by mobile money (purpose kin_dues).
 *
 * Safe to run again. API only: not in the sync registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kin_events')) {
            Schema::create('kin_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('kind', 16)->default('meeting');
                $t->string('title', 150);
                $t->dateTime('starts_at');
                $t->dateTime('ends_at')->nullable();
                $t->string('venue', 190)->nullable();
                $t->string('venue_url', 500)->nullable();
                $t->text('description')->nullable();
                $t->text('agenda')->nullable();
                $t->mediumText('minutes')->nullable();
                $t->unsignedBigInteger('minutes_by')->nullable(); // users.id
                $t->string('status', 10)->default('planned');
                $t->string('cancel_reason', 255)->nullable();
                $t->unsignedBigInteger('branch_id')->nullable(); // null = the whole family
                $t->unsignedBigInteger('dues_programme_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable(); // users.id
                $t->timestamps();
                $t->index(['company_id', 'starts_at'], 'kin_events_company_starts_idx');
            });
        }

        if (! Schema::hasTable('kin_event_people')) {
            Schema::create('kin_event_people', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('event_id');
                $t->unsignedBigInteger('member_id'); // sacco_members
                $t->string('rsvp', 8)->nullable();
                $t->string('attendance', 8)->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamps();
                $t->unique(['event_id', 'member_id'], 'kin_event_people_event_member_unique');
                $t->index(['company_id', 'member_id'], 'kin_event_people_company_member_idx');
            });
        }

        if (! Schema::hasTable('kin_memories')) {
            Schema::create('kin_memories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('kind', 10)->default('story');
                $t->string('title', 150);
                $t->mediumText('body')->nullable();
                $t->date('happened_on')->nullable();
                $t->unsignedSmallInteger('happened_year')->nullable();
                $t->string('place', 150)->nullable();
                $t->unsignedBigInteger('event_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable(); // users.id
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'created_at'], 'kin_memories_company_created_idx');
            });
        }

        if (! Schema::hasTable('kin_memory_people')) {
            Schema::create('kin_memory_people', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('memory_id');
                $t->unsignedBigInteger('member_id');
                $t->unique(['memory_id', 'member_id'], 'kin_memory_people_memory_member_unique');
                $t->index('member_id', 'kin_memory_people_member_idx');
            });
        }

        if (! Schema::hasTable('kin_memory_files')) {
            Schema::create('kin_memory_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('memory_id');
                $t->unsignedBigInteger('file_id'); // files.id
                $t->unsignedBigInteger('thumb_file_id')->nullable(); // files.id, photos only
                $t->string('mime', 100);
                $t->unsignedBigInteger('bytes')->default(0);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->index('memory_id', 'kin_memory_files_memory_idx');
            });
        }

        Schema::table('momo_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('momo_requests', 'dues_period')) {
                $t->string('dues_period', 10)->nullable();
            }
            if (! Schema::hasColumn('momo_requests', 'sacco_dues_payment_id')) {
                $t->unsignedBigInteger('sacco_dues_payment_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('momo_requests', function (Blueprint $t) {
            foreach (['dues_period', 'sacco_dues_payment_id'] as $c) {
                if (Schema::hasColumn('momo_requests', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        foreach (['kin_memory_files', 'kin_memory_people', 'kin_memories', 'kin_event_people', 'kin_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
