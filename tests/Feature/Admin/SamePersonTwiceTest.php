<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;

/** Same person twice: logins sharing an email or phone, for platform admins only; join-this-app logins are not listed. */
class SamePersonTwiceTest extends AdminTestCase
{
    public function test_shared_phone_and_email_variants_are_listed_and_siblings_are_not(): void
    {
        $admin = $this->makeTenant('admin');
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');
        $c = $this->makeTenant('company');
        $a['company']->forceFill(['name' => 'Nakawa Wedding'])->saveQuietly();
        $b['company']->forceFill(['name' => 'Nakawa Shop'])->saveQuietly();
        $a['user']->forceFill(['phone_e164' => '+256700111222'])->saveQuietly();
        $b['user']->forceFill(['phone_e164' => null, 'phone_number' => '0700 111222'])->saveQuietly();
        DB::table('account_siblings')->insert(['user_id' => $a['user']->id, 'sibling_user_id' => $c['user']->id, 'app' => 'akiba-ledger', 'created_at' => now(), 'updated_at' => now()]);
        $c['user']->forceFill(['phone_number' => '0700111222'])->saveQuietly();

        $page = $this->asAdmin($admin['user'])->get('/same-person-twice')->assertOk()
            ->assertSee('…700111222')->assertSee('Nakawa Wedding')->assertSee('Nakawa Shop')->assertSee('#'.$b['user']->id);
        $page->assertDontSee('#'.$c['user']->id.'<', false);

        $this->asAdmin($a['user'])->get('/same-person-twice')->assertForbidden();
    }
}
