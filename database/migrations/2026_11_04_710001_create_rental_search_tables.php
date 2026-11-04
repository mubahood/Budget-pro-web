<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate Ledger's search (App\Services\Rentals\Search\SearchIndex): one row per record in rental_search_entries, and what finds it
 * in rental_search_keys, each key on an index of (account, term), so a search reads this account's keys only, never a
 * `LIKE '%…%'` over the live tables, however many other accounts the server holds. A copy for finding: it holds no balance and
 * never an ID number in plain text (a keyed hash, compared exactly). rental_search_marks is each person's recent and pinned
 * records. `rentals:search-index` fills all of it for accounts that already exist.
 *
 * Additive: new tables, two new indexes on existing tables (InnoDB adds them online, no table lock). Nothing is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_search_entries')) {
            Schema::create('rental_search_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('kind', 12); // tenant | unit | lease | payment | bill | landlord | property | payout | expense | ticket
                $t->unsignedBigInteger('record_id');
                $t->unsignedBigInteger('property_id')->nullable();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->string('title', 190);
                $t->string('subtitle', 255)->nullable();
                $t->string('status', 24)->nullable();
                $t->text('body'); // the words of what finds it, lower case
                $t->unsignedBigInteger('amount_minor')->nullable();
                $t->date('on_date')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['kind', 'record_id'], 'rental_search_entries_record_unique');
                $t->index(['company_id', 'kind', 'id'], 'rental_search_entries_kind_idx');
                $t->index(['company_id', 'amount_minor', 'on_date'], 'rental_search_entries_amount_idx');
                $t->index(['company_id', 'on_date'], 'rental_search_entries_date_idx');
                $t->index(['company_id', 'kind', 'tenant_id'], 'rental_search_entries_tenant_idx');
            });
        }

        if (! Schema::hasTable('rental_search_keys')) {
            Schema::create('rental_search_keys', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('entry_id');
                $t->unsignedBigInteger('company_id');
                // A prefix says what the key is. w: a word; b: a word of a bill; c: a code; n: a number without its letters and
                // zeros; p: phone digits; r: phone digits reversed; q: next of kin's phone; s: how a name sounds; k: its consonants;
                // i: a keyed hash of an ID number. Plain ASCII, so the index stays small and compares by byte.
                $t->string('term', 64)->charset('ascii')->collation('ascii_bin');
                $t->index(['company_id', 'term', 'entry_id'], 'rental_search_keys_term_idx');
                $t->index(['entry_id', 'term'], 'rental_search_keys_entry_idx');
            });
        }

        if (! Schema::hasTable('rental_search_marks')) {
            Schema::create('rental_search_marks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('user_id');
                $t->string('kind', 12);
                $t->unsignedBigInteger('record_id');
                $t->boolean('pinned')->default(false);
                $t->timestamp('opened_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'kind', 'record_id'], 'rental_search_marks_record_unique');
                $t->index(['company_id', 'user_id', 'opened_at'], 'rental_search_marks_recent_idx');
            });
        }

        // The list screens' own searches: a unit by its code, a receipt by its reference (40 characters are plenty, and fit any row format).
        if (Schema::hasTable('rental_units') && ! $this->hasIndex('rental_units', 'rental_units_code_idx')) {
            Schema::table('rental_units', fn (Blueprint $t) => $t->index(['company_id', 'code'], 'rental_units_code_idx'));
        }
        if (Schema::hasTable('rental_payments') && ! $this->hasIndex('rental_payments', 'rental_payments_reference_idx')) {
            DB::statement('ALTER TABLE rental_payments ADD INDEX rental_payments_reference_idx (company_id, reference(40))');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('rental_payments', 'rental_payments_reference_idx')) {
            Schema::table('rental_payments', fn (Blueprint $t) => $t->dropIndex('rental_payments_reference_idx'));
        }
        if ($this->hasIndex('rental_units', 'rental_units_code_idx')) {
            Schema::table('rental_units', fn (Blueprint $t) => $t->dropIndex('rental_units_code_idx'));
        }
        Schema::dropIfExists('rental_search_marks');
        Schema::dropIfExists('rental_search_keys');
        Schema::dropIfExists('rental_search_entries');
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)->where('index_name', $index)->exists();
    }
};
