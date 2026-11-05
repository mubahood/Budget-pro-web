<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A person's second account, beside their first. An app whose books stand alone (Estate Ledger: tenants' and landlords' money is
 * never mixed with anything else, KR-16) can't be added to a shop's account as a module, so it gets an account of its own, and the
 * person needs no second email or password: they sign in as themselves, and the app they sign in to decides which account opens.
 *
 * The second account's owner login has no email or phone of its own and can't be signed in to directly (AccountLookup never finds
 * it). Its password follows the first login's, so a question for the current password has the answer the person knows.
 */
final class SiblingAccounts
{
    /** Apps that may be opened this way => the module their accounts are built around. */
    public const APPS = ['estate-ledger' => 'rentals'];

    /** The start of a sibling's username: nothing signs in with one. */
    public const USERNAME_PREFIX = 'sibling-';

    private static ?bool $ready = null;

    /**
     * Opens the account, or finds the one already opened: asking twice (an answer lost on a bad network) never makes two.
     *
     * @return array{user: User, company: Company}
     */
    public function open(User $person, string $app, string $name): array
    {
        $module = self::APPS[$app] ?? throw BusinessRuleException::make('app_not_offered', 'This app does not open a separate account.');
        $person = self::signInOf($person);
        PublicDemo::guardAccount($person, 'opening another account');
        $company = Company::withoutGlobalScopes()->find($person->company_id);
        if ($company === null) {
            throw BusinessRuleException::make('no_company', 'Your account is not linked to a business.');
        }
        if (in_array($module, $company->modules(), true)) {
            throw BusinessRuleException::make('already_has_it', 'This account already has it.');
        }
        $existing = self::forApp($person, $app);
        if ($existing->id !== $person->id) {
            return ['user' => $existing, 'company' => Company::withoutGlobalScopes()->findOrFail($existing->company_id)];
        }
        $name = trim($name);
        if ($name === '') {
            throw BusinessRuleException::make('name_required', 'Name your agency or property.');
        }

        return DB::transaction(function () use ($person, $company, $app, $module, $name) {
            $username = self::USERNAME_PREFIX.$person->id.'-'.$app;
            $made = app(RegistrationService::class)->register([
                'first_name' => $person->first_name ?: $person->name,
                'last_name' => (string) $person->last_name,
                'email' => $username.'@accounts.invalid', // the registration asks for one; it is cleared below and nothing is ever sent to it
                'password' => Str::password(40),
                'company_name' => mb_substr($name, 0, 191),
                'currency' => $company->currency,
                'country' => $company->country,
                'timezone' => $company->timezone,
                'business_type' => $module,
                'modules' => [$module],
            ], RegistrationService::PRODUCT_BUDGET, 'sibling');
            $made['user']->forceFill(['email' => null, 'username' => $username, 'password' => $person->password, 'email_verified_at' => now()])->saveQuietly();
            $made['company']->forceFill(['email' => $person->email, 'phone_number' => $person->phone_e164 ?: $person->phone_number])->saveQuietly();
            DB::table('account_siblings')->insert(['user_id' => $person->id, 'sibling_user_id' => $made['user']->id, 'app' => $app, 'created_at' => now(), 'updated_at' => now()]);

            return $made;
        });
    }

    /** The login that opens [app] for the person who just proved who they are: the account beside theirs if they have one, else theirs. */
    public static function forApp(User $person, ?string $app): User
    {
        if ($app === null || ! isset(self::APPS[$app]) || ! self::ready()) {
            return $person;
        }
        $id = DB::table('account_siblings')->where('user_id', $person->id)->where('app', $app)->value('sibling_user_id');

        return ($id === null ? null : User::withoutGlobalScopes()->find($id)) ?? $person;
    }

    /** The login a person signs in with, for a question only they can answer. */
    public static function signInOf(User $user): User
    {
        if (! self::ready()) {
            return $user;
        }
        $id = DB::table('account_siblings')->where('sibling_user_id', $user->id)->value('user_id');

        return ($id === null ? null : User::withoutGlobalScopes()->find($id)) ?? $user;
    }

    /** A password changed at any door (the app, the web, a reset) is the same person's everywhere. */
    public static function spreadPassword(User $changed): void
    {
        if (! self::ready()) {
            return;
        }
        $first = DB::table('account_siblings')->where('sibling_user_id', $changed->id)->value('user_id') ?? $changed->id;
        $others = DB::table('account_siblings')->where('user_id', $first)->pluck('sibling_user_id')->push($first)->reject(fn ($id) => (int) $id === (int) $changed->id);
        if ($others->isNotEmpty() && (int) $first !== 0) {
            DB::table('admin_users')->whereIn('id', $others->all())->update(['password' => $changed->password]);
        }
    }

    public static function isSiblingUsername(string $raw): bool
    {
        return str_starts_with(strtolower(trim($raw)), self::USERNAME_PREFIX);
    }

    /** Whether this copy of the database has the table yet (a test database or a server mid-deploy). */
    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('account_siblings');
    }

    /** For tests that build the table after this process has looked. */
    public static function forgetReadiness(): void
    {
        self::$ready = null;
    }
}
