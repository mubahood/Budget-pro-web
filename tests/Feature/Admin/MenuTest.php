<?php

namespace Tests\Feature\Admin;

use App\Models\AdminMenu;
use App\Support\AdminAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** The web sidebar and the tenant allow-list (menu review 2026-09-30). */
class MenuTest extends AdminTestCase
{
    private const OWNERS = ['company', 'worker', 'super-treasurer', 'treasurer'];

    /** Recreates the production menu as it was before the tidy (titles, uris, parents, role rules). */
    private function productionMenu(): void
    {
        DB::table('admin_role_menu')->delete();
        DB::table('admin_menu')->delete();
        AdminAccess::ensureShopRoles();
        $finance = [...self::OWNERS, 'shop_manager', 'shop_accountant'];
        $n = 0;
        $add = function (string $title, string $uri, int $parent = 0, array $roles = []) use (&$n): int {
            $id = (int) DB::table('admin_menu')->insertGetId(['parent_id' => $parent, 'order' => ++$n, 'title' => $title, 'icon' => 'fa-circle', 'uri' => $uri, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('admin_roles')->whereIn('slug', $roles)->pluck('id') as $roleId) {
                DB::table('admin_role_menu')->insert(['role_id' => $roleId, 'menu_id' => $id]);
            }

            return $id;
        };
        $add('Dashboard', '/');
        $g29 = $add('Financial periods', 'financial-periods');
        $shop = $add('Shop', '');
        $budget = $add('Budget Management', 'budget');
        $g11 = $add('Financial periods', 'financial-periods', 0, $finance);
        $admin = $add('Admin', '', 0, ['admin']);
        $poultry = $add('Poultry Management', '');
        $pingpin = $add('Ping Pin', '');
        $billing = $add('Billing', '', 0, ['admin']);
        $add('My plan & billing', 'billing');
        $add('Your data', 'your-data');
        foreach ([['Users', 'auth/users'], ['Roles', 'auth/roles'], ['Menu', 'auth/menu'], ['System health', 'system-health']] as [$t, $u]) {
            $add($t, $u, $admin);
        }
        $add('System Configuration', 'companies-edit', $g11);
        $add('Team', 'employees', $g11);
        $add('Team', 'employees', $g11, self::OWNERS);
        $add('My Profile', 'companies-edit', $g11, self::OWNERS);
        $add('Financial periods', 'financial-periods', $g29);
        foreach ([['Categories', 'stock-categories'], ['Sub-categories', 'stock-sub-categories'], ['Products', 'stock-items'], ['Stock movements', 'stock-records'],
            ['Sales / POS', 'sale-records'], ['Finance categories', 'financial-categories'], ['Income & expenses', 'financial-records'], ['Financial reports', 'financial-reports']] as [$t, $u]) {
            $add($t, $u, $g29);
        }
        $add('Inventory Management', '', $g29);
        foreach (['contribution-records', 'budget-programs', 'budget-item-categories', 'budget-items', 'data-exports'] as $u) {
            $add(Str::headline($u), $u, $budget);
        }
        $add('Batches', 'poultry-batches', $poultry);
        $add('Daily Records', 'poultry-daily-records', $poultry);
        foreach ([['Devices', 'tracked-devices'], ['Fleet Map', 'tracking-map'], ['Location History', 'device-locations'], ['Remote Commands', 'device-commands']] as [$t, $u]) {
            $add($t, $u, $pingpin);
        }
        $add('Plans', 'pingpin-plans', $pingpin, ['admin']);
        $add('Plans', 'plans', $billing, ['admin']);
        foreach ([['Customers', 'customers'], ['Suppliers', 'suppliers'], ['Receive stock', 'goods-receipts'], ['Stock counts', 'stock-takes'], ['Shifts & cash-up', 'shifts'],
            ['Units', 'units'], ['Reports', 'reports'], ['Locations', 'locations'], ['Mobile money & reminders', 'engagement']] as [$t, $u]) {
            $add($t, $u, $shop);
        }
    }

    private function runTidy(): void
    {
        (require database_path('migrations/2026_09_30_200001_tidy_admin_menu.php'))->up();
    }

    /** Structural problems left in the menu (empty = clean). */
    private function problems(): array
    {
        $rows = DB::table('admin_menu')->get();
        $out = [];
        foreach ($rows as $r) {
            $kids = $rows->where('parent_id', $r->id);
            if ($kids->isNotEmpty() && (string) $r->uri !== '') {
                $out[] = "heading {$r->title} has uri {$r->uri}";
            }
            if ($kids->isEmpty() && (string) $r->uri === '') {
                $out[] = "empty heading {$r->title}";
            }
        }
        foreach ($rows->groupBy(fn ($r) => $r->parent_id.'|'.$r->title) as $key => $group) {
            if ($group->count() > 1) {
                $out[] = "duplicate {$key}";
            }
        }
        foreach ($rows->where('uri', '!=', '')->groupBy(fn ($r) => $r->parent_id.'|'.$r->uri) as $key => $group) {
            if ($group->count() > 1) {
                $out[] = "duplicate uri {$key}";
            }
        }

        return $out;
    }

    private function snapshot(): array
    {
        return DB::table('admin_menu')->orderBy('id')->get(['id', 'parent_id', 'order', 'title', 'uri'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_the_tidy_fixes_the_production_menu_and_survives_the_seeders(): void
    {
        $this->productionMenu();
        $this->runTidy();

        $this->assertSame([], $this->problems());
        $sell = DB::table('admin_menu')->where('uri', 'stock-items')->value('parent_id');
        $this->assertSame('Sell & stock', DB::table('admin_menu')->where('id', $sell)->value('title'));
        $this->assertSame(
            ['sale-records', 'stock-items', 'customers', 'stock-records', 'goods-receipts', 'financial-records', 'reports', 'financial-reports', 'stock-categories', 'stock-sub-categories', 'financial-categories', 'financial-periods'],
            DB::table('admin_menu')->where('parent_id', $sell)->orderBy('order')->pluck('uri')->all(),
            'most-used first'
        );
        $this->assertSame(['Dashboard', 'Sell & stock', 'More shop tools'], DB::table('admin_menu')->where('parent_id', 0)->orderBy('order')->limit(3)->pluck('title')->all());
        $settings = DB::table('admin_menu')->where('uri', 'employees')->value('parent_id');
        $this->assertSame('Settings', DB::table('admin_menu')->where('id', $settings)->value('title'));
        $this->assertSame(1, DB::table('admin_menu')->where('uri', 'employees')->count());
        $this->assertSame(1, DB::table('admin_menu')->where('uri', 'companies-edit')->count());
        $this->assertTrue(DB::table('admin_role_menu')->where('menu_id', DB::table('admin_menu')->where('uri', 'companies-edit')->value('id'))->exists(), 'the row with role rules is the one kept');
        $this->assertSame(0, DB::table('admin_menu')->where('title', 'Inventory Management')->count());
        $this->assertSame('', DB::table('admin_menu')->where('title', 'Budget Management')->value('uri'));
        $this->assertSame(0, DB::table('admin_menu')->where('parent_id', 0)->where('uri', 'like', 'financial-%')->count());
        $this->assertTrue(DB::table('admin_role_menu')->where('menu_id', DB::table('admin_menu')->where('uri', 'financial-periods')->value('id'))->exists(), 'financial periods now carries the finance roles');
        $this->assertStringContainsString('/ajax*', (string) DB::table('admin_permissions')->where('slug', 'tenant.workspace')->value('http_path'));

        $clean = $this->snapshot();
        $this->runTidy();
        $this->assertSame($clean, $this->snapshot(), 'running the tidy again changes nothing');

        // Production deploys run db:seed after migrate: the seeders only add their own missing platform items.
        $this->seed(DatabaseSeeder::class);
        $seeded = $this->snapshot();
        $roleMenus = DB::table('admin_role_menu')->count();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame([], $this->problems(), 'db:seed does not bring duplicates or uri headings back');
        $this->assertSame($seeded, $this->snapshot(), 'a second seed changes nothing');
        $this->assertSame($roleMenus, DB::table('admin_role_menu')->count());
        foreach ($clean as $row) {
            $this->assertContains($row, $seeded, 'seeding kept every tidied row as it was');
        }
        $this->assertSame(0, DB::table('admin_menu')->where('parent_id', 0)->where('uri', 'like', 'financial-%')->count());
        $this->runTidy();
        $this->assertSame([], $this->problems(), 'and the tidy after seeding keeps it clean');
        $this->assertCount(count($seeded), $this->snapshot());
    }

    public function test_seeding_twice_creates_nothing_new(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tables = ['plans', 'pingpin_plans', 'product_templates', 'admin_roles', 'admin_permissions', 'admin_menu', 'admin_role_menu', 'admin_role_permissions', 'subscriptions'];
        $before = array_combine($tables, array_map(fn ($t) => DB::table($t)->count(), $tables));
        $this->seed(DatabaseSeeder::class);
        $after = array_combine($tables, array_map(fn ($t) => DB::table($t)->count(), $tables));
        $this->assertSame($before, $after);
        $this->assertSame(DB::table('admin_roles')->count(), DB::table('admin_roles')->distinct()->count('slug'));
    }

    public function test_a_shop_sees_only_its_own_modules(): void
    {
        $this->productionMenu();
        $this->runTidy();
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['business_type' => 'retail', 'enabled_modules' => null])->saveQuietly();
        Auth::guard('admin')->login($t['user']);

        $titles = fn () => collect((new AdminMenu())->toTree())->pluck('title')->all();
        $top = $titles();
        $this->assertContains('Sell & stock', $top);
        $this->assertNotContains('Ping Pin', $top, 'no Ping Pin for a shop without devices');
        $this->assertNotContains('Poultry Management', $top);
        $this->assertNotContains('Budget Management', $top);
        $this->assertNotContains('Admin', $top);

        // Finance switched off: the shop group stays (it used to vanish with its financial-* uri).
        $t['company']->forceFill(['enabled_modules' => ['shop']])->saveQuietly();
        $tree = collect((new AdminMenu())->toTree());
        $sell = $tree->firstWhere('title', 'Sell & stock');
        $this->assertNotNull($sell);
        $this->assertContains('stock-items', array_column($sell['children'], 'uri'));
        $this->assertNotContains('financial-records', array_column($sell['children'], 'uri'));

        DB::table('tracked_devices')->insert(['company_id' => $t['company']->id, 'uuid' => (string) Str::uuid(), 'name' => 'Phone', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('Ping Pin', $titles(), 'shown once the company tracks a device');
    }

    public function test_every_shop_page_is_on_the_tenant_allow_list(): void
    {
        $platform = '/^(auth|_handle_|gens?\b|pingpin-plans|plans|subscriptions|companies\b|system-health|product-templates)/';
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! in_array(\App\Http\Middleware\EnsureWebAccess::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            $path = trim(preg_replace('/\{\w+\??\}/', '1', $route->uri()), '/');
            if (preg_match($platform, $path)) {
                continue;
            }
            $matched = false;
            foreach (AdminAccess::TENANT_PATHS as $pattern) {
                $p = trim($pattern, '/');
                if ($pattern === '/' ? $path === '' : Str::is($p, $path)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $missing[] = '/'.$path;
            }
        }
        $this->assertSame([], $missing, 'add these to AdminAccess::TENANT_PATHS or shop users get "Permission denied"');
    }
}
