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
        $link = rtrim((string) config('app.url'), '/').'/invite/'.$token;
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
