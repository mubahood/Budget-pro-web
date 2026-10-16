<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Licensing\StandalonePlan;
use App\Support\AdminAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reference data for a standalone copy (docs/STANDALONE_EDITION_PLAN.md §7.1, §9), run by the
 * installer and the updater instead of DatabaseSeeder's SaaS list. Idempotent.
 *
 *  - Roles, permissions and menus exactly as AdminRolesSeeder lays them on a fresh database: the
 *    platform role first and the owner role ("company") second, because id 2 is hard-coded as the
 *    owner role (EnsureCompanyOwnerRole, User::ensureCompanyOwnerRole). Then the per-shop roles.
 *  - The setup wizard's template packs (ProductTemplateSeeder). Poultry reference data and the
 *    classic admin menus come with their migrations.
 *  - One plan, slug "standalone": lifetime, free, every feature on, every limit unlimited until
 *    `licence:apply` writes the licence's limits (StandalonePlan::apply).
 *  - No SaaS plans (PlanSeeder would hand plan-less companies a Business subscription), no platform
 *    admin, no demo: the shop owner is the top of the tree.
 */
class StandaloneReferenceSeeder extends Seeder
{
    public const FEATURES = [
        'inventory' => true, 'sales' => true, 'finance' => true, 'budgets' => true, 'api_access' => true, 'forecasting' => true,
        'auto_reorder' => true, 'multi_location' => true, 'whatsapp_receipts' => true, 'whatsapp_automation' => true, 'shop_v2' => true,
    ];

    /** Every key a plan can carry (PlanController::LIMIT_KEYS), so Entitlements::DEFAULT_LIMITS never applies. */
    public const LIMITS = [
        'max_users' => null, 'max_products' => null, 'max_sales_per_month' => null, 'max_budget_programs' => null,
        'max_locations' => null, 'max_devices' => null, 'storage_mb' => null,
    ];

    /** Classic menu entries for SaaS-only screens (404 on standalone): left out of the menu. */
    public const SAAS_MENU_URIS = ['billing', 'plans', 'subscriptions', 'pingpin-plans', 'product-templates', 'companies', 'gens', 'gen'];

    public function run(): void
    {
        $this->call(AdminRolesSeeder::class);
        AdminAccess::ensureShopRoles();
        $this->call(ProductTemplateSeeder::class);
        self::ensurePlan();
        self::hideSaasMenus();

        $ownerRoleId = (int) DB::table('admin_roles')->where('slug', 'company')->value('id');
        if ($ownerRoleId !== 2) {
            // Only on a database that already had roles: a fresh install always gets id 2.
            Log::warning('[standalone] the owner role is not id 2; owners get role 2 (EnsureCompanyOwnerRole)', ['owner_role_id' => $ownerRoleId]);
        }
    }

    /** The standalone plan; an existing one keeps its limits (the licence's), and gets any missing feature or limit key. */
    public static function ensurePlan(): Plan
    {
        $plan = Plan::query()->where('slug', StandalonePlan::SLUG)->first() ?? new Plan(['slug' => StandalonePlan::SLUG, 'limits' => self::LIMITS]);
        $plan->fill([
            'name' => 'Standalone',
            'description' => 'This installation: one business, every feature, no subscription.',
            'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'lifetime', 'trial_days' => 0,
            'is_active' => true, 'is_public' => false, 'sort_order' => 99,
            'features' => self::FEATURES + (array) ($plan->features ?? []),
            'limits' => (array) ($plan->limits ?? []) + self::LIMITS,
        ]);
        $plan->save();

        return $plan;
    }

    private static function hideSaasMenus(): void
    {
        $ids = DB::table('admin_menu')->whereIn('uri', self::SAAS_MENU_URIS)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('admin_role_menu')->whereIn('menu_id', $ids)->delete();
            DB::table('admin_menu')->whereIn('id', $ids)->delete();
        }
    }
}
