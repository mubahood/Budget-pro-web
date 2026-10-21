<?php

namespace App\Support\Rules;

use App\Services\Team\Permissions;
use Illuminate\Validation\Rule;

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

    /**
     * A member added directly with a login and a password (TeamService::createMember).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function newMember(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:191', Rule::unique('admin_users', 'email')],
            'password' => PasswordPolicy::rules(max: 191), 'role' => ['required', 'string']];
    }

    /**
     * A member's name and contact details (TeamService::updateDetails). The email is a login, so unique.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function details(?int $ignoreUserId = null): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['nullable', 'email', 'max:191', Rule::unique('admin_users', 'email')->ignore($ignoreUserId)],
            'phone' => ['nullable', 'string', 'max:30'], 'phone_2' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500']];
    }

    /** A new password set for a member (TeamService::setPassword). @return array<string, array<int, mixed>> */
    public static function password(): array
    {
        return ['password' => PasswordPolicy::rules(confirmed: true, max: 191)];
    }

    /** @return array<string, array<int, mixed>> */
    public static function member(): array
    {
        return ['role' => ['nullable', 'string'], 'active' => ['nullable', 'boolean']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rolePermissions(?\App\Models\Company $company = null): array
    {
        return ['permissions' => ['present', 'array'], 'permissions.*' => ['in:'.implode(',', $company ? Permissions::availableFor((int) $company->id) : Permissions::all())]];
    }

    /**
     * Roles a company can give and adjust (every role it is offered but the owner): a shop's six, or a SACCO's
     * sacco_* roles and the viewer. Without a company: the family's roles, as before modules had roles.
     *
     * @return array<string, string> key => label
     */
    public static function editableRoles(?\App\Models\Company $company = null): array
    {
        $keys = $company ? Permissions::rolesFor((int) $company->id) : array_keys(array_filter((array) config('permissions.roles'), fn ($r) => ! isset($r['module'])));

        return collect($keys)->reject(fn ($k) => $k === 'owner')->mapWithKeys(fn ($k) => [$k => (string) config("permissions.roles.{$k}.label")])->all();
    }

    /**
     * The permissions a company's role screen lists, with their labels (a shop's 18; a SACCO's own).
     *
     * @return array<string, string>
     */
    public static function permissionLabels(\App\Models\Company $company): array
    {
        return array_intersect_key((array) config('permissions.permissions'), array_flip(Permissions::availableFor((int) $company->id)));
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
