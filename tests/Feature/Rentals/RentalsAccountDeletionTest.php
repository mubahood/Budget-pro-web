<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalLogin;
use App\Models\User;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use App\Services\Ops\TenantDataService;
use App\Services\Rentals\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Deleting an account really deletes it, as the public page says, and leaves anything else the person has working.
 */
class RentalsAccountDeletionTest extends RentalsPhoneApiCase
{
    private const SECRET = 'Secret-12345!';

    /** @return array{0: User, 1: Company, 2: array<string, string>} */
    private function owner(string $email, array $modules = ['rentals']): array
    {
        SiblingAccounts::forgetReadiness();
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Sample', 'email' => $email, 'password' => self::SECRET,
            'company_name' => 'Sample '.uniqid(), 'currency' => 'UGX', 'country' => 'UG', 'business_type' => $modules[0], 'modules' => $modules]);
        $u = $r['user']->fresh();

        return [$u, $r['company']->fresh(), $this->auth($u->createToken('t')->plainTextToken)];
    }

    /** Who Google says the person is. */
    private string $googleSays = '';

    private function google(string $email): void
    {
        config(['services.google.web_client_id' => 'client.apps.googleusercontent.com']);
        $this->googleSays = $email;
        Http::fake(['oauth2.googleapis.com/*' => fn () => Http::response(['email' => $this->googleSays, 'email_verified' => 'true', 'aud' => 'client.apps.googleusercontent.com',
            'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600), 'given_name' => 'Asha', 'family_name' => 'Sample'])]);
    }

    public function test_the_account_s_files_and_its_people_s_sign_ins_go_with_it(): void
    {
        [, $company] = $this->owner('del.files@example.com');
        [, $other] = $this->owner('del.other@example.com');
        $id = (int) $company->id;
        PrivateFiles::put("files/{$id}/2026/11/paper.pdf", 'x');
        PrivateFiles::put("files/{$other->id}/2026/11/paper.pdf", 'x');
        $root = PrivateFiles::root();
        $loginId = (int) DB::table('rental_logins')->insertGetId(['company_id' => $id, 'subject_type' => 'tenant', 'subject_id' => 1, 'phone_e164' => '+256700111222', 'created_at' => now(), 'updated_at' => now()]);
        RentalLogin::withoutGlobalScopes()->findOrFail($loginId)->createToken('phone');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_type', RentalLogin::class)->where('tokenable_id', $loginId)->count());

        try {
            app(TenantDataService::class)->purge($id);

            $this->assertFalse(File::exists("{$root}/files/{$id}"), 'the private files are gone');
            $this->assertTrue(File::exists("{$root}/files/{$other->id}/2026/11/paper.pdf"), "another account's stay");
            $this->assertSame(0, DB::table('rental_logins')->where('id', $loginId)->count());
            $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_type', RentalLogin::class)->where('tokenable_id', $loginId)->count(), 'a tenant phone sign-in ends with it');
        } finally {
            File::deleteDirectory("{$root}/files/{$other->id}");
        }
    }

    public function test_deleting_the_first_account_leaves_the_person_a_way_into_the_rent_books_beside_it(): void
    {
        [$first, $shop, $h] = $this->owner('del.first@example.com', ['shop']);
        $rent = $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => 'Sample Lets', 'device_name' => 'test'], $h)->assertSuccessful()->json('data.user.company_id');

        app(TenantDataService::class)->purge((int) $shop->id);

        $this->assertSame(0, DB::table('admin_users')->where('id', $first->id)->count());
        $this->assertSame(0, DB::table('account_siblings')->where('user_id', $first->id)->count());
        $this->postJson('/api/v1/auth/login', ['identifier' => 'del.first@example.com', 'password' => self::SECRET, 'app' => 'estate-ledger', 'device_name' => 'test'])
            ->assertOk()->assertJsonPath('data.user.company_id', $rent);
        $this->postJson('/api/v1/auth/login', ['identifier' => 'del.first@example.com', 'password' => self::SECRET, 'device_name' => 'test'])
            ->assertOk()->assertJsonPath('data.user.company_id', $rent);
    }

    public function test_deleting_the_rent_books_leaves_the_first_account_and_they_can_open_new_ones(): void
    {
        [$first, $shop, $h] = $this->owner('del.rent@example.com', ['shop']);
        $rent = $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => 'Sample Lets', 'device_name' => 'test'], $h)->assertSuccessful()->json('data.user.company_id');

        app(TenantDataService::class)->purge((int) $rent);

        $this->assertSame((int) $shop->id, (int) DB::table('admin_users')->where('id', $first->id)->value('company_id'));
        $in = $this->postJson('/api/v1/auth/login', ['identifier' => 'del.rent@example.com', 'password' => self::SECRET, 'app' => 'estate-ledger', 'device_name' => 'test'])
            ->assertOk()->assertJsonPath('data.user.company_id', (int) $shop->id);
        $again = $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => 'Sample Lets Two', 'device_name' => 'test'], $this->auth($in->json('data.token')))->assertSuccessful()->json('data.user.company_id');
        $this->assertNotSame((int) $rent, (int) $again);
    }

    public function test_someone_who_signed_up_with_google_can_confirm_with_google_and_nobody_else_can(): void
    {
        [$owner, $company, $h] = $this->owner('del.google@example.com');
        $this->postJson('/api/v1/company/delete', [], $h)->assertStatus(422);

        $this->google('someone.else@example.com');
        $this->postJson('/api/v1/company/delete', ['google_token' => 'tok'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'wrong_google');
        $this->assertSame(0, DB::table('data_requests')->where('company_id', $company->id)->count());

        $this->googleSays = 'del.google@example.com';
        $this->postJson('/api/v1/company/delete', ['google_token' => 'tok'], $h)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame(1, DB::table('data_requests')->where('company_id', $company->id)->where('kind', 'delete')->count());
        $this->assertSame($owner->id, (int) DB::table('data_requests')->where('company_id', $company->id)->value('requested_by_id'));
    }

    public function test_staff_cannot_ask_even_with_the_owner_s_google(): void
    {
        [, $company] = $this->owner('del.team@example.com');
        $staff = User::factory()->create(['company_id' => $company->id]);
        $this->google('del.team@example.com');
        $this->postJson('/api/v1/company/delete', ['google_token' => 'tok'], $this->h($staff))->assertForbidden();
    }
}
