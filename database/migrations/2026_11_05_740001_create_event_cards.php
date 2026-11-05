<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation cards for an event (App\Services\Budget\CardTemplates, EventInvitations): the designs a chair uploads,
 * where each one prints the invitee's name, and the log of who got a card and who was invited.
 *
 * The name's place and size are kept as shares of the card, not pixels, so the same design prints the same at any
 * size: name_y is the text's baseline as a percent of the card's height (it is always centred across), font_size is
 * thousandths of the card's width, letter_spacing thousandths of the font size, max_width a percent of the card's
 * width that a long name shrinks to fit. Sync columns as on every tenant table (ADR 0001), for the phone later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_card_templates')) {
            Schema::create('event_card_templates', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36);
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('budget_program_id');
                $table->string('name', 120);
                $table->string('source_type', 10)->default('image'); // image | pdf
                $table->string('background_path', 191); // the picture shown and printed (CardFiles)
                $table->string('background_mime', 40);
                $table->unsignedInteger('background_w');
                $table->unsignedInteger('background_h');
                $table->string('source_path', 191)->nullable(); // the PDF as uploaded, kept so its PDF stays sharp
                $table->unsignedInteger('source_bytes')->default(0);
                $table->unsignedSmallInteger('pdf_page')->default(1); // which page of the PDF takes the name
                $table->string('font_key', 40);
                $table->unsignedSmallInteger('font_size');
                $table->string('color', 7);
                $table->decimal('name_y', 6, 3);
                $table->string('letter_case', 10)->default('asis'); // asis | upper | title
                $table->smallInteger('letter_spacing')->default(0);
                $table->unsignedTinyInteger('max_width')->default(80);
                $table->boolean('is_default')->default(false);
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->unsignedBigInteger('updated_by_id')->nullable();
                $table->timestamps();
                // Sync columns (ADR 0001).
                $table->unsignedBigInteger('server_seq')->default(0);
                $table->unsignedBigInteger('client_created_at')->nullable();
                $table->unsignedBigInteger('client_updated_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_deleted')->default(false);
                $table->string('device_id', 100)->nullable();

                $table->unique(['company_id', 'uuid'], 'event_card_templates_company_uuid_unique');
                $table->index(['company_id', 'server_seq'], 'event_card_templates_company_seq_idx');
                $table->index(['budget_program_id', 'is_deleted'], 'event_card_templates_program_idx');
            });
        }

        if (! Schema::hasTable('event_invitations')) {
            Schema::create('event_invitations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36);
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('budget_program_id');
                $table->unsignedBigInteger('template_id')->nullable(); // the card last made for them
                $table->string('name', 120);
                $table->string('name_key', 120); // lower case, single spaces: one row per person on an event
                $table->string('source', 20)->default('typed'); // pledge | committee | team | other_event | typed
                $table->string('source_note', 191)->nullable(); // what was known of them at the time
                $table->unsignedBigInteger('contribution_record_id')->nullable();
                $table->string('status', 10)->default('made'); // made (a card, not yet marked) | invited
                $table->dateTime('invited_at')->nullable();
                $table->unsignedBigInteger('invited_by_id')->nullable();
                $table->unsignedInteger('cards_made')->default(0);
                $table->string('last_format', 5)->nullable(); // png | pdf
                $table->dateTime('last_card_at')->nullable();
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->timestamps();
                // Sync columns (ADR 0001).
                $table->unsignedBigInteger('server_seq')->default(0);
                $table->unsignedBigInteger('client_created_at')->nullable();
                $table->unsignedBigInteger('client_updated_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_deleted')->default(false);
                $table->string('device_id', 100)->nullable();

                $table->unique(['company_id', 'uuid'], 'event_invitations_company_uuid_unique');
                $table->unique(['budget_program_id', 'name_key'], 'event_invitations_person_unique');
                $table->index(['company_id', 'server_seq'], 'event_invitations_company_seq_idx');
                $table->index(['budget_program_id', 'status'], 'event_invitations_program_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_invitations');
        Schema::dropIfExists('event_card_templates');
    }
};
