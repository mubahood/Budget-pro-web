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

        return self::prune($tree, self::disabledPaths($company));
    }

    /** @return array<int, string> */
    public static function disabledPaths(Company $company): array
    {
        $on = $company->modules();
        $paths = [];
        foreach (config('onboarding.module_paths') as $module => $list) {
            if (! in_array($module, $on, true)) {
                $paths = [...$paths, ...$list];
            }
        }

        return $paths;
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

    private static function prune(array $nodes, array $disabled): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $first = explode('/', trim((string) ($node['uri'] ?? ''), '/'))[0];
            if ($first !== '' && self::pathDisabled($first, $disabled)) {
                continue;
            }
            if (! empty($node['children'])) {
                $node['children'] = self::prune($node['children'], $disabled);
                if ($node['children'] === [] && $first === '') {
                    continue; // a heading whose items are all hidden
                }
            }
            $out[] = $node;
        }

        return $out;
    }
}
