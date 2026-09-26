<?php

namespace App\Services\Team;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Invites, roles, deactivation and ownership transfer (plan C5, P3-4). */
class TeamService
{
    public const INVITE_DAYS = 7;

    public static function roles(): array
    {
        return array_keys(config('permissions.roles'));
    }

    /** @return array{invite: object, link: string} */
    public function invite(User $by, string $role, ?string $phone, ?string $email, ?string $name = null): array
    {
        if ($role === 'owner' || ! in_array($role, self::roles(), true)) {
            throw BusinessRuleException::make('invalid_role', 'Choose manager, cashier, stock keeper, accountant or viewer.');
        }
        $company = Company::withoutGlobalScopes()->findOrFail($by->company_id);
        $e164 = $phone ? Phone::e164($phone, $company->country ?? 'UG') : null;
        if ($phone && ! $e164) {
            throw BusinessRuleException::make('invalid_phone', 'Enter a valid phone number.');
        }
        $email = $email ? strtolower(trim($email)) : null;
        if (! $e164 && ! $email) {
            throw BusinessRuleException::make('contact_required', 'Enter a phone number or an email.');
        }
        $existing = User::withoutGlobalScopes()->where(fn ($q) => $q->when($e164, fn ($w) => $w->orWhere('phone_e164', $e164))->when($email, fn ($w) => $w->orWhereRaw('LOWER(email) = ?', [$email])))->first();
        if ($existing) {
            throw BusinessRuleException::make('already_member', (int) $existing->company_id === (int) $company->id ? 'This person is already on your team.' : 'This person already has a '.config('app.name').' account for another business.');
        }
        (new \App\Services\Billing\Quotas())->assertCanAdd($company, 'users');

        $token = Str::random(40);
        $id = DB::table('invites')->insertGetId([
            'company_id' => $company->id, 'token_hash' => hash('sha256', $token), 'name' => $name, 'phone_e164' => $e164, 'email' => $email, 'role' => $role,
            'invited_by_id' => $by->id, 'status' => 'pending', 'expires_at' => now()->addDays(self::INVITE_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invite = DB::table('invites')->find($id);
        $link = $this->send($invite, $token, $by, $company);

        return ['invite' => $invite, 'link' => $link];
    }

    public function resend(object $invite, User $by): string
    {
        if ($invite->status !== 'pending') {
            throw BusinessRuleException::make('invite_closed', 'This invite is no longer open.');
        }
        $token = Str::random(40);
        DB::table('invites')->where('id', $invite->id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::INVITE_DAYS), 'updated_at' => now()]);

        return $this->send(DB::table('invites')->find($invite->id), $token, $by, Company::withoutGlobalScopes()->find($invite->company_id));
    }

    private function send(object $invite, string $token, User $by, Company $company): string
    {
        $link = self::inviteBase().'/invite/'.$token;
        $label = config("permissions.roles.{$invite->role}.label");
        $text = "{$by->name} invited you to join {$company->name} on ".config('app.name')." as {$label}. Accept here: {$link} (valid ".self::INVITE_DAYS.' days)';
        $to = $invite->phone_e164 ?: $invite->email;
        app(Messenger::class)->send($to, $text, Messenger::channelsFor($invite->phone_e164, $invite->email), ['company_id' => $company->id, 'user_id' => $by->id, 'purpose' => 'invite',
            'options' => ['mail' => ['subject' => "Join {$company->name} on ".config('app.name')]]]);
        DB::table('invites')->where('id', $invite->id)->increment('sent_count');

        return $link;
    }

    public static function findOpen(string $token): ?object
    {
        $invite = DB::table('invites')->where('token_hash', hash('sha256', $token))->first();

        return $invite && $invite->status === 'pending' && now()->lessThan($invite->expires_at) ? $invite : null;
    }

    public function accept(string $token, string $firstName, string $lastName, string $password): User
    {
        return DB::transaction(function () use ($token, $firstName, $lastName, $password) {
            $invite = DB::table('invites')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $invite || $invite->status !== 'pending' || now()->greaterThan($invite->expires_at)) {
                throw BusinessRuleException::make('invite_invalid', 'This invite link has expired or was already used. Ask for a new one.');
            }
            if (strlen($password) < 6) {
                throw BusinessRuleException::make('weak_password', 'Password must be at least 6 characters.');
            }
            $user = new User();
            $user->first_name = trim($firstName);
            $user->last_name = trim($lastName);
            $user->name = trim($firstName.' '.$lastName);
            $user->email = $invite->email;
            $user->phone_e164 = $invite->phone_e164;
            $user->phone_number = $invite->phone_e164;
            $user->username = $invite->email ?: $invite->phone_e164;
            $user->password = Hash::make($password);
            $user->company_id = $invite->company_id;
            $user->status = 'Active';
            if ($invite->phone_e164) {
                $user->phone_verified_at = now(); // they received the link on this phone
            } else {
                $user->email_verified_at = now();
            }
            $user->save();
            DB::table('company_members')->updateOrInsert(['company_id' => $invite->company_id, 'user_id' => $user->id],
                ['role' => $invite->role, 'status' => 'active', 'invited_by_id' => $invite->invited_by_id, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->syncAdminRole($user, $invite->role);
            DB::table('invites')->where('id', $invite->id)->update(['status' => 'accepted', 'accepted_user_id' => $user->id, 'accepted_at' => now(), 'updated_at' => now()]);
            app(\App\Services\Notifications\Notifier::class)->notify((int) $invite->company_id, 'team', 'New team member', "{$user->name} joined as ".config("permissions.roles.{$invite->role}.label").'.');

            return $user;
        });
    }

    /**
     * The invitee already has an account (made after the invite was sent, or left over from a shop
     * they were removed from): join it to the inviting shop instead of creating a second account.
     * Same one-shop-per-account rule as invite(): an owner, or an active member of another shop,
     * cannot be moved.
     */
    public function acceptExisting(string $token, User $user): User
    {
        return DB::transaction(function () use ($token, $user) {
            $invite = DB::table('invites')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $invite || $invite->status !== 'pending' || now()->greaterThan($invite->expires_at)) {
                throw BusinessRuleException::make('invite_invalid', 'This invite link has expired or was already used. Ask for a new one.');
            }
            $matches = ($invite->phone_e164 && $invite->phone_e164 === $user->phone_e164)
                || ($invite->email && strtolower((string) $user->email) === strtolower((string) $invite->email));
            if (! $matches) {
                throw BusinessRuleException::make('invite_other_account', 'This invite was sent to a different phone number or email.');
            }
            $companyId = (int) $invite->company_id;
            $ownsOther = Company::withoutGlobalScopes()->where('owner_id', $user->id)->where('id', '!=', $companyId)->exists();
            // Still working somewhere else: linked to another shop that exists and was not removed from it
            // (staff from before membership rows have none, and count as working there).
            $activeElsewhere = $user->company_id && (int) $user->company_id !== $companyId
                && Company::withoutGlobalScopes()->whereKey($user->company_id)->exists()
                && ! DB::table('company_members')->where('company_id', $user->company_id)->where('user_id', $user->id)->where('status', '!=', 'active')->exists();
            if ($ownsOther || $activeElsewhere) {
                throw BusinessRuleException::make('already_member', 'This account already belongs to another business, so it cannot join this one. Ask for an invite to a different phone number or email.');
            }
            $user->company_id = $companyId;
            $user->status = 'Active';
            $col = $invite->phone_e164 && $invite->phone_e164 === $user->phone_e164 ? 'phone_verified_at' : 'email_verified_at';
            $user->{$col} ??= now(); // they received the link there
            $user->save();
            DB::table('company_members')->updateOrInsert(['company_id' => $companyId, 'user_id' => $user->id],
                ['role' => $invite->role, 'status' => 'active', 'invited_by_id' => $invite->invited_by_id, 'joined_at' => now(), 'deactivated_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->syncAdminRole($user, $invite->role);
            DB::table('invites')->where('id', $invite->id)->update(['status' => 'accepted', 'accepted_user_id' => $user->id, 'accepted_at' => now(), 'updated_at' => now()]);
            Permissions::flush();
            app(\App\Services\Notifications\Notifier::class)->notify($companyId, 'team', 'New team member', "{$user->name} joined as ".config("permissions.roles.{$invite->role}.label").'.');

            return $user;
        });
    }

    /** Where invite links open: the new shop interface when configured, else budget-pro's own page. */
    public static function inviteBase(): string
    {
        return rtrim((string) (config('saas.invite_url') ?: config('saas.public_url', config('app.url'))), '/');
    }

    public function setRole(Company $company, User $member, string $role): void
    {
        if ((int) $company->owner_id === (int) $member->id) {
            throw BusinessRuleException::make('owner_role', 'Transfer ownership to change the owner’s role.');
        }
        if ($role === 'owner' || ! in_array($role, self::roles(), true)) {
            throw BusinessRuleException::make('invalid_role', 'Unknown role.');
        }
        DB::table('company_members')->updateOrInsert(['company_id' => $company->id, 'user_id' => $member->id], ['role' => $role, 'status' => 'active', 'updated_at' => now()]);
        $this->syncAdminRole($member, $role);
        Permissions::flush();
    }

    public function setActive(Company $company, User $member, bool $active): void
    {
        if ((int) $company->owner_id === (int) $member->id) {
            throw BusinessRuleException::make('owner_role', 'The owner cannot be deactivated.');
        }
        DB::table('company_members')->where('company_id', $company->id)->where('user_id', $member->id)
            ->update(['status' => $active ? 'active' : 'inactive', 'deactivated_at' => $active ? null : now(), 'updated_at' => now()]);
        $member->status = $active ? 'Active' : 'Inactive';
        $member->save();
        if (! $active) {
            $member->tokens()->delete(); // signed out on every phone
        }
        Permissions::flush();
    }

    public function transferOwnership(Company $company, User $owner, User $to, string $password): void
    {
        if (! Hash::check($password, $owner->password)) {
            throw BusinessRuleException::make('wrong_password', 'Your password is not correct.');
        }
        if ((int) $to->company_id !== (int) $company->id || $to->id === $owner->id) {
            throw BusinessRuleException::make('invalid_member', 'Choose another member of this business.');
        }
        DB::transaction(function () use ($company, $owner, $to) {
            $company->owner_id = $to->id;
            $company->saveQuietly();
            DB::table('company_members')->updateOrInsert(['company_id' => $company->id, 'user_id' => $to->id], ['role' => 'owner', 'status' => 'active', 'updated_at' => now()]);
            DB::table('company_members')->updateOrInsert(['company_id' => $company->id, 'user_id' => $owner->id], ['role' => 'manager', 'status' => 'active', 'updated_at' => now()]);
            $this->syncAdminRole($to, 'owner');
            $this->syncAdminRole($owner, 'manager');
        });
        Permissions::flush();
    }

    /**
     * Every role with the permissions it has in this company (defaults plus the company's overrides).
     *
     * @return list<array{key: string, label: string, permissions: array<int, string>}>
     */
    public static function roleList(Company $company): array
    {
        $out = [];
        foreach (config('permissions.roles') as $key => $r) {
            // One query for every role's overrides, shared with Permissions (plan A4).
            $out[] = ['key' => $key, 'label' => $r['label'], 'permissions' => Permissions::forRole((int) $company->id, $key)];
        }

        return $out;
    }

    /**
     * This company's version of a role: stores only the differences from the role's defaults.
     * Billing, team and settings stay owner-only whatever is asked.
     *
     * @param  array<int, string>  $permissions
     */
    public function setRolePermissions(Company $company, string $role, array $permissions): void
    {
        if ($role === 'owner' || ! array_key_exists($role, config('permissions.roles'))) {
            throw BusinessRuleException::make('invalid_role', 'This role cannot be changed.');
        }
        $defaults = Permissions::defaultsFor($role);
        $wanted = array_diff($permissions, \App\Support\Rules\TeamRules::OWNER_ONLY);
        DB::transaction(function () use ($company, $role, $defaults, $wanted) {
            DB::table('company_role_permissions')->where('company_id', $company->id)->where('role', $role)->delete();
            foreach (Permissions::all() as $p) {
                $want = in_array($p, $wanted, true);
                if ($want !== in_array($p, $defaults, true)) {
                    DB::table('company_role_permissions')->insert(['company_id' => $company->id, 'role' => $role, 'permission' => $p, 'allowed' => $want, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        Permissions::flush();
    }

    /** Cancel a pending invite of this company; false when there is none. */
    public function revokeInvite(Company $company, int $inviteId): bool
    {
        return DB::table('invites')->where('company_id', $company->id)->where('id', $inviteId)->where('status', 'pending')->update(['status' => 'revoked', 'updated_at' => now()]) > 0;
    }

    /** Keep the web admin role (menus, access) in line with the company role. */
    public function syncAdminRole(User $user, string $role): void
    {
        $slugs = array_values(config('permissions.admin_roles'));
        $ids = DB::table('admin_roles')->whereIn('slug', array_merge($slugs, ['worker']))->pluck('id');
        DB::table('admin_role_users')->where('user_id', $user->id)->whereIn('role_id', $ids)->delete();
        $slug = config("permissions.admin_roles.{$role}");
        if (! DB::table('admin_roles')->where('slug', $slug)->exists() && $slug !== 'company') {
            \App\Support\AdminAccess::ensureShopRoles();
        }
        $target = DB::table('admin_roles')->where('slug', $slug)->value('id');
        if ($target) {
            DB::table('admin_role_users')->insert(['role_id' => $target, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
