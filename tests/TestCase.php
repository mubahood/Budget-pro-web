<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        self::fakeBreachCheck($this->app);
    }

    /**
     * The password breach check (App\Support\Rules\PasswordPolicy, api.pwnedpasswords.com) never leaves the
     * machine in tests. It is faked at its verifier, which answers "not leaked" without any HTTP call, so no
     * test's own Http::fake, sent-count or assertNothingSent sees it. PasswordPolicyTest puts the real verifier
     * back behind Http::fake for that host to prove the check itself.
     */
    public static function fakeBreachCheck(\Illuminate\Contracts\Foundation\Application $app): void
    {
        $app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data)
            {
                return true;
            }
        });
    }
}
