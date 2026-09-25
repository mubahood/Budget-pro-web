<?php

namespace App\Models;

use App\Http\Middleware\PlatformAdminOnly;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Facades\Admin;

/** The web sidebar without the modules a shop has switched off (plan C4). */
class AdminMenu extends Menu
{
    protected $table = 'admin_menu';

    public function toTree(): array
    {
        $tree = parent::toTree();
        $user = Admin::user();
        if ($user === null || PlatformAdminOnly::isPlatformAdmin($user)) {
            return $tree;
        }
        $company = Company::withoutGlobalScopes()->find($user->company_id);
        if ($company === null) {
            return $tree;
        }

        $visible = fn (array $node): bool => ! method_exists($user, 'visible') || $user->visible((array) ($node['roles'] ?? []));

        return self::prune(self::pruneHidden($tree, $visible), self::disabledPaths($company));
    }

    /** Drop items the user's role cannot see, so a heading whose items are all hidden goes too. */
    public static function pruneHidden(array $nodes, \Closure $visible): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! $visible($node)) {
                continue;
            }
            if (! empty($node['children']) && is_array($node['children'])) {
                $node['children'] = self::pruneHidden($node['children'], $visible);
                if ($node['children'] === []) {
                    continue;
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function disabledPaths(Company $company): array
    {
        $on = self::modulesOn($company);
        $paths = [];
        foreach (config('onboarding.module_paths') as $module => $list) {
            if (! in_array($module, $on, true)) {
                $paths = [...$paths, ...$list];
            }
        }

        return $paths;
    }

    /**
     * Modules switched on for the company. Ping Pin is not a shop module the owner toggles:
     * it is on for companies that have a Ping Pin subscription or tracked devices.
     *
     * @return array<int, string>
     */
    public static function modulesOn(Company $company): array
    {
        $on = $company->modules();
        $usesPingPin = (\Illuminate\Support\Facades\Schema::hasTable('pingpin_subscriptions') && \Illuminate\Support\Facades\DB::table('pingpin_subscriptions')->where('company_id', $company->id)->exists())
            || (\Illuminate\Support\Facades\Schema::hasTable('tracked_devices') && \Illuminate\Support\Facades\DB::table('tracked_devices')->where('company_id', $company->id)->exists());
        if ($usesPingPin) {
            $on[] = 'pingpin';
        }

        return $on;
    }

    public static function pathDisabled(string $firstSegment, array $disabled): bool
    {
        foreach ($disabled as $p) {
            if (str_ends_with($p, '*') ? str_starts_with($firstSegment, rtrim($p, '*')) : $firstSegment === $p) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop items of switched-off modules. A heading is judged only by its items (its own uri is
     * ignored — old headings carried an item's uri such as `financial-periods`): it disappears
     * when none of its items is left.
     */
    public static function prune(array $nodes, array $disabled): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (array_key_exists('children', $node) && is_array($node['children']) && $node['children'] !== []) {
                $node['children'] = self::prune($node['children'], $disabled);
                if ($node['children'] === []) {
                    continue; // a heading whose items are all hidden
                }
                $out[] = $node;

                continue;
            }
            $uri = trim((string) ($node['uri'] ?? ''));
            if ($uri === '') {
                continue; // a heading with no items (nothing to open)
            }
            $first = explode('/', trim($uri, '/'))[0];
            if ($first !== '' && self::pathDisabled($first, $disabled)) {
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }
}
