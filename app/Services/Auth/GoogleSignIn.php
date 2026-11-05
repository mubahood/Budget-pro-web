<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;

/**
 * Whether a Google ID token from the phone is real and meant for us. Google checks the signature (tokeninfo); we check it was made
 * for our app, that it is Google's, that it has not expired and that Google has confirmed the email. Nothing is kept from it.
 */
final class GoogleSignIn
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @return array{email: string, first_name: string, last_name: string}
     *
     * @throws BusinessRuleException google_off, google_unreachable or google_invalid
     */
    public function verify(string $idToken): array
    {
        $clientId = trim((string) config('services.google.web_client_id'));
        if ($clientId === '') {
            throw BusinessRuleException::make('google_off', 'Sign in with Google is not available on this server.');
        }
        try {
            $answer = Http::timeout(12)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        } catch (\Throwable) {
            throw BusinessRuleException::make('google_unreachable', 'We could not reach Google. Check your connection and try again.');
        }
        $claims = $answer->successful() ? (array) $answer->json() : [];
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($email === '' || ! $verified || ($claims['aud'] ?? null) !== $clientId || ! in_array($claims['iss'] ?? null, self::ISSUERS, true) || (int) ($claims['exp'] ?? 0) <= time()) {
            throw BusinessRuleException::make('google_invalid', 'Google could not confirm this sign-in. Please try again.');
        }

        return ['email' => $email, 'first_name' => trim((string) ($claims['given_name'] ?? '')), 'last_name' => trim((string) ($claims['family_name'] ?? ''))];
    }
}
