<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RedactedOperationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Setting a password on the classic screens signs that person out of the phone app (P0-2, found), and no
 * password reaches laravel-admin's operation log, old rows included.
 */
class RevocationAndOperationLogTest extends AdminTestCase
{
    public function test_a_new_password_on_the_settings_page_revokes_the_phone_tokens_and_is_not_logged(): void
    {
        $t = $this->makeTenant('company');
        $t['user']->createToken('phone', ['*'], now()->addDay());
        $this->assertSame(1, $t['user']->tokens()->count());

        $this->asAdmin($t['user'])->put('/auth/setting', ['name' => 'Owner', 'password' => 'changed2026', 'password_confirmation' => 'changed2026'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('changed2026', $t['user']->fresh()->password));
        $this->assertSame(0, $t['user']->tokens()->count());
        $row = DB::table('admin_operation_log')->where('user_id', $t['user']->id)->where('path', 'auth/setting')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('changed2026', $row->input);
        $this->assertSame(RedactedOperationLog::MASK, json_decode($row->input, true)['password']);
        $this->assertSame('Owner', json_decode($row->input, true)['name'], 'the rest of the input is kept');
    }

    public function test_a_new_password_on_the_employees_form_revokes_that_persons_tokens_only(): void
    {
        $t = $this->makeTenant('company');
        $email = 'emp_'.uniqid().'@example.test';
        $payload = ['first_name' => 'Emp', 'last_name' => 'Loyee', 'sex' => 'Female', 'email' => $email, 'phone_number' => '+256700000003',
            'password' => 'cashier2026', 'password_confirmation' => 'cashier2026', 'team_role' => 'cashier', 'status' => 'Active'];
        $this->asAdmin($t['user'])->post('/employees', $payload)->assertRedirect();
        $worker = User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
        $worker->createToken('phone', ['*'], now()->addDay());
        $t['user']->createToken('owner-phone', ['*'], now()->addDay());

        // An edit that leaves the password blank keeps the tokens.
        $this->asAdmin($t['user'])->put('/employees/'.$worker->id, array_merge($payload, ['first_name' => 'Renamed', 'password' => '', 'password_confirmation' => '']))->assertRedirect();
        $this->assertSame(1, $worker->tokens()->count());

        $this->asAdmin($t['user'])->put('/employees/'.$worker->id, array_merge($payload, ['password' => 'newcash2026', 'password_confirmation' => 'newcash2026']))->assertRedirect();
        $this->assertTrue(Hash::check('newcash2026', $worker->fresh()->password));
        $this->assertSame(0, $worker->tokens()->count());
        $this->assertSame(1, $t['user']->tokens()->count(), 'the owner stays signed in');
        $this->assertSame(0, DB::table('admin_operation_log')->where('user_id', $t['user']->id)->where('input', 'like', '%2026%')->count());
    }

    public function test_the_migration_redacts_rows_already_in_the_log(): void
    {
        $t = $this->makeTenant('company');
        $json = DB::table('admin_operation_log')->insertGetId(['user_id' => $t['user']->id, 'path' => 'auth/setting', 'method' => 'PUT', 'ip' => '127.0.0.1',
            'input' => json_encode(['name' => 'Owner', 'password' => 'plain-secret-1', 'password_confirmation' => 'plain-secret-1', '_token' => 'csrf', 'nested' => ['pin' => '1234', 'note' => 'keep']]),
            'created_at' => now(), 'updated_at' => now()]);
        $broken = DB::table('admin_operation_log')->insertGetId(['user_id' => $t['user']->id, 'path' => 'employees', 'method' => 'POST', 'ip' => '127.0.0.1',
            'input' => '{"first_name":"Emp","password":"plain-secret-2","new_password":"plain-secret-3","cut short', 'created_at' => now(), 'updated_at' => now()]);
        $clean = DB::table('admin_operation_log')->insertGetId(['user_id' => $t['user']->id, 'path' => 'customers', 'method' => 'POST', 'ip' => '127.0.0.1',
            'input' => '{"name":"Pinto Traders"}', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_10_17_420003_redact_secrets_in_admin_operation_log.php'))->up();

        $row = json_decode((string) DB::table('admin_operation_log')->where('id', $json)->value('input'), true);
        $this->assertSame(['name' => 'Owner', 'password' => '[redacted]', 'password_confirmation' => '[redacted]', '_token' => '[redacted]', 'nested' => ['pin' => '[redacted]', 'note' => 'keep']], $row);
        $text = (string) DB::table('admin_operation_log')->where('id', $broken)->value('input');
        $this->assertStringNotContainsString('plain-secret', $text);
        $this->assertStringContainsString('"first_name":"Emp"', $text);
        $this->assertSame('{"name":"Pinto Traders"}', DB::table('admin_operation_log')->where('id', $clean)->value('input'));
    }
}
