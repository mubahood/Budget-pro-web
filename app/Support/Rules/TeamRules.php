<?php

namespace App\Support\Rules;

use App\Services\Team\Permissions;

/**
 * What a team request may contain: one rule set for the mobile API (TeamController) and the new
 * web interface (budget-pro-new Team screen). The business rules themselves (who can be invited,
 * owner protection, seat limits) live in App\Services\Team\TeamService.
 */
class TeamRules
{
    /** Permissions that stay with the owner whatever a role override says. */
    public const OWNER_ONLY = ['billing', 'manage_team', 'manage_settings'];

    /** @return array<string, array<int, mixed>> */
    public static function invite(): array
    {
        return ['role' => ['required', 'string'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'name' => ['nullable', 'string', 'max:150']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function member(): array
    {
        return ['role' => ['nullable', 'string'], 'active' => ['nullable', 'boolean']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rolePermissions(): array
    {
        return ['permissions' => ['present', 'array'], 'permissions.*' => ['in:'.implode(',', Permissions::all())]];
    }

    /** Roles a company can adjust (every role but the owner). @return array<string, string> key => label */
    public static function editableRoles(): array
    {
        return collect(config('permissions.roles'))->except('owner')->map(fn ($r) => $r['label'])->all();
    }

    /**
     * The same rules with every key under a prefix (for a form that keeps them in an array property).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function prefixed(string $prefix, array $rules): array
    {
        return collect($rules)->mapWithKeys(fn ($r, $k) => ["{$prefix}.{$k}" => $r])->all();
    }
}
