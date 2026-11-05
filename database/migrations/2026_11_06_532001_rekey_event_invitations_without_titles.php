<?php

use App\Services\Budget\EventInvitations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guest's name_key now ignores the titles in front of the name (EventInvitations::key), so a card for "Mr. Kato Paul" is the
 * "Kato Paul" of the pledge book. Rows logged before that get the new key. Where another row of the event already holds it, the
 * row stays as it is: the guest list still shows them as one person, and nothing is lost. Changes no name or money.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_invitations')) {
            return;
        }
        foreach (DB::table('event_invitations')->orderBy('id')->get(['id', 'budget_program_id', 'name', 'name_key']) as $row) {
            $key = EventInvitations::key((string) $row->name);
            if ($key === $row->name_key || $key === '') {
                continue;
            }
            $taken = DB::table('event_invitations')->where('budget_program_id', $row->budget_program_id)->where('name_key', $key)->exists();
            if (! $taken) {
                DB::table('event_invitations')->where('id', $row->id)->update(['name_key' => $key]);
            }
        }
    }

    public function down(): void
    {
        // The old keys are not needed: a row keeps its name, and a name's key can be worked out again.
    }
};
