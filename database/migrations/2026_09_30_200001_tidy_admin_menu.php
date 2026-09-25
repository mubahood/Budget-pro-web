<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tidy the web sidebar (menu review 2026-09-30).
 *
 * The phase-2 menu migration renamed rows by uri, so two headings ended up called
 * "Financial periods" with uri `financial-periods` (a heading with a module uri is
 * hidden with that module and opens a page instead of a group), plus duplicate
 * Team / System Configuration rows and an empty "Inventory Management" heading.
 *
 * Rows are found by what they contain (the heading that holds `stock-items`, the one
 * that holds `companies-edit`…), never by id, because ids differ between installs.
 * Idempotent: running it again (or re-running the seeders) changes nothing.
 * The most-used screens come first: Sales / POS, Products, Customers, Stock movements,
 * Receive stock, Income & expenses, Reports.
 */
return new class extends Migration
{
    /** "Sell & stock", in this order. Rows with these uris are moved into it. */
    public const SELL = [
        'sale-records', 'stock-items', 'customers', 'stock-records', 'goods-receipts', 'financial-records', 'reports',
        'financial-reports', 'stock-categories', 'stock-sub-categories', 'financial-categories', 'financial-periods',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('admin_menu')) {
            return;
        }
        DB::transaction(fn () => $this->tidy());

        if (Schema::hasTable('admin_roles') && DB::table('admin_roles')->exists()) {
            AdminAccess::scopeTenantRoles(); // tenant allow-list (e.g. /ajax*, /engagement) without waiting for db:seed
            AdminAccess::ensureShopRoles();  // role rows on the items whose uri moved off the headings
        }
    }

    public function down(): void
    {
        // Data tidy only: the removed rows were duplicates / empty headings.
    }

    public function tidy(): void
    {
        $now = now();

        // 1. A heading (a row with items) never carries a uri.
        $parents = DB::table('admin_menu')->where('parent_id', '>', 0)->distinct()->pluck('parent_id')->all();
        DB::table('admin_menu')->whereIn('id', $parents)->where(fn ($q) => $q->where('uri', '!=', '')->orWhereNull('uri'))->update(['uri' => '', 'updated_at' => $now]);

        // 2. The groups, found by their contents.
        $sell = $this->parentOf('stock-items') ?? $this->parentOf('sale-records');
        if ($sell === null && DB::table('admin_menu')->whereIn('uri', self::SELL)->exists()) {
            $sell = (int) DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 2, 'title' => 'Sell & stock', 'icon' => 'fa-shopping-cart', 'uri' => '', 'created_at' => $now, 'updated_at' => $now]);
        }
        $settings = $this->parentOf('companies-edit') ?? $this->parentOf('employees');
        $shop = DB::table('admin_menu')->where('parent_id', 0)->whereIn('title', ['Shop', 'More shop tools'])->orderBy('id')->value('id') ?? $this->parentOf('suppliers');

        if ($sell !== null) {
            DB::table('admin_menu')->where('id', $sell)->update(['title' => 'Sell & stock', 'icon' => 'fa-shopping-cart', 'uri' => '', 'updated_at' => $now]);
            DB::table('admin_menu')->whereIn('uri', self::SELL)->where('id', '!=', $sell)->update(['parent_id' => $sell, 'updated_at' => $now]);
        }
        if ($settings !== null && $settings !== $sell) {
            DB::table('admin_menu')->where('id', $settings)->update(['title' => 'Settings', 'icon' => 'fa-cogs', 'uri' => '', 'updated_at' => $now]);
        }
        if ($shop !== null && (int) $shop !== $sell && (int) $shop !== $settings) {
            DB::table('admin_menu')->where('id', $shop)->update(['title' => 'More shop tools', 'icon' => 'fa-th-large', 'uri' => '', 'updated_at' => $now]);
        }

        // 3. Duplicates: same group, same page → keep the row with role rules (then the oldest).
        $dups = DB::table('admin_menu')->where('uri', '!=', '')->select('parent_id', 'uri')->groupBy('parent_id', 'uri')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dups as $d) {
            $rows = DB::table('admin_menu')->where('parent_id', $d->parent_id)->where('uri', $d->uri)->pluck('id')
                ->map(fn ($id) => ['id' => (int) $id, 'roles' => DB::table('admin_role_menu')->where('menu_id', $id)->count(), 'kids' => DB::table('admin_menu')->where('parent_id', $id)->count()])
                ->sort(fn ($a, $b) => [$b['kids'], $b['roles'], $a['id']] <=> [$a['kids'], $a['roles'], $b['id']])->values();
            $this->remove($rows->slice(1)->where('kids', 0)->pluck('id')->all());
        }
        DB::table('admin_menu')->where('uri', 'companies-edit')->where('parent_id', '>', 0)->update(['title' => 'Business settings', 'updated_at' => $now]);
        DB::table('admin_menu')->where('uri', 'employees')->where('parent_id', '>', 0)->update(['title' => 'Team', 'updated_at' => $now]);

        // 4. Headings with nothing in them (e.g. "Inventory Management").
        do {
            $empty = DB::table('admin_menu as m')->where(fn ($q) => $q->where('m.uri', '')->orWhereNull('m.uri'))
                ->whereNotIn('m.title', AdminAccess::PLATFORM_MENU_TITLES)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('admin_menu as c')->whereColumn('c.parent_id', 'm.id'))
                ->pluck('m.id')->all();
            $this->remove($empty);
        } while ($empty !== []);

        // 5. Order: most-used first.
        $top = array_values(array_unique(array_filter([
            DB::table('admin_menu')->where('parent_id', 0)->where('uri', '/')->orderBy('id')->value('id'),
            $sell,
            $shop,
            $this->parentOf('budget-programs'),
            $this->parentOf('poultry-batches'),
            $this->parentOf('tracked-devices'),
            $settings,
            DB::table('admin_menu')->where('parent_id', 0)->where('uri', 'billing')->value('id'),
            DB::table('admin_menu')->where('parent_id', 0)->where('uri', 'your-data')->value('id'),
            DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id'),
            DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Billing')->value('id'),
        ])));
        $top = array_map('intval', $top);
        $rest = DB::table('admin_menu')->where('parent_id', 0)->whereNotIn('id', $top ?: [0])->orderBy('order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $n = 0;
        foreach ([...$top, ...$rest] as $id) {
            $this->order($id, $n, $id === $sell);
        }
    }

    /** The group a page sits in (null when it is missing or at the top level). */
    private function parentOf(string $uri): ?int
    {
        $id = DB::table('admin_menu')->where('uri', $uri)->where('parent_id', '>', 0)->orderBy('id')->value('parent_id');

        return $id === null ? null : (int) $id;
    }

    private function order(int $id, int &$n, bool $sell = false): void
    {
        DB::table('admin_menu')->where('id', $id)->update(['order' => ++$n]);
        $children = DB::table('admin_menu')->where('parent_id', $id)->orderBy('order')->orderBy('id')->get(['id', 'uri']);
        if ($sell) {
            $rank = array_flip(self::SELL);
            $children = $children->sortBy(fn ($c) => [$rank[$c->uri] ?? 999, $c->id])->values();
        }
        foreach ($children as $child) {
            $this->order((int) $child->id, $n);
        }
    }

    /** @param array<int, int> $ids */
    private function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        DB::table('admin_role_menu')->whereIn('menu_id', $ids)->delete();
        if (Schema::hasTable('admin_permission_menu')) {
            DB::table('admin_permission_menu')->whereIn('menu_id', $ids)->delete();
        }
        DB::table('admin_menu')->whereIn('id', $ids)->delete();
    }
};
