<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\Team\Permissions;

/**
 * The web portal's shop menu, as data both apps read: budget-pro-new's App\Shop\Navigation draws it
 * (by `route`), and the phone's meta lists the same pages (by `path`) for "Open on the web" (D1, D7).
 * Ordered the way a shop day runs (sell → stock → people → money → reports → settings). Each entry is
 * gated by a permission (`can`, any of; none = everyone), the company's `module`, a supermarket
 * `feature` (StoreFeatures, any of) and an `edition`. `path` is the web route's URI.
 */
final class ShopMenu
{
    /** @return list<array<string, mixed>> */
    public static function sections(): array
    {
        return [
            ['key' => 'sell', 'label' => null, 'entries' => [
                ['label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'route' => 'dashboard', 'path' => '/dashboard', 'match' => ['dashboard']],
                // Team chat: every member of the shop, with the unread count (App\Shop\Badges) beside it.
                ['label' => 'Chat', 'icon' => 'fa-comment-dots', 'route' => 'chat.index', 'path' => '/chat', 'match' => ['chat.*'], 'badge' => 'chat'],
                ['label' => 'Sell', 'icon' => 'fa-cash-register', 'route' => 'pos', 'path' => '/sell', 'match' => ['pos'], 'can' => 'sell', 'module' => 'shop'],
                ['label' => 'Sales', 'icon' => 'fa-receipt', 'route' => 'sales.index', 'path' => '/sales', 'match' => ['sales.*'], 'can' => ['sell', 'view_reports'], 'module' => 'shop'],
                // Supermarket (B3): only for shops with promotions on.
                ['label' => 'Promotions', 'icon' => 'fa-tags', 'route' => 'promotions.index', 'path' => '/promotions', 'match' => ['promotions.*'], 'can' => 'manage_products', 'module' => 'shop', 'feature' => 'promotions'],
            ]],
            ['key' => 'catalogue', 'label' => 'Shop', 'module' => 'shop', 'entries' => [
                ['label' => 'Products', 'icon' => 'fa-box', 'route' => 'products.index', 'path' => '/products', 'match' => ['products.*']],
                ['key' => 'stock', 'label' => 'Stock', 'icon' => 'fa-warehouse', 'items' => [
                    ['label' => 'Stock movements', 'icon' => 'fa-arrow-right-arrow-left', 'route' => 'stock.movements', 'path' => '/stock/movements', 'match' => ['stock.movements'], 'can' => ['adjust', 'restock', 'view_reports']],
                    ['label' => 'Receive stock', 'icon' => 'fa-truck-ramp-box', 'route' => 'stock.receipts', 'path' => '/stock/receipts', 'match' => ['stock.receipts'], 'can' => 'restock'],
                    ['label' => 'Purchase orders', 'icon' => 'fa-file-signature', 'route' => 'stock.orders', 'path' => '/stock/orders', 'match' => ['stock.orders'], 'can' => 'restock'],
                    ['label' => 'Returns to supplier', 'icon' => 'fa-rotate-left', 'route' => 'stock.supplier-returns', 'path' => '/stock/supplier-returns', 'match' => ['stock.supplier-returns'], 'can' => 'restock'],
                    ['label' => 'Stock counts', 'icon' => 'fa-clipboard-check', 'route' => 'stock.counts', 'path' => '/stock/counts', 'match' => ['stock.counts*'], 'can' => 'stock_take'],
                    ['label' => 'Transfers & locations', 'icon' => 'fa-shop', 'route' => 'stock.locations', 'path' => '/stock/locations', 'match' => ['stock.locations'], 'can' => 'adjust'],
                    ['label' => 'Reorder list', 'icon' => 'fa-cart-flatbed', 'route' => 'stock.reorder', 'path' => '/stock/reorder', 'match' => ['stock.reorder'], 'can' => 'restock'],
                    // Supermarket (D2/B4): only for shops with one of these features on.
                    ['label' => 'Short-dated', 'icon' => 'fa-hourglass-half', 'route' => 'stock.expiring', 'path' => '/stock/expiring', 'match' => ['stock.expiring'], 'can' => ['adjust', 'restock'], 'feature' => ['fefo', 'markdowns']],
                ]],
                ['label' => 'Customers', 'icon' => 'fa-users', 'route' => 'customers.index', 'path' => '/customers', 'match' => ['customers.*'], 'can' => ['sell', 'view_reports']],
                ['label' => 'Debtors & creditors', 'icon' => 'fa-scale-unbalanced', 'route' => 'debts.index', 'path' => '/debts', 'match' => ['debts.*'], 'can' => ['sell', 'view_reports', 'restock']],
                ['label' => 'Suppliers', 'icon' => 'fa-people-carry-box', 'route' => 'suppliers.index', 'path' => '/suppliers', 'match' => ['suppliers.*'], 'can' => ['restock', 'view_reports']],
            ]],
            ['key' => 'money', 'label' => 'Money', 'entries' => [
                ['label' => 'Income & expenses', 'icon' => 'fa-money-bill-transfer', 'route' => 'money.records', 'path' => '/money/records', 'match' => ['money.records'], 'can' => 'manage_finance', 'module' => 'finance'],
                ['label' => 'Shifts & cash-up', 'icon' => 'fa-cash-register', 'route' => 'money.shifts', 'path' => '/money/shifts', 'match' => ['money.shifts'], 'can' => ['sell', 'view_reports'], 'module' => 'shop'],
                ['label' => 'Financial periods', 'icon' => 'fa-calendar-days', 'route' => 'money.periods', 'path' => '/money/periods', 'match' => ['money.periods'], 'can' => 'manage_finance', 'module' => 'finance'],
                ['label' => 'Reports', 'icon' => 'fa-chart-pie', 'route' => 'reports.index', 'path' => '/reports', 'match' => ['reports.*'], 'can' => 'view_reports'],
            ]],
            ['key' => 'admin', 'label' => 'Settings', 'entries' => [
                ['label' => 'Business settings', 'icon' => 'fa-store', 'route' => 'settings.business', 'path' => '/settings', 'match' => ['settings.business'], 'can' => 'manage_settings'],
                ['label' => 'Team', 'icon' => 'fa-user-group', 'route' => 'team.index', 'path' => '/team', 'match' => ['team.*'], 'can' => 'manage_team'],
                ['label' => 'My plan', 'icon' => 'fa-crown', 'route' => 'plan', 'path' => '/plan', 'match' => ['plan'], 'can' => 'billing', 'edition' => Edition::SAAS],
                // The screen itself is the owner's only (manage_settings never leaves the owner: TeamRules::OWNER_ONLY).
                ['label' => 'Your data', 'icon' => 'fa-database', 'route' => 'settings.data', 'path' => '/settings/data', 'match' => ['settings.data*'], 'can' => 'manage_settings'],
                // A buyer's own email, SMS, WhatsApp and Flutterwave accounts (standalone only).
                ['label' => 'Connections', 'icon' => 'fa-plug', 'route' => 'settings.connections', 'path' => '/settings/connections', 'match' => ['settings.connections'], 'can' => 'manage_settings', 'edition' => Edition::STANDALONE],
                // The release, the licence, updates, backups and the phone QR code (standalone only).
                ['label' => 'Licence & updates', 'icon' => 'fa-certificate', 'route' => 'settings.system', 'path' => '/settings/system', 'match' => ['settings.system*'], 'can' => 'manage_settings', 'edition' => Edition::STANDALONE],
            ]],
        ];
    }

    /**
     * Whether this person sees an entry (or section): edition, module, feature (any of), permission (any of).
     *
     * @param  callable(string): bool  $can  @param  callable(string): bool  $moduleOn  @param  callable(string): bool  $featureOn
     */
    public static function allowed(array $entry, callable $can, callable $moduleOn, callable $featureOn): bool
    {
        if (isset($entry['edition']) && $entry['edition'] !== Edition::name()) {
            return false;
        }
        if (isset($entry['module']) && ! $moduleOn($entry['module'])) {
            return false;
        }
        if (isset($entry['feature']) && ! collect((array) $entry['feature'])->contains(fn ($f) => $featureOn($f))) {
            return false;
        }
        if (isset($entry['can'])) {
            return collect((array) $entry['can'])->contains(fn ($p) => $can($p));
        }

        return true;
    }

    /**
     * The web pages this person may open, flat, for the phone: [{key, label, section, path}] (key = the web route name).
     *
     * @return list<array{key: string, label: string, section: ?string, path: string}>
     */
    public static function pagesFor(User $user, Company $company): array
    {
        $can = fn (string $p) => Permissions::can($user, $p);
        $moduleOn = fn (string $m) => in_array($m, $company->modules(), true);
        $featureOn = fn (string $f) => StoreFeatures::enabled($company, $f);
        $out = [];
        foreach (self::sections() as $section) {
            if (! self::allowed($section, $can, $moduleOn, $featureOn)) {
                continue;
            }
            foreach ($section['entries'] as $entry) {
                if (! self::allowed($entry, $can, $moduleOn, $featureOn)) {
                    continue;
                }
                foreach ($entry['items'] ?? [$entry] as $page) {
                    if ($page !== $entry && ! self::allowed($page, $can, $moduleOn, $featureOn)) {
                        continue;
                    }
                    $out[] = ['key' => $page['route'], 'label' => $page['label'], 'section' => $section['label'] ?? null, 'path' => $page['path']];
                }
            }
        }

        return $out;
    }
}
