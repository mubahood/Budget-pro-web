<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Base class for API feature tests.
 *
 * Uses DatabaseTransactions against the dedicated budget_pro_test schema, so
 * every test rolls back and the app database is never modified.
 */
abstract class ApiTestCase extends TestCase
{
    use DatabaseTransactions;

    protected int $tenantSeq = 0;

    /**
     * Reset resolved auth guards before every request.
     *
     * In a single test the application instance is reused across requests, and
     * the Sanctum guard caches the first-resolved user. Without this, a second
     * request that carries a different bearer token would reuse the first
     * token's user. Forgetting guards forces per-request token resolution —
     * matching real HTTP behaviour where each request is a fresh process.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    /**
     * Register a fresh tenant and return [token, userId, companyId, email].
     */
    protected function registerTenant(array $overrides = []): array
    {
        $this->tenantSeq++;
        $unique = uniqid('t', true).$this->tenantSeq;

        $payload = array_merge([
            'first_name' => 'Test',
            'last_name' => 'Owner',
            'email' => "user_{$unique}@example.com",
            'password' => 'secret123',
            'company_name' => 'Co '.$unique,
            'currency' => 'UGX',
        ], $overrides);

        $res = $this->postJson('/api/v1/auth/register', $payload);
        $res->assertStatus(201);

        return [
            'token' => $res->json('data.token'),
            'user_id' => $res->json('data.user.id'),
            'company_id' => $res->json('data.company.id'),
            'email' => $payload['email'],
            'password' => $payload['password'],
        ];
    }

    /**
     * Authorization headers for a bearer token.
     */
    protected function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
