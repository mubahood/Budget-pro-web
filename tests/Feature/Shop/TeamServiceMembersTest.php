<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\AdminTestCase;

/** TeamService::createMember / setPassword / updateDetails: the classic Employees form (EmployeesController:111-161) as a service. */
class TeamServiceMembersTest extends AdminTestCase
{
    private function refused(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_member_is_added_with_a_login_and_password_through_set_role(): void
    {
        $t = $this->makeTenant('company');
        $company = $t['company'];
        $u = app(TeamService::class)->createMember($company, $t['user'], 'Grace Nakato', 'Grace@Example.test', '0772 404 101', 'secret-pass', 'cashier');

        $this->assertSame('Grace', $u->first_name);
        $this->assertSame('Nakato', $u->last_name);
        $this->assertSame('Grace Nakato', $u->name);
        $this->assertSame('grace@example.test', $u->email);
        $this->assertSame('+256772404101', $u->phone_e164);
        $this->assertSame('grace@example.test', $u->username);
        $this->assertSame((int) $company->id, (int) $u->company_id);
        $this->assertTrue(Hash::check('secret-pass', $u->password));
        $m = DB::table('company_members')->where('company_id', $company->id)->where('user_id', $u->id)->first();
        $this->assertSame(['cashier', 'active', (int) $t['user']->id], [$m->role, $m->status, (int) $m->invited_by_id]);
        Permissions::flush();
        $this->assertSame('cashier', Permissions::roleOf($u));
        $this->assertTrue(DB::table('admin_role_users')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')
            ->where('user_id', $u->id)->where('slug', config('permissions.admin_roles.cashier'))->exists(), 'the web admin role follows, as with setRole');

        // Phone-only works too, and signs in with the phone.
        $p = app(TeamService::class)->createMember($company, $t['user'], 'Peter', null, '0772404102', 'secret-pass', 'stock_keeper');
        $this->assertSame('+256772404102', $p->username);
        $this->assertSame((int) $p->id, (int) \App\Services\Auth\AccountLookup::find('0772404102')?->id);
    }

    public function test_create_member_refusals_and_the_seat_limit(): void
    {
        $t = $this->makeTenant('company');
        $s = app(TeamService::class);
        $c = $t['company'];
        $this->assertSame('invalid_role', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', 'a@x.test', null, 'secret-pass', 'owner')));
        $this->assertSame('contact_required', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', null, null, 'secret-pass', 'cashier')));
        $this->assertSame('invalid_phone', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', null, '12', 'secret-pass', 'cashier')));
        $this->assertSame('weak_password', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', 'a@x.test', null, 'short', 'cashier')));
        $this->assertSame('already_member', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', strtoupper($t['user']->email), null, 'secret-pass', 'cashier')));

        $plan = Plan::query()->create(['name' => 'Tiny', 'slug' => 'tiny-'.uniqid(), 'price' => 0, 'limits' => ['max_users' => 1], 'features' => [], 'is_active' => true]);
        Subscription::create(['company_id' => $c->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $c = Company::withoutGlobalScopes()->find($c->id);
        $this->assertSame('plan_limit_reached', $this->refused(fn () => $s->createMember($c, $t['user'], 'A', 'a@x.test', null, 'secret-pass', 'cashier')));
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'a@x.test')->count());
    }

    public function test_set_password_hashes_and_signs_out_everywhere_and_protects_the_owner(): void
    {
        $t = $this->makeTenant('company');
        $s = app(TeamService::class);
        $c = $t['company'];
        $m = $s->createMember($c, $t['user'], 'Mary Achan', 'mary@x.test', null, 'secret-pass', 'manager');
        $m->createToken('phone');
        $s->setPassword($c, $t['user'], $m, 'new-password-1');
        $this->assertTrue(Hash::check('new-password-1', $m->fresh()->password));
        $this->assertSame(0, $m->tokens()->count(), 'signed out on every phone');

        // Nobody but the owner changes the owner's password; the owner may.
        $this->assertSame('owner_password', $this->refused(fn () => $s->setPassword($c, $m, $t['user'], 'hijack-pass')));
        $this->assertTrue(Hash::check('secret123', $t['user']->fresh()->password));
        $s->setPassword($c, $t['user'], $t['user'], 'owner-new-pass');
        $this->assertTrue(Hash::check('owner-new-pass', $t['user']->fresh()->password));

        $this->assertSame('weak_password', $this->refused(fn () => $s->setPassword($c, $t['user'], $m, 'short')));
        $other = $this->makeTenant('company');
        $this->assertSame('invalid_member', $this->refused(fn () => $s->setPassword($c, $t['user'], $other['user'], 'secret-pass')));
    }

    public function test_update_details_keeps_logins_unique_and_the_owner_protected(): void
    {
        $t = $this->makeTenant('company');
        $s = app(TeamService::class);
        $c = $t['company'];
        $m = $s->createMember($c, $t['user'], 'Tom', null, '0772404201', 'secret-pass', 'cashier');
        $u = $s->updateDetails($c, $t['user'], $m, ['name' => 'Tom Okello', 'email' => 'TOM@x.test', 'phone' => '0772404202', 'phone_2' => '0700 111 222', 'address' => 'Kikuubo']);
        $this->assertSame(['Tom', 'Okello', 'Tom Okello', 'tom@x.test', '+256772404202', '0700 111 222', 'Kikuubo'],
            [$u->first_name, $u->last_name, $u->name, $u->email, $u->phone_e164, $u->phone_number_2, $u->address]);

        $this->assertSame('already_member', $this->refused(fn () => $s->updateDetails($c, $t['user'], $m, ['name' => 'Tom', 'email' => $t['user']->email])));
        $this->assertSame('contact_required', $this->refused(fn () => $s->updateDetails($c, $t['user'], $m, ['name' => 'Tom', 'email' => null, 'phone' => null])));
        $this->assertSame('owner_details', $this->refused(fn () => $s->updateDetails($c, $m, $t['user'], ['name' => 'X', 'email' => 'x@x.test'])));
        $this->assertNotSame('x@x.test', $t['user']->fresh()->email);
    }
}
