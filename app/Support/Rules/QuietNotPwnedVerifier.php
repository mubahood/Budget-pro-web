<?php

namespace App\Support\Rules;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\NotPwnedVerifier;

/**
 * The breach check behind PasswordPolicy (Have I Been Pwned's range API), quiet when the server is offline.
 *
 * Laravel's verifier reports every failed request as an error, so a standalone copy with no internet
 * filled System health with one error per password set. This one still fails open (the password is let
 * through), and:
 *
 *  - no connection or a timeout: no error report, one log line an hour at most;
 *  - an error answer from the API (any non-2xx): one warning an hour at most, with the status;
 *  - anything else going wrong: reported, once an hour at most.
 *
 * Bound by PasswordPolicy::shortenBreachCheck in both apps, with its short timeout.
 */
class QuietNotPwnedVerifier extends NotPwnedVerifier
{
    /** At most one note an hour per kind of failure. */
    public const QUIET_SECONDS = 3600;

    protected function search($hashPrefix)
    {
        $response = null;
        try {
            $response = $this->factory->withHeaders(['Add-Padding' => true])->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (ConnectionException $e) {
            self::once('offline', fn () => Log::warning('[passwords] The leaked-password check could not reach its server, so new passwords are let through without it. Said once an hour.', ['error' => $e->getMessage()]));
        } catch (\Throwable $e) {
            self::once('error:'.$e::class, fn () => report($e));
        }

        if ($response !== null && ! $response->successful()) {
            self::once('status:'.$response->status(), fn () => Log::warning('[passwords] The leaked-password check answered with an error, so new passwords are let through without it. Said once an hour.', ['status' => $response->status()]));
        }
        $body = $response !== null && $response->successful() ? $response->body() : '';

        return Str::of($body)->trim()->explode("\n")->filter(fn ($line) => str_contains($line, ':'));
    }

    private static function once(string $kind, callable $note): void
    {
        try {
            $first = Cache::add('passwords:breach-check-note:'.$kind, 1, self::QUIET_SECONDS);
        } catch (\Throwable) {
            $first = true; // no cache: say it
        }
        if ($first) {
            $note();
        }
    }
}
