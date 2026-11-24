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
    /** Apps whose own books are always opened this way => the module their accounts are built around. A family app (\App\Support\AppFamily)
     *  is opened this way too, but only for a person whose own books stand alone (an Akiba or Estate account can't take a shop module). */
    public const APPS = ['estate-ledger' => 'rentals', 'akiba-ledger' => 'sacco'];

    /** The business type a new account beside the person's is built as, per module. */
    private const BUSINESS_TYPE = ['shop' => 'retail', 'poultry' => 'poultry', 'budget' => 'fundraising', 'sacco' => 'sacco', 'rentals' => 'rentals'];

    /** What the name question says, per module. */
    private const NAME_ASK = ['shop' => 'Name your shop.', 'poultry' => 'Name your farm.', 'budget' => 'Name your event or budget.', 'sacco' => 'Name your SACCO.', 'rentals' => 'Name your agency or property.'];

    /** The start of a sibling's username: nothing signs in with one. */
    public const USERNAME_PREFIX = 'sibling-';

    /** What a person signs in with. */
    private const SIGN_IN = ['email', 'email_verified_at', 'username', 'phone_number', 'phone_e164', 'phone_verified_at', 'password'];

    private static ?bool $ready = null;

    /**
     * Opens the account, or finds the one already opened: asking twice (an answer lost on a bad network) never makes two.
     *
     * @return array{user: User, company: Company}
     */
    public function open(User $person, string $app, string $name): array
    {
        $app = \App\Support\AppFamily::slug($app) ?? throw BusinessRuleException::make('app_not_offered', 'This app does not open a separate account.');
        $module = \App\Support\AppFamily::module($app);
        $person = self::signInOf($person);
        PublicDemo::guardAccount($person, 'opening another account');
        $company = Company::withoutGlobalScopes()->find($person->company_id);
        if ($company === null) {
            throw BusinessRuleException::make('no_company', 'Your account is not linked to a business.');
        }
        if (! \App\Support\AppFamily::ownBooks($app) && ! \App\Support\AppFamily::booksStandAlone($company->modules())) {
            // Family books take the app as a module instead (\App\Services\Auth\AppAccess::join).
            throw BusinessRuleException::make('app_not_offered', 'This app is added to your books, not opened beside them.');
        }
        if (\App\Support\AppFamily::opens($company->modules(), $app)) {
            throw BusinessRuleException::make('already_has_it', 'This account already has it.');
        }
        $existing = self::forApp($person, $app);
        if ($existing->id !== $person->id) {
            return ['user' => $existing, 'company' => Company::withoutGlobalScopes()->findOrFail($existing->company_id)];
        }
        $name = trim($name);
        if ($name === '') {
            throw BusinessRuleException::make('name_required', self::NAME_ASK[$module] ?? 'Name your business.');
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
                'business_type' => self::BUSINESS_TYPE[$module] ?? 'other',
                'modules' => [$module],
            ], RegistrationService::PRODUCT_BUDGET, 'sibling');
            $made['user']->forceFill(['email' => null, 'username' => $username, 'password' => $person->password, 'email_verified_at' => now()])->saveQuietly();
            $made['company']->forceFill(['email' => $person->email, 'phone_number' => $person->phone_e164 ?: $person->phone_number])->saveQuietly();
            DB::table('account_siblings')->insert(['user_id' => $person->id, 'sibling_user_id' => $made['user']->id, 'app' => $app, 'created_at' => now(), 'updated_at' => now()]);

            return $made;
        });
    }

    /**
     * The login that opens [app] for the person who just proved who they are: the account beside theirs made for that app if there is
     * one, else theirs. A family app on a person whose own books stand alone falls back to any family account beside theirs, since
     * family books are one set whichever family app opened them.
     */
    public static function forApp(User $person, ?string $app): User
    {
        $app = \App\Support\AppFamily::slug($app);
        if ($app === null || ! self::ready()) {
            return $person;
        }
        $links = DB::table('account_siblings')->where('user_id', $person->id)->orderBy('id')->pluck('sibling_user_id', 'app');
        if ($links->isEmpty()) {
            return $person;
        }
        $id = $links[$app] ?? null;
        if ($id === null && ! \App\Support\AppFamily::ownBooks($app)) {
            $own = Company::withoutGlobalScopes()->find($person->company_id);
            if ($own !== null && \App\Support\AppFamily::booksStandAlone($own->modules())) {
                $id = $links->first(fn ($sid, $linked) => \App\Support\AppFamily::slug($linked) !== null && ! \App\Support\AppFamily::ownBooks(\App\Support\AppFamily::slug($linked)));
            }
        }

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

    /**
     * These logins are going with their account. Takes every link naming them away, so someone who asks again later gets a new account
     * and not a refusal, and says who must take over: a person whose own sign-in goes still has the account beside it, and needs a way in.
     * Call it before the logins are deleted, and [takeOver] after.
     *
     * @param  array<int, int|string>  $userIds
     * @return array<int, array<string, mixed>> the login that stays => how the person signs in
     */
    public static function leaving(array $userIds): array
    {
        if ($userIds === [] || ! self::ready()) {
            return [];
        }
        $going = array_map('intval', $userIds);
        $heirs = [];
        foreach (DB::table('account_siblings')->whereIn('user_id', $going)->orderBy('id')->get()->groupBy('user_id') as $first => $links) {
            $stays = $links->reject(fn ($l) => in_array((int) $l->sibling_user_id, $going, true))->values();
            if ($stays->isEmpty()) {
                continue;
            }
            $heir = (int) $stays->first()->sibling_user_id;
            $heirs[$heir] = (array) DB::table('admin_users')->where('id', $first)->first(self::SIGN_IN);
            if (Schema::hasTable('user_identities')) {
                DB::table('user_identities')->where('user_id', $first)->update(['user_id' => $heir]); // their Google goes with their sign-in
            }
            DB::table('account_siblings')->whereIn('id', $stays->slice(1)->pluck('id')->all())->update(['user_id' => $heir]);
        }
        DB::table('account_siblings')->whereIn('user_id', $going)->orWhereIn('sibling_user_id', $going)->delete();

        return $heirs;
    }

    /** @param  array<int, array<string, mixed>>  $heirs what [leaving] returned */
    public static function takeOver(array $heirs): void
    {
        foreach ($heirs as $heir => $signIn) {
            DB::table('admin_users')->where('id', $heir)->update($signIn);
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
