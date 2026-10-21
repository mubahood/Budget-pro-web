<?php

namespace Tests\Feature\Sacco\Simple;

use App\Services\Sacco\Members\MemberLogin;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/** The app signs a member in with a password: one membership gives a token at once, several a ticket to pick one. */
class MemberPasswordLoginTest extends ApiTestCase
{
    use MakesSimpleSacco;

    public function test_password_sign_in_gives_a_token_or_a_choice(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $m = $this->saver($company, $owner);
        $email = 'amina'.uniqid().'@example.com';
        app(MemberLogin::class)->enable($company, (int) $m->id, $owner, $email, null, 'secret-pass');

        $this->postJson('/api/v1/sacco/member-auth/login', ['login' => $email, 'password' => 'wrong'])->assertStatus(401);
        $r = $this->postJson('/api/v1/sacco/member-auth/login', ['login' => $email, 'password' => 'secret-pass'])->assertCreated();
        $this->getJson('/api/v1/sacco/me', ['Authorization' => 'Bearer '.$r->json('data.token'), 'Accept' => 'application/json'])->assertOk()->assertJsonPath('data.member_id', $m->id);

        [$other, $owner2] = $this->simpleSacco();
        $m2 = $this->saver($other, $owner2);
        app(MemberLogin::class)->enable($other, (int) $m2->id, $owner2, $email, null, 'secret-pass');
        $two = $this->postJson('/api/v1/sacco/member-auth/login', ['login' => $email, 'password' => 'secret-pass'])->assertOk();
        $this->assertCount(2, $two->json('data.memberships'));
        $this->postJson('/api/v1/sacco/member-auth/token', ['company_id' => $other->id, 'member_id' => $m2->id, 'ticket' => $two->json('data.ticket')])
            ->assertCreated()->assertJsonPath('data.member.member_id', $m2->id);
    }
}
