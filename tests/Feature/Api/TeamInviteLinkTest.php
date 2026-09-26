<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Illuminate\Support\Facades\DB;

/** Where invite links open (saas.invite_url) and joining a shop with an account that already exists. */
class TeamInviteLinkTest extends ApiTestCase
{
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->owner = $this->registerTenant();
    }

    private function invite(array $body): array
    {
        return $this->postJson('/api/v1/team/invites', $body, $this->auth($this->owner['token']))->assertStatus(201)->json('data');
    }

    public function test_links_use_the_invite_url_when_set_and_the_public_url_otherwise(): void
    {
        config(['saas.invite_url' => '', 'saas.public_url' => 'https://classic.example']);
        $this->assertStringStartsWith('https://classic.example/invite/', $this->invite(['role' => 'cashier', 'email' => 'a@example.com'])['link']);

        config(['saas.invite_url' => 'https://shop.example/']);
        $link = $this->invite(['role' => 'cashier', 'email' => 'b@example.com'])['link'];
        $this->assertStringStartsWith('https://shop.example/invite/', $link);
        $this->assertStringContainsString('https://shop.example/invite/', (string) DB::table('message_log')->where('to', 'b@example.com')->value('body'));
        $this->assertNotNull(TeamService::findOpen(basename($link)));
    }

    public function test_an_existing_account_without_a_shop_joins_instead_of_getting_a_second_account(): void
    {
        $token = basename($this->invite(['role' => 'manager', 'email' => 'late@example.com'])['link']);
        $u = new User();
        $u->forceFill(['name' => 'Late Comer', 'first_name' => 'Late', 'last_name' => 'Comer', 'email' => 'late@example.com', 'username' => 'late@example.com',
            'password' => bcrypt('secret123'), 'status' => 'Active'])->save();

        $joined = app(TeamService::class)->acceptExisting($token, $u);
        Permissions::flush();

        $this->assertSame((int) $this->owner['company_id'], (int) $joined->company_id);
        $this->assertSame('manager', Permissions::roleOf($joined->fresh()));
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'late@example.com')->count());
        $this->assertSame('accepted', DB::table('invites')->where('token_hash', hash('sha256', $token))->value('status'));
        $this->assertNull(TeamService::findOpen($token));
    }

    public function test_owners_other_members_and_other_people_cannot_take_an_invite(): void
    {
        $other = $this->registerTenant(['email' => 'boss@example.com']);
        $token = basename($this->invite(['role' => 'cashier', 'email' => 'someone@example.com'])['link']);
        $boss = User::withoutGlobalScopes()->find($other['user_id']);

        try {
            app(TeamService::class)->acceptExisting($token, $boss);
            $this->fail('another account took the invite');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invite_other_account', $e->errorCode());
        }

        // The invite names the owner of another shop (the account appeared after the invite was sent).
        DB::table('invites')->where('token_hash', hash('sha256', $token))->update(['email' => 'boss@example.com']);
        try {
            app(TeamService::class)->acceptExisting($token, $boss);
            $this->fail('an owner joined another shop');
        } catch (BusinessRuleException $e) {
            $this->assertSame('already_member', $e->errorCode());
        }
        $this->assertSame((int) $other['company_id'], (int) $boss->fresh()->company_id);
        $this->assertNotNull(TeamService::findOpen($token));
    }
}
