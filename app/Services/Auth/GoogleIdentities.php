<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Onboarding\PublicDemo;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Google accounts people have connected to their sign-in (user_identities). A Google account is found by its `sub`, which stays
 * the same when the person changes their Gmail address; the email is only a first match and for display. Links always point at the
 * sign-in login (never a sibling), so one Google opens every app the person has. Nothing here merges two people.
 */
final class GoogleIdentities
{
    public const PROVIDER = 'google';

    /** Connect Google is not shown again for this long after "Not now"… */
    public const SNOOZE_DAYS = 14;

    /** …and stops after this many. */
    public const MAX_DISMISSALS = 3;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('user_identities');
    }

    public static function forgetReadiness(): void
    {
        self::$ready = null;
    }

    /** The login a Google account was connected to, or null. */
    public static function find(string $sub): ?User
    {
        if (! self::ready()) {
            return null;
        }
        $id = DB::table('user_identities')->where('provider', self::PROVIDER)->where('subject', $sub)->value('user_id');

        return $id === null ? null : User::withoutGlobalScopes()->find($id);
    }

    /**
     * The login a proved Google account opens, or null for someone new: its own link first (the sub stays when the Gmail address
     * changes), then the email it proves. When the email is proved here for the first time, it counts as proof (EmailProof::claim)
     * and is marked verified; the Google account is then connected. The phone (auth/google) and the web doors both come through here.
     * The caller still refuses a login that is switched off, and picks the app's books (SiblingAccounts::forApp).
     *
     * @param  array{sub: string, email: string}  $who  GoogleSignIn::verify()
     */
    public static function signIn(array $who): ?User
    {
        $byGoogle = self::find($who['sub']);
        $user = $byGoogle ?? AccountLookup::find($who['email']);
        if ($user === null) {
            return null;
        }
        if (strtolower((string) $user->status) === 'inactive') {
            return $user; // nothing is changed for a login that is switched off
        }
        if ($byGoogle === null || strcasecmp((string) $user->email, $who['email']) === 0) {
            EmailProof::claim($user); // the first proof of this email: nothing set up before it stays valid
            $col = OtpService::verifiedColumn($who['email']);
            if ($user->{$col} === null) {
                $user->forceFill([$col => now()])->saveQuietly();
            }
        }
        try {
            self::link($user, $who);
        } catch (BusinessRuleException) {
            // Found by its own link or by its own email, so it can't belong to someone else; a race at worst, and the sign-in stands.
        }

        return $user;
    }

    /**
     * Connects a Google account to this person's sign-in, or notes it was used again. A Google account already connected to someone
     * else is refused, and so is one whose email is another login's sign-in email: that person must sign in there.
     *
     * @param  array{sub: string, email: string}  $who  GoogleSignIn::verify()
     *
     * @throws BusinessRuleException google_taken
     */
    public static function link(User $login, array $who): void
    {
        if (! self::ready()) {
            return;
        }
        $person = SiblingAccounts::signInOf($login);
        $row = DB::table('user_identities')->where('provider', self::PROVIDER)->where('subject', $who['sub'])->first();
        if ($row !== null && (int) $row->user_id !== (int) $person->id) {
            throw BusinessRuleException::make('google_taken', 'This Google account is connected to another sign-in.');
        }
        $owner = AccountLookup::find($who['email']);
        if ($row === null && $owner !== null && (int) $owner->id !== (int) $person->id) {
            throw BusinessRuleException::make('google_taken', 'This Google email is used by another sign-in. Sign in with Google there.');
        }
        DB::table('user_identities')->updateOrInsert(
            ['provider' => self::PROVIDER, 'subject' => $who['sub']],
            ['user_id' => $person->id, 'email' => $who['email'], 'last_used_at' => now()] + ($row === null ? ['linked_at' => now()] : []),
        );
        // A sign-in with no email gets the Google one (proved by Google), so codes and resets have somewhere to go. Nothing else changes.
        if (blank($person->email) && $owner === null) {
            $person->forceFill(['email' => $who['email'], 'email_verified_at' => now()])->saveQuietly();
        }
    }

    /**
     * Disconnects Google. Refused when it is the only way in that the person can recover (no email and no phone): they would be locked out.
     *
     * @throws BusinessRuleException last_way_in
     */
    public static function unlink(User $login): int
    {
        if (! self::ready()) {
            return 0;
        }
        $person = SiblingAccounts::signInOf($login);
        if (blank($person->email) && blank($person->phone_e164) && blank($person->phone_number)) {
            throw BusinessRuleException::make('last_way_in', 'Add an email or phone first, so you can still sign in.');
        }

        return DB::table('user_identities')->where('provider', self::PROVIDER)->where('user_id', $person->id)->delete();
    }

    /** @return array<int, array{email: ?string, linked_at: ?string}> */
    public static function of(User $login): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('user_identities')->where('provider', self::PROVIDER)->where('user_id', SiblingAccounts::signInOf($login)->id)->orderBy('id')
            ->get(['email', 'linked_at'])->map(fn ($r) => ['email' => $r->email, 'linked_at' => $r->linked_at ? \Illuminate\Support\Carbon::parse($r->linked_at)->toIso8601String() : null])->all();
    }

    /** Whether to show "Connect Google" to this person now. */
    public static function nudge(User $login): bool
    {
        if (! self::ready() || trim((string) config('services.google.web_client_id')) === '') {
            return false;
        }
        $person = SiblingAccounts::signInOf($login);
        if (PublicDemo::isDemoUser($person) || \App\Services\Sacco\Demo\SaccoDemo::isDemoUser($person) || \App\Services\Rentals\Demo\RentalsDemo::isDemoUser($person) || \App\Services\Kin\Demo\KinDemo::isDemoUser($person) || \App\Services\Unity\Demo\UnityDemo::isDemoUser($person) || (int) ($person->google_nudge_count ?? 0) >= self::MAX_DISMISSALS) {
            return false;
        }
        if ($person->google_nudge_until !== null && now()->lt(\Illuminate\Support\Carbon::parse($person->google_nudge_until))) {
            return false;
        }

        return ! DB::table('user_identities')->where('provider', self::PROVIDER)->where('user_id', $person->id)->exists();
    }

    /** "Not now": hidden for two weeks, and for good after the third time. */
    public static function dismiss(User $login): void
    {
        $person = SiblingAccounts::signInOf($login);
        $person->forceFill(['google_nudge_until' => now()->addDays(self::SNOOZE_DAYS), 'google_nudge_count' => min(255, (int) ($person->google_nudge_count ?? 0) + 1)])->saveQuietly();
    }
}
