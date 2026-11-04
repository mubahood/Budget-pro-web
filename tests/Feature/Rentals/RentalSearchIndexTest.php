<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalTenant;
use App\Services\Ops\TenantDataService;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Search\SearchIndex;
use App\Support\Rentals\SearchText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Estate Ledger's search index: every write reaches it, after the writer's transaction; `rentals:search-index` fills what exists and
 * repairs what a crash missed, safely on any day; an ID number never lies in it in plain text; a closed account leaves nothing behind.
 */
class RentalSearchIndexTest extends ApiTestCase
{
    use MakesRentals;

    private function terms(string $kind, int $id): array
    {
        $entry = DB::table('rental_search_entries')->where('kind', $kind)->where('record_id', $id)->value('id');

        return $entry === null ? [] : DB::table('rental_search_keys')->where('entry_id', $entry)->pluck('term')->all();
    }

    public function test_the_migration_is_additive_and_keeps_the_new_indexes(): void
    {
        foreach (['rental_search_entries', 'rental_search_keys', 'rental_search_marks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $index = fn (string $table) => collect(DB::select("SHOW INDEX FROM {$table}"))->pluck('Key_name')->all();
        $this->assertContains('rental_units_code_idx', $index('rental_units'));
        $this->assertContains('rental_payments_reference_idx', $index('rental_payments'));
        $this->assertContains('rental_search_keys_term_idx', $index('rental_search_keys'));
    }

    public function test_a_write_is_indexed_when_flushed_and_a_failed_search_write_never_stops_it(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 2, 500000, ['name' => 'Kisaasi Court', 'area' => 'Kisaasi']);
        $lease = $this->tenantIn($owner, $units[0], Carbon::now()->toDateString(), ['tenant' => ['name' => 'Allan Namuddu', 'phone' => '+256700000495', 'alt_phone' => '0781 222 333',
            'next_of_kin_phone' => '0755123456', 'id_type' => 'national_id', 'id_number' => 'CM90012345ABCD', 'employer' => 'Stanbic Bank']]);
        $payment = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'mobile_money', 'reference' => 'MP-26606453']);
        SearchIndex::flush();

        $tenant = $this->terms('tenant', (int) $lease->tenant_id);
        foreach (['w:allan', 'w:namuddu', 'w:stanbic', 'p:700000495', 'r:594000007', 'p:781222333', 'q:755123456', 's:namudu'] as $term) {
            $this->assertContains($term, $tenant, $term);
        }
        $this->assertContains(SearchIndex::idTerm('CM90012345ABCD'), $tenant);
        $this->assertContains(SearchIndex::idTerm('cm 9001 2345 abcd'), $tenant, 'as typed from a card');
        $this->assertStringNotContainsString('90012345', implode(' ', $tenant));

        $receipt = $this->terms('payment', (int) $payment->id);
        $this->assertContains('c:mp26606453', $receipt);
        $this->assertContains('n:26606453', $receipt);
        $this->assertSame(['payment', 500000], [DB::table('rental_search_entries')->where('kind', 'payment')->where('record_id', $payment->id)->value('kind'), (int) DB::table('rental_search_entries')->where('kind', 'payment')->where('record_id', $payment->id)->value('amount_minor')]);
        $this->assertNotContains('w:allan', $receipt, 'a receipt is found through its tenant, so a rename touches one row');

        $this->assertContains('w:a1', $this->terms('unit', (int) $units[0]->id));
        $this->assertContains('w:kisaasi', $this->terms('unit', (int) $units[0]->id));
        $this->assertContains('c:'.SearchText::code($lease->number), $this->terms('lease', (int) $lease->id));
        $this->assertContains('w:kisaasi', $this->terms('property', (int) $property->id));

        // A rename reaches the lease that shows it, not the receipts.
        $t = RentalTenant::withoutGlobalScopes()->findOrFail($lease->tenant_id);
        app(Tenants::class)->update($owner, $t, ['name' => 'Allan Kizza']);
        SearchIndex::flush();
        $this->assertContains('w:kizza', $this->terms('tenant', (int) $t->id));
        $this->assertNotContains('w:namuddu', $this->terms('tenant', (int) $t->id));
        $this->assertContains('w:kizza', $this->terms('lease', (int) $lease->id));

        // A bill's paid amount changing writes nothing: nothing it is found by changed.
        $charge = \App\Models\RentalCharge::withoutGlobalScopes()->where('lease_id', $lease->id)->firstOrFail();
        SearchIndex::flush();
        $charge->paid_minor = (int) $charge->paid_minor;
        $charge->save();
        $this->assertSame([], (new \ReflectionProperty(SearchIndex::class, 'dirty'))->getValue());

        // The index being broken stops nothing.
        $broken = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$broken) {
            if ($broken && str_contains($sql, 'rental_search_entries')) {
                throw new \RuntimeException('the index is broken');
            }
        });
        app(Units::class)->update($owner, $units[1], ['code' => 'A2B']);
        SearchIndex::flush();
        $broken = false;
        $this->assertSame('A2B', DB::table('rental_units')->where('id', $units[1]->id)->value('code'));
        $this->assertSame([], app(\App\Services\Rentals\Jobs\RentalCheck::class)->run((int) $company->id));
    }

    public function test_the_command_fills_an_existing_account_repairs_it_and_is_safe_to_run_again(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 3);
        $lease = $this->tenantIn($owner, $units[0], Carbon::now()->toDateString(), ['tenant' => ['name' => 'Asha Nakato', 'phone' => '0701000222']]);
        SearchIndex::flush();
        DB::table('rental_search_keys')->where('company_id', $company->id)->delete();
        DB::table('rental_search_entries')->where('company_id', $company->id)->delete();

        // As on the live server the day this ships: nothing indexed.
        $this->assertSame(0, Artisan::call('rentals:search-index', ['--company' => $company->id]));
        $count = fn (string $kind) => DB::table('rental_search_entries')->where('company_id', $company->id)->where('kind', $kind)->count();
        $this->assertSame(3, $count('unit'));
        $this->assertSame(1, $count('tenant'));
        $this->assertSame(1, $count('lease'));
        $this->assertGreaterThan(0, $count('bill'));
        $this->assertContains('w:nakato', $this->terms('tenant', (int) $lease->tenant_id));
        $keys = DB::table('rental_search_keys')->where('company_id', $company->id)->count();

        // Again: nothing doubled.
        Artisan::call('rentals:search-index', ['--company' => $company->id]);
        Artisan::call('rentals:search-index', ['--company' => $company->id, '--full' => true]);
        $this->assertSame($keys, DB::table('rental_search_keys')->where('company_id', $company->id)->count());
        $this->assertSame(3, $count('unit'));

        // A row whose record is gone is removed, its keys with it; one missing comes back.
        $ghost = DB::table('rental_search_entries')->insertGetId(['company_id' => $company->id, 'kind' => 'unit', 'record_id' => 999999999, 'title' => 'Ghost', 'body' => 'ghost', 'updated_at' => now()]);
        DB::table('rental_search_keys')->insert(['entry_id' => $ghost, 'company_id' => $company->id, 'term' => 'w:ghost']);
        DB::table('rental_search_entries')->where('kind', 'tenant')->where('record_id', $lease->tenant_id)->delete();
        Artisan::call('rentals:search-index', ['--company' => $company->id]);
        $this->assertSame(0, DB::table('rental_search_entries')->where('id', $ghost)->count());
        $this->assertSame(0, DB::table('rental_search_keys')->where('term', 'w:ghost')->where('company_id', $company->id)->count());
        $this->assertSame(1, $count('tenant'));
    }

    public function test_a_closed_account_leaves_nothing_and_an_export_holds_no_index(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 1);
        $lease = $this->tenantIn($owner, $units[0], Carbon::now()->toDateString());
        SearchIndex::flush();
        DB::table('rental_search_marks')->insert(['company_id' => $company->id, 'user_id' => $owner->id, 'kind' => 'tenant', 'record_id' => $lease->tenant_id, 'opened_at' => now()]);
        foreach (['rental_search_entries', 'rental_search_keys', 'rental_search_marks'] as $table) {
            $this->assertNotContains($table, TenantDataService::tenantTables());
            $this->assertGreaterThan(0, DB::table($table)->where('company_id', $company->id)->count());
        }
        $id = (int) $company->id;
        app(TenantDataService::class)->purge($id);
        foreach (['rental_search_entries', 'rental_search_keys', 'rental_search_marks'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $id)->count(), $table);
        }
        $this->assertNull(Company::withoutGlobalScopes()->find($id));
    }

    public function test_text_is_cut_one_way(): void
    {
        foreach (['0772 123456', '772123456', '+256772123456', '256 772 123456', '0772-123-456'] as $phone) {
            $this->assertSame('772123456', SearchText::national($phone), $phone);
        }
        $this->assertSame('r001450', SearchText::code('R-001450'));
        $this->assertSame(SearchText::sound('Nakato'), SearchText::sound('Nakatto'));
        $this->assertSame(SearchText::sound('Namuddu'), SearchText::sound('Namudu'));
        $this->assertSame(SearchText::skeleton('Mohammed'), SearchText::skeleton('Muhammad'));
        $this->assertSame([[650000], '', []], SearchText::amounts('650k'));
        $this->assertSame([[650000], 'nambi', []], SearchText::amounts('650,000 nambi'));
        $this->assertSame([[1500000], '', []], SearchText::amounts('1.5m'));
        $this->assertSame([[], '6150000', [6150000]], SearchText::amounts('6150000'));
        $this->assertSame([[], 'R-001450', []], SearchText::amounts('R-001450'));
        $this->assertSame([[], '0700000495', []], SearchText::amounts('0700000495'));
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->assertSame([['2026-11-02'], ''], SearchText::dates('2 nov'));
        $this->assertSame([['2025-12-25'], ''], SearchText::dates('25 Dec'));
        $this->assertSame([['2026-10-05'], 'x'], SearchText::dates('5/10/2026 x'));
        $this->assertSame([['2026-11-04'], ''], SearchText::dates('today'));
        Carbon::setTestNow();
        $this->assertTrue(SearchText::surelyPhone('0700000495'));
        $this->assertTrue(SearchText::surelyPhone('+256700000495'));
        $this->assertFalse(SearchText::surelyPhone('6150000'));
    }
}
