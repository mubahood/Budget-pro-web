<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends shop users from the classic admin to the same screen in the new interface
 * (budget-pro-new, config('saas.new_ui_url')), once config('saas.redirect_to_new_ui') is on.
 *
 * Only page views move: GET/HEAD, not AJAX or JSON, for a signed-in shop user (never a platform
 * administrator) whose company uses the shop module. Writes, AJAX, the API, public receipt and invite
 * pages, and every classic screen the new interface has no equivalent for (budgets and pledges,
 * poultry, device tracking, data exports…) stay here. The classic login page sends people to the
 * new one.
 *
 * `?classic=1` on any classic URL keeps that browser session in the classic screens (the new
 * interface's "Classic screens" button uses it; platform administrators sign in at
 * /auth/login?classic=1).
 */
class RedirectToNewUi
{
    public const STAY = 'bp_classic';

    /**
     * Classic path (first segment) → new-interface path. A record id in the classic URL
     * (…/15 or …/15/edit) opens that record: `peek` is the new interface's "open this one" parameter.
     */
    public const MAP = [
        '' => '/dashboard', 'home' => '/dashboard', 'index' => '/dashboard',
        'stock-items' => '/products', 'stock-categories' => '/products/categories', 'stock-sub-categories' => '/products/categories',
        'units' => '/products/units',
        'sale-records' => '/sales', 'customers' => '/customers', 'suppliers' => '/suppliers',
        'stock-records' => '/stock/movements', 'goods-receipts' => '/stock/receipts', 'purchase-orders' => '/stock/orders',
        'purchase-returns' => '/stock/supplier-returns', 'stock-takes' => '/stock/counts', 'locations' => '/stock/locations',
        'stock-transfers' => '/stock/locations', 'reorder-suggestions' => '/stock/reorder',
        'financial-records' => '/money/records', 'financial-categories' => '/money/records', 'shifts' => '/money/shifts',
        'financial-periods' => '/money/periods', 'reports' => '/reports', 'financial-reports' => '/reports',
        'companies-edit' => '/settings', 'employees' => '/team', 'billing' => '/plan', 'subscription-expired' => '/plan',
        'setup' => '/welcome',
    ];

    /** Records the new interface opens with ?peek=<id>. */
    private const PEEKS = ['stock-items', 'sale-records', 'customers', 'suppliers'];

    public function handle(Request $request, Closure $next): Response
    {
        $newUi = rtrim((string) config('saas.new_ui_url'), '/');
        if ($newUi === '' || ! config('saas.redirect_to_new_ui')) {
            return $next($request);
        }
        if ($request->boolean('classic')) {
            $request->session()->put(self::STAY, true);

            return $next($request);
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }
        if (($request->ajax() && ! $request->pjax()) || $request->expectsJson() || $request->session()->get(self::STAY)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $user = Admin::user();

        if ($user === null) {
            // Signed out: the classic login page becomes the new one.
            return $path === 'auth/login' ? $this->go($request, $newUi.'/login') : $next($request);
        }
        if (PlatformAdminOnly::isPlatformAdmin($user) || ! $this->usesShop($user)) {
            return $next($request);
        }

        $target = self::target($path);

        return $target === null ? $next($request) : $this->go($request, $newUi.$target);
    }

    /** The new-interface path for a classic path, or null when the new interface has no such screen. */
    public static function target(string $path): ?string
    {
        $parts = $path === '' ? [''] : explode('/', $path);
        $base = self::MAP[$parts[0]] ?? null;
        if ($base === null) {
            return null;
        }
        $rest = array_slice($parts, 1);
        if ($parts[0] === 'sale-records' && ($rest[0] ?? null) === 'create') {
            return '/sell';
        }
        if (isset($rest[0]) && ctype_digit($rest[0]) && in_array($parts[0], self::PEEKS, true)) {
            return $base.'?peek='.$rest[0];
        }
        if (($rest[0] ?? null) === 'create' && in_array($parts[0], ['stock-items', 'customers', 'suppliers', 'financial-records', 'goods-receipts', 'purchase-orders'], true)) {
            return $base.'?new=1';
        }

        return $base;
    }

    private function usesShop($user): bool
    {
        $company = Company::withoutGlobalScopes()->find($user->company_id);

        return $company !== null && in_array('shop', $company->modules(), true);
    }

    /** A normal redirect; for laravel-admin's pjax navigation, a page that moves the whole window. */
    private function go(Request $request, string $url): Response
    {
        if ($request->pjax()) {
            return response('<script>window.location.href = '.json_encode($url, JSON_UNESCAPED_SLASHES).';</script>', 200, ['Content-Type' => 'text/html']);
        }

        return redirect()->away($url);
    }
}
