<?php

namespace Tests\Feature\Edition;

use App\Models\Company;
use App\Services\Install\SingleBusinessGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The database's own lock on one business (SingleBusinessGuard): a raw INSERT of a second company
 * and any DELETE are refused. CREATE TRIGGER ends any open transaction on MySQL, so the MySQL case
 * runs without the usual test transaction and removes its row and the triggers itself.
 */
class SingleBusinessTriggersTest extends TestCase
{
    public function test_mysql_triggers_refuse_a_raw_second_company_and_any_delete(): void
    {
        $guard = new SingleBusinessGuard();
        $guard->uninstall();
        $onlyId = null;
        try {
            DB::table('companies')->whereNotNull('standalone_slot')->update(['standalone_slot' => null]);
            $onlyId = DB::table('companies')->insertGetId(['owner_id' => 0, 'name' => 'Only business (trigger test)', 'standalone_slot' => 1, 'created_at' => now(), 'updated_at' => now()]);

            $this->assertCount(3, $guard->install());
            $this->assertSame([], $guard->install(), 'idempotent');
            $this->assertTrue($guard->installed());

            $this->assertRefused(fn () => DB::table('companies')->insert(['owner_id' => 0, 'name' => 'Second (raw SQL)', 'created_at' => now(), 'updated_at' => now()]), '1062');
            $this->assertRefused(fn () => DB::table('companies')->where('id', $onlyId)->delete(), Company::ONE_BUSINESS);

            DB::table('companies')->where('id', $onlyId)->update(['standalone_slot' => null, 'name' => 'Renamed']);
            $this->assertSame(1, (int) DB::table('companies')->where('id', $onlyId)->value('standalone_slot'), 'an update keeps slot 1');
            $this->assertSame(0, DB::table('companies')->where('name', 'Second (raw SQL)')->count());
        } finally {
            $guard->uninstall();
            if ($onlyId) {
                DB::table('companies')->where('id', $onlyId)->delete();
            }
        }
        $this->assertFalse($guard->installed());
    }

    public function test_sqlite_triggers_do_the_same(): void
    {
        config(['database.connections.edition_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('edition_sqlite');
        try {
            Schema::create('companies', function ($t) {
                $t->id();
                $t->string('name');
                $t->unsignedTinyInteger('standalone_slot')->nullable()->unique();
            });
            $guard = new SingleBusinessGuard();
            $this->assertCount(3, $guard->install());
            $this->assertTrue($guard->installed());

            DB::table('companies')->insert(['name' => 'Only']);
            $this->assertSame(1, (int) DB::table('companies')->value('standalone_slot'));
            $this->assertRefused(fn () => DB::table('companies')->insert(['name' => 'Second']), 'UNIQUE');
            $this->assertRefused(fn () => DB::table('companies')->delete(), Company::ONE_BUSINESS);
            $guard->uninstall();
            $this->assertFalse($guard->installed());
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('edition_sqlite');
        }
    }

    private function assertRefused(\Closure $statement, string $needle): void
    {
        try {
            $statement();
            $this->fail("The database accepted a statement it must refuse ({$needle}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
