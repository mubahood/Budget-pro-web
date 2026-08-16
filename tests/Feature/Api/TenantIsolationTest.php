<?php

namespace Tests\Feature\Api;

/**
 * The crown-jewel tests: one tenant must never read or write another tenant's data.
 */
class TenantIsolationTest extends ApiTestCase
{
    public function test_tenant_cannot_read_update_or_delete_another_tenants_record(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // Tenant A creates a stock category.
        $created = $this->postJson('/api/v1/stock-categories', ['name' => 'A-only'], $this->auth($a['token']));
        $created->assertStatus(201);
        $id = $created->json('data.id');

        // Tenant B must not see it.
        $this->getJson("/api/v1/stock-categories/{$id}", $this->auth($b['token']))->assertStatus(404);
        $this->putJson("/api/v1/stock-categories/{$id}", ['name' => 'hacked'], $this->auth($b['token']))->assertStatus(404);
        $this->deleteJson("/api/v1/stock-categories/{$id}", [], $this->auth($b['token']))->assertStatus(404);

        // The record is untouched for tenant A.
        $this->getJson("/api/v1/stock-categories/{$id}", $this->auth($a['token']))
            ->assertOk()->assertJsonPath('data.name', 'A-only');
    }

    public function test_list_is_scoped_to_own_company(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        $this->postJson('/api/v1/stock-categories', ['name' => 'Cat A1'], $this->auth($a['token']))->assertStatus(201);
        $this->postJson('/api/v1/stock-categories', ['name' => 'Cat A2'], $this->auth($a['token']))->assertStatus(201);

        $listA = $this->getJson('/api/v1/stock-categories', $this->auth($a['token']));
        $listA->assertOk();
        $this->assertSame(2, $listA->json('meta.total'));

        $listB = $this->getJson('/api/v1/stock-categories', $this->auth($b['token']));
        $listB->assertOk();
        $this->assertSame(0, $listB->json('meta.total'));
    }

    public function test_mass_assignment_of_company_and_creator_is_ignored(): void
    {
        $a = $this->registerTenant();

        $res = $this->postJson('/api/v1/stock-categories', [
            'name' => 'Guarded',
            'company_id' => 999999,       // attempt to plant in another tenant
            'created_by_id' => 888888,    // attempt to forge creator
            'earned_profit' => 500000,    // attempt to set a computed rollup
        ], $this->auth($a['token']));

        $res->assertStatus(201);
        $this->assertSame($a['company_id'], $res->json('data.company_id'));
    }

    public function test_cannot_reference_another_tenants_foreign_key(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // A creates a category; B tries to attach a sub-category to A's category.
        $catA = $this->postJson('/api/v1/stock-categories', ['name' => 'A cat'], $this->auth($a['token']))->json('data.id');

        $this->postJson('/api/v1/stock-sub-categories', [
            'name' => 'Sneaky', 'stock_category_id' => $catA,
        ], $this->auth($b['token']))->assertStatus(422); // exists rule is company-scoped
    }
}
