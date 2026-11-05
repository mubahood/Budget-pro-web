<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\AccountLookup;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Someone with a shop who also lets property: rent books beside their shop, an account of its own, and one sign-in. The app they
 * sign in to decides which account opens.
 */
class RentalsSiblingAccountTest extends RentalsPhoneApiCase
{
    private const OWNER_PASS = 'Shop-Owner-2026!x';

    /** @return array{0: User, 1: Company, 2: array<string, string>} */
    private function shopOwner(string $email = 'sib.owner@example.com'): array
    {
        SiblingAccounts::forgetReadiness();
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Sample', 'email' => $email, 'password' => self::OWNER_PASS, 'company_name' => 'Sample Shop',
            'currency' => 'UGX', 'country' => 'UG', 'business_type' => 'retail', 'modules' => ['shop', 'finance']]);
        $u = $r['user']->fresh();

        return [$u, $r['company']->fresh(), $this->auth($u->createToken('t')->plainTextToken)];
    }

    private function open(array $headers, string $name = 'Sample Lets'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => $name, 'device_name' => 'test'], $headers);
    }

    public function test_opening_gives_rent_books_of_their_own_and_a_sign_in(): void
    {
        [$owner, $shop, $h] = $this->shopOwner();
        $before = $shop->modules();

        $d = $this->open($h)->assertOk()->json('data');
        $this->assertNotEmpty($d['token']);
        $this->assertSame('Sample Lets', $d['company']['name']);

        $new = Company::withoutGlobalScopes()->findOrFail($d['company']['id']);
        $this->assertNotSame((int) $shop->id, (int) $new->id);
        $this->assertSame(['rentals'], $new->modules(), 'nothing else is in the rent books');
        $this->assertSame($before, $shop->fresh()->modules(), 'the shop is not touched');
        $this->assertSame((string) $shop->currency, (string) $new->currency);
        $this->assertSame($owner->email, $new->email, 'mail about the account reaches the person');

        // The new token opens the rent books, and the old one ended.
        $this->getJson('/api/v1/rentals/overview', $this->auth($d['token']))->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', $h)->assertStatus(401);
    }

    public function test_asking_twice_never_makes_two(): void
    {
        [$owner, , $h] = $this->shopOwner();
        $first = $this->open($h)->assertOk()->json('data.company.id');
        $again = $this->open($this->auth($owner->createToken('t2')->plainTextToken), 'Another Name')->assertOk()->json('data.company.id');
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('account_siblings')->where('user_id', $owner->id)->count());
    }

    public function test_the_same_sign_in_opens_each_account_in_its_own_app(): void
    {
        [$owner, $shop, $h] = $this->shopOwner();
        $rent = $this->open($h)->assertOk()->json('data.company.id');

        $in = fn (?string $app) => $this->postJson('/api/v1/auth/login', array_filter(['identifier' => $owner->email, 'password' => self::OWNER_PASS, 'device_name' => 'x', 'app' => $app]))->assertOk()->json('data.company.id');
        $this->assertSame($rent, $in('estate-ledger'));
        $this->assertSame((int) $shop->id, (int) $in('budget-pro'));
        $this->assertSame((int) $shop->id, (int) $in(null), 'an older app with no name of its own keeps the first account');
        $this->assertSame((int) $shop->id, (int) $in('nonsense'));
    }

    public function test_someone_with_no_second_account_signs_in_as_always(): void
    {
        [$owner, $shop] = $this->shopOwner();
        $id = $this->postJson('/api/v1/auth/login', ['identifier' => $owner->email, 'password' => self::OWNER_PASS, 'device_name' => 'x', 'app' => 'estate-ledger'])->assertOk()->json('data.company.id');
        $this->assertSame((int) $shop->id, (int) $id);
    }

    public function test_the_second_login_is_never_signed_in_to_directly(): void
    {
        [$owner, , $h] = $this->shopOwner();
        $d = $this->open($h)->assertOk()->json('data');
        $sib = User::withoutGlobalScopes()->findOrFail($d['user']['id']);

        $this->assertNull($sib->email);
        $this->assertNull(AccountLookup::find($sib->username));
        $this->assertSame((int) $owner->id, (int) AccountLookup::find($owner->email)->id, 'the person is still found as themselves');
        $this->postJson('/api/v1/auth/login', ['identifier' => $sib->username, 'password' => self::OWNER_PASS, 'device_name' => 'x'])->assertStatus(422);
    }

    public function test_the_person_is_shown_as_themselves_and_their_contact_stays_theirs(): void
    {
        [$owner, , $h] = $this->shopOwner();
        $d = $this->open($h)->assertOk()->json('data');
        $this->assertSame($owner->email, $d['user']['email'], 'never blank, never "null", on their own page');

        // An app sending back what it was shown, with a new name: the name changes, the contact stays on the person's own login.
        $this->putJson('/api/v1/auth/profile', ['first_name' => 'Asha B', 'email' => $owner->email], $this->auth($d['token']))->assertOk();
        $sib = User::withoutGlobalScopes()->findOrFail($d['user']['id']);
        $this->assertSame('Asha B', $sib->first_name);
        $this->assertNull($sib->email);
        $this->assertSame($owner->email, $owner->fresh()->email);
    }

    public function test_one_password_whichever_account_changed_it(): void
    {
        [$owner, , $h] = $this->shopOwner();
        $d = $this->open($h)->assertOk()->json('data');

        // Changed inside the rent books: the person's own sign-in follows.
        $this->putJson('/api/v1/auth/password', ['current_password' => self::OWNER_PASS, 'new_password' => 'New-Pass-2026!zz', 'new_password_confirmation' => 'New-Pass-2026!zz'], $this->auth($d['token']))->assertOk();
        $this->assertTrue(Hash::check('New-Pass-2026!zz', $owner->fresh()->password));
        $this->postJson('/api/v1/auth/login', ['identifier' => $owner->email, 'password' => 'New-Pass-2026!zz', 'device_name' => 'x', 'app' => 'estate-ledger'])->assertOk();

        // Changed on the first account: the rent books follow.
        $fresh = $owner->fresh();
        $fresh->password = Hash::make('Third-Pass-2026!q');
        $fresh->save();
        $this->assertTrue(Hash::check('Third-Pass-2026!q', User::withoutGlobalScopes()->findOrFail($d['user']['id'])->password));
    }

    public function test_it_is_only_for_those_it_can_help(): void
    {
        [, , $h] = $this->shopOwner();
        $this->postJson('/api/v1/auth/sibling', ['app' => 'budget-pro', 'company_name' => 'X'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'app_not_offered');
        $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => ''], $h)->assertStatus(422);
        $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => 'X'])->assertStatus(401);

        // An account that already keeps rent books needs no second.
        [, $owner] = $this->rentalsAccount();
        $this->postJson('/api/v1/auth/sibling', ['app' => 'estate-ledger', 'company_name' => 'X'], $this->h($owner))->assertStatus(422)->assertJsonPath('errors.code', 'already_has_it');
    }
}
