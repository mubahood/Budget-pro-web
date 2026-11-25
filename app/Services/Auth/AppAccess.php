<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\PublicDemo;
use App\Services\Onboarding\SiblingAccounts;
use App\Services\Team\Permissions;
use App\Support\AppFamily;
use Illuminate\Support\Facades\DB;

/**
 * Whether the person who just signed in already has the app they signed in to, and how it is added if not
 * (budget-pro-new/docs/accounts/01-one-person-many-apps.md). One person, one sign-in, many apps:
 *  - a family app (Shelfie, Kuku Tally, Pledge & Budget, Budget Pro) is added to the books they have, as a module;
 *  - an own-books app (Akiba Ledger, Estate Ledger), or any app for someone whose books stand alone, opens books of its own beside
 *    theirs, on the same sign-in (SiblingAccounts).
 * Nothing is merged, moved or deleted: a module is only added, and books beside are new.
 */
final class AppAccess
{
    /**
     * What the apps say after a sign-in. Null when the app is unknown (old phones, the web's own doors).
     *
     * `handoff` is true when the person signed in but has no books for this app yet: the token still opens their own
     * account, nothing was created for them, and the phone shows its neutral "this app is for ..." screen (UnityDesk's
     * phone, which replaces Budget Pro's, reads this when an old Budget Pro user signs in) and offers `add` only when asked.
     *
     * @return array{app: string, name: string, has: bool, handoff: bool, add: ?string, can_add: bool, books: ?string}|null
     */
    public static function of(User $login, ?string $app): ?array
    {
        $slug = AppFamily::slug($app);
        $company = $slug === null ? null : Company::withoutGlobalScopes()->find($login->company_id);
        if ($company === null) {
            return null;
        }
        $modules = $company->modules();
        $has = AppFamily::opens($modules, $slug);
        $add = $has ? null : ((AppFamily::ownBooks($slug) || AppFamily::booksStandAlone($modules)) ? 'sibling' : 'module');
        $demo = PublicDemo::isDemoUser($login) || PublicDemo::isDemoUser(SiblingAccounts::signInOf($login));
        $canAdd = match ($add) {
            'module' => ! $demo && Permissions::can($login, 'manage_settings'),
            'sibling' => ! $demo,
            default => false,
        };

        return [
            'app' => $slug,
            'name' => AppFamily::name($slug),
            'has' => $has,
            'handoff' => ! $has,
            'add' => $add,
            'can_add' => $canAdd,
            // Only for a module: the books it joins. Akiba and Estate never name the person's other books.
            'books' => $add === 'module' ? (string) $company->name : null,
        ];
    }

    /**
     * Adds [app] for this person and says which login opens it now. Asked twice it changes nothing the second time.
     *
     * @return array{user: User, company: Company, joined: bool}
     *
     * @throws BusinessRuleException app_unknown, not_allowed, name_required, demo
     */
    public function join(User $login, string $app, ?string $name = null): array
    {
        $slug = AppFamily::slug($app) ?? throw BusinessRuleException::make('app_unknown', 'This app is not known.');
        $person = SiblingAccounts::signInOf($login);
        $target = SiblingAccounts::forApp($person, $slug);
        $access = self::of($target, $slug) ?? throw BusinessRuleException::make('no_company', 'Your account is not linked to a business.');

        if ($access['has']) {
            return ['user' => $target, 'company' => Company::withoutGlobalScopes()->findOrFail($target->company_id), 'joined' => false];
        }
        if ($access['add'] === 'module') {
            PublicDemo::guardAccount($target, 'adding an app');
            if (! Permissions::can($target, 'manage_settings')) {
                throw BusinessRuleException::make('not_allowed', 'Ask the owner of '.$access['books'].' to add '.$access['name'].'.');
            }
            $company = DB::transaction(function () use ($target, $slug) {
                $company = Company::withoutGlobalScopes()->lockForUpdate()->findOrFail($target->company_id);
                $company->enabled_modules = array_values(array_unique([...$company->modules(), AppFamily::module($slug)]));
                $company->save();

                return $company;
            });

            return ['user' => $target, 'company' => $company, 'joined' => true];
        }

        $made = app(SiblingAccounts::class)->open($person, $slug, (string) $name);

        return $made + ['joined' => true];
    }
}
