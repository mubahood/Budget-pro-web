<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UnityDesk: the home, the five modules and the organisation tree (unitydesk docs/12-modules-contract.md).
 *
 * - sacco_members: next of kin (M9).
 * - unity_chapters: kind (region | chapter), parent_id (a chapter's region) and a short code (M6).
 * - unity_committees, unity_committee_members: committees (whole group or a chapter) and their titled officials.
 * - unity_member_events: a member's history (joined, approved, transferred, exited...). Never edited.
 * - unity_meetings, unity_attendance, unity_resolutions: meetings and events, who came, what was decided.
 * - unity_drives, unity_pledges, unity_drive_payments: levies and harambee drives, by member (M5).
 * - unity_fund_entries, unity_welfare_requests: the welfare fund book and requests (M4: not the SACCO ledger).
 * - unity_ballots, unity_ballot_questions, unity_ballot_options, unity_votes: elections and polls (secret, M7).
 * - unity_announcements: the noticeboard.
 * - unity_settings: one row per group (verify_page).
 *
 * New tables carry company_id and the sync columns (ADR 0001); they are not in the sync engine (M3: the phone reads them
 * through the API and writes through its outbox). Money in minor units. MySQL 5.7: no JSON defaults. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacco_members', function (Blueprint $t) {
            $add = fn (string $column, callable $make) => Schema::hasColumn('sacco_members', $column) ? null : $make();
            $add('next_of_kin_name', fn () => $t->string('next_of_kin_name', 120)->nullable());
            $add('next_of_kin_phone_e164', fn () => $t->string('next_of_kin_phone_e164', 20)->nullable());
            $add('next_of_kin_relation', fn () => $t->string('next_of_kin_relation', 40)->nullable());
        });

        Schema::table('unity_chapters', function (Blueprint $t) {
            $add = fn (string $column, callable $make) => Schema::hasColumn('unity_chapters', $column) ? null : $make();
            $add('kind', fn () => $t->string('kind', 10)->default('chapter')); // region | chapter
            $add('parent_id', fn () => $t->unsignedBigInteger('parent_id')->nullable()); // a chapter's region
            $add('code', fn () => $t->string('code', 12)->nullable()); // unique per group when set (checked in UnityChapter)
        });
        Schema::table('unity_chapters', function (Blueprint $t) {
            if (! $this->hasIndex('unity_chapters', 'unity_chapters_company_parent_idx')) {
                $t->index(['company_id', 'kind', 'parent_id'], 'unity_chapters_company_parent_idx');
            }
        });

        $this->create('unity_committees', function (Blueprint $t) {
            $t->unsignedBigInteger('chapter_id')->nullable(); // null: the whole group
            $t->string('name', 120);
            $t->unsignedInteger('sort_order')->default(0);
        }, fn (Blueprint $t) => $t->index(['company_id', 'chapter_id'], 'unity_committees_company_chapter_idx'));

        $this->create('unity_committee_members', function (Blueprint $t) {
            $t->unsignedBigInteger('committee_id');
            $t->unsignedBigInteger('member_id');
            $t->string('title', 60);
            $t->unsignedInteger('sort_order')->default(0);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable(); // null: serving
        }, function (Blueprint $t) {
            $t->index(['company_id', 'committee_id', 'ends_on'], 'unity_committee_members_committee_idx');
            $t->index(['company_id', 'member_id'], 'unity_committee_members_member_idx');
        });

        $this->create('unity_member_events', function (Blueprint $t) {
            $t->unsignedBigInteger('member_id');
            $t->string('kind', 12); // joined | approved | rejected | transferred | status | exited | died | reinstated
            $t->string('from_value', 120)->nullable();
            $t->string('to_value', 120)->nullable();
            $t->string('note', 500)->nullable();
            $t->date('on');
            $t->unsignedBigInteger('by_user_id')->nullable();
        }, function (Blueprint $t) {
            $t->index(['company_id', 'member_id', 'on'], 'unity_member_events_member_idx');
            $t->index(['company_id', 'kind', 'on'], 'unity_member_events_kind_idx');
        });

        $this->create('unity_meetings', function (Blueprint $t) {
            $t->string('kind', 8)->default('meeting'); // meeting | event | agm
            $t->string('title', 150);
            $t->unsignedBigInteger('chapter_id')->nullable(); // null: the whole group
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->nullable();
            $t->string('venue', 150)->nullable();
            $t->text('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->unsignedBigInteger('minutes_file_id')->nullable(); // stored_files
            $t->string('status', 10)->default('scheduled'); // scheduled | held | cancelled
            $t->boolean('rsvp_open')->default(false);
            $t->unsignedInteger('capacity')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, function (Blueprint $t) {
            $t->index(['company_id', 'starts_at'], 'unity_meetings_company_starts_idx');
            $t->index(['company_id', 'chapter_id', 'status'], 'unity_meetings_company_chapter_idx');
        });

        $this->create('unity_attendance', function (Blueprint $t) {
            $t->unsignedBigInteger('meeting_id');
            $t->unsignedBigInteger('member_id');
            $t->string('rsvp', 5)->nullable(); // yes | no | maybe
            $t->string('ticket_no', 20)->nullable();
            $t->boolean('present')->default(false);
            $t->boolean('apology')->default(false);
            $t->dateTime('checked_in_at')->nullable();
            $t->string('method', 5)->nullable(); // list | qr
        }, function (Blueprint $t) {
            $t->unique(['meeting_id', 'member_id'], 'unity_attendance_meeting_member_unique');
            $t->index(['company_id', 'member_id'], 'unity_attendance_company_member_idx');
        });

        $this->create('unity_resolutions', function (Blueprint $t) {
            $t->unsignedBigInteger('meeting_id');
            $t->string('text', 500);
            $t->unsignedBigInteger('owner_member_id')->nullable();
            $t->date('due_on')->nullable();
            $t->string('status', 5)->default('open'); // open | done
            $t->date('done_on')->nullable();
        }, fn (Blueprint $t) => $t->index(['company_id', 'status', 'due_on'], 'unity_resolutions_company_status_idx'));

        $this->create('unity_drives', function (Blueprint $t) {
            $t->string('kind', 10); // levy | harambee
            $t->string('title', 150);
            $t->string('description', 1000)->nullable();
            $t->bigInteger('target_minor')->nullable();
            $t->bigInteger('per_member_minor')->nullable(); // a levy's amount for each member
            $t->date('due_on')->nullable();
            $t->unsignedBigInteger('chapter_id')->nullable(); // null: the whole group
            $t->string('status', 8)->default('open'); // open | closed
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, fn (Blueprint $t) => $t->index(['company_id', 'status'], 'unity_drives_company_status_idx'));

        $this->create('unity_pledges', function (Blueprint $t) {
            $t->unsignedBigInteger('drive_id');
            $t->unsignedBigInteger('member_id');
            $t->bigInteger('pledged_minor')->default(0);
            $t->bigInteger('paid_minor')->default(0);
            $t->date('due_on')->nullable();
            $t->string('status', 10)->default('open'); // open | paid | waived | cancelled
        }, function (Blueprint $t) {
            $t->unique(['drive_id', 'member_id'], 'unity_pledges_drive_member_unique');
            $t->index(['company_id', 'member_id'], 'unity_pledges_company_member_idx');
            $t->index(['company_id', 'status', 'due_on'], 'unity_pledges_company_status_idx');
        });

        $this->create('unity_drive_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('drive_id');
            $t->unsignedBigInteger('pledge_id')->nullable();
            $t->unsignedBigInteger('member_id');
            $t->bigInteger('amount_minor');
            $t->string('method', 20)->nullable();
            $t->date('paid_on');
            $t->string('reference', 100)->nullable();
            $t->string('receipt_no', 40)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, function (Blueprint $t) {
            $t->index(['company_id', 'drive_id'], 'unity_drive_payments_company_drive_idx');
            $t->index(['company_id', 'member_id'], 'unity_drive_payments_company_member_idx');
        });

        $this->create('unity_fund_entries', function (Blueprint $t) {
            $t->string('direction', 3); // in | out
            $t->string('kind', 12); // contribution | income | payout | expense | adjustment
            $t->bigInteger('amount_minor'); // above zero
            $t->unsignedBigInteger('member_id')->nullable();
            $t->unsignedBigInteger('request_id')->nullable(); // unity_welfare_requests
            $t->date('on');
            $t->string('method', 20)->nullable();
            $t->string('reference', 100)->nullable();
            $t->string('receipt_no', 40)->nullable();
            $t->string('note', 500)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, function (Blueprint $t) {
            $t->index(['company_id', 'on'], 'unity_fund_entries_company_on_idx');
            $t->index(['company_id', 'member_id'], 'unity_fund_entries_company_member_idx');
        });

        $this->create('unity_welfare_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('member_id');
            $t->string('kind', 12); // bereavement | sickness | wedding | birth | other
            $t->string('details', 1000)->nullable();
            $t->bigInteger('requested_minor')->default(0);
            $t->bigInteger('approved_minor')->nullable();
            $t->string('status', 10)->default('pending'); // pending | approved | rejected | paid
            $t->unsignedBigInteger('decided_by_id')->nullable();
            $t->date('decided_on')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->unsignedBigInteger('paid_entry_id')->nullable(); // unity_fund_entries
            $t->unsignedBigInteger('requested_by_id')->nullable();
        }, function (Blueprint $t) {
            $t->index(['company_id', 'status'], 'unity_welfare_requests_company_status_idx');
            $t->index(['company_id', 'member_id'], 'unity_welfare_requests_company_member_idx');
        });

        $this->create('unity_ballots', function (Blueprint $t) {
            $t->string('kind', 8); // election | poll
            $t->string('title', 150);
            $t->string('description', 1000)->nullable();
            $t->unsignedBigInteger('chapter_id')->nullable(); // null: everyone
            $t->dateTime('opens_at')->nullable();
            $t->dateTime('closes_at')->nullable();
            $t->string('status', 6)->default('draft'); // draft | open | closed
            $t->string('eligibility', 8)->default('current'); // paid_up | current
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, fn (Blueprint $t) => $t->index(['company_id', 'status'], 'unity_ballots_company_status_idx'));

        $this->create('unity_ballot_questions', function (Blueprint $t) {
            $t->unsignedBigInteger('ballot_id');
            $t->string('title', 150);
            $t->unsignedInteger('sort_order')->default(0);
        }, fn (Blueprint $t) => $t->index(['company_id', 'ballot_id'], 'unity_ballot_questions_ballot_idx'));

        $this->create('unity_ballot_options', function (Blueprint $t) {
            $t->unsignedBigInteger('question_id');
            $t->string('label', 150);
            $t->unsignedBigInteger('member_id')->nullable(); // a candidate
            $t->unsignedInteger('sort_order')->default(0);
        }, fn (Blueprint $t) => $t->index(['company_id', 'question_id'], 'unity_ballot_options_question_idx'));

        $this->create('unity_votes', function (Blueprint $t) {
            $t->unsignedBigInteger('ballot_id');
            $t->unsignedBigInteger('question_id');
            $t->unsignedBigInteger('option_id');
            $t->unsignedBigInteger('member_id');
            $t->dateTime('cast_at');
        }, function (Blueprint $t) {
            $t->unique(['question_id', 'member_id'], 'unity_votes_question_member_unique');
            $t->index(['company_id', 'ballot_id'], 'unity_votes_company_ballot_idx');
        });

        $this->create('unity_announcements', function (Blueprint $t) {
            $t->string('title', 150);
            $t->text('body');
            $t->string('audience', 10)->default('all'); // all | chapter | category | region
            $t->unsignedBigInteger('audience_id')->nullable();
            $t->boolean('pinned')->default(false);
            $t->dateTime('published_at')->nullable();
            $t->unsignedInteger('notices_sent')->default(0);
            $t->unsignedInteger('sms_sent')->default(0);
            $t->unsignedBigInteger('created_by_id')->nullable();
        }, fn (Blueprint $t) => $t->index(['company_id', 'pinned', 'published_at'], 'unity_announcements_company_board_idx'));

        if (! Schema::hasTable('unity_settings')) {
            Schema::create('unity_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->boolean('verify_page')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['unity_settings', 'unity_announcements', 'unity_votes', 'unity_ballot_options', 'unity_ballot_questions', 'unity_ballots',
            'unity_welfare_requests', 'unity_fund_entries', 'unity_drive_payments', 'unity_pledges', 'unity_drives', 'unity_resolutions',
            'unity_attendance', 'unity_meetings', 'unity_member_events', 'unity_committee_members', 'unity_committees'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('unity_chapters', function (Blueprint $t) {
            if ($this->hasIndex('unity_chapters', 'unity_chapters_company_parent_idx')) {
                $t->dropIndex('unity_chapters_company_parent_idx');
            }
        });
        foreach (['unity_chapters' => ['kind', 'parent_id', 'code'], 'sacco_members' => ['next_of_kin_name', 'next_of_kin_phone_e164', 'next_of_kin_relation']] as $table => $columns) {
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($columns));
            }
        }
    }

    /** A new table: id, company_id, the given columns, created_by_id/timestamps where useful, the sync columns and their indexes. */
    private function create(string $table, callable $columns, ?callable $indexes = null): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        Schema::create($table, function (Blueprint $t) use ($table, $columns, $indexes) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $columns($t);
            $t->timestamps();
            $this->syncColumns($t);
            $t->unique(['company_id', 'uuid'], $table.'_company_uuid_unique');
            $t->index(['company_id', 'server_seq'], $table.'_company_seq_idx');
            if ($indexes !== null) {
                $indexes($t);
            }
        });
    }

    /** The sync columns (ADR 0001). */
    private function syncColumns(Blueprint $t): void
    {
        $t->string('uuid', 36);
        $t->unsignedBigInteger('server_seq')->default(0);
        $t->unsignedInteger('version')->default(1);
        $t->boolean('is_deleted')->default(false);
        $t->unsignedBigInteger('client_created_at')->nullable();
        $t->unsignedBigInteger('client_updated_at')->nullable();
        $t->string('device_id', 64)->nullable();
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $name)->exists();
    }
};
