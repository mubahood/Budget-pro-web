<?php

namespace Tests\Feature\Api;

/**
 * Covers the budget/contribution correctness + stability fixes made in this
 * pass: duplicate-name should be a clean 422 (not an uncaught 500), a real
 * receipt figure must survive a fully_paid=Yes save, and an over-budget
 * category's percentage must not be silently clamped to 100.
 */
class BudgetModuleTest extends ApiTestCase
{
    public function test_duplicate_budget_program_name_returns_a_clean_422(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)->assertStatus(201);

        $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 0);
    }

    public function test_duplicate_budget_item_category_name_returns_a_clean_422(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)->json('data.id');
        $this->postJson('/api/v1/budget-item-categories', ['name' => 'Venue', 'budget_program_id' => $prog], $h)->assertStatus(201);

        $this->postJson('/api/v1/budget-item-categories', ['name' => 'Venue', 'budget_program_id' => $prog], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 0);
    }

    public function test_duplicate_budget_item_name_returns_a_clean_422(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)->json('data.id');
        $cat = $this->postJson('/api/v1/budget-item-categories', ['name' => 'Venue', 'budget_program_id' => $prog], $h)->json('data.id');
        $this->postJson('/api/v1/budget-items', ['name' => 'Hall', 'budget_item_category_id' => $cat, 'unit_price' => 1000, 'quantity' => 1], $h)->assertStatus(201);

        $this->postJson('/api/v1/budget-items', ['name' => 'Hall', 'budget_item_category_id' => $cat, 'unit_price' => 1000, 'quantity' => 1], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 0);
    }

    public function test_fully_paid_does_not_destroy_a_lower_real_receipt_figure(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Pledge Drive'], $h)->json('data.id');

        // A pledge of 100,000 that only actually received 60,000 -- but the
        // caller also (incorrectly, or via a UI shortcut bug) sends
        // fully_paid=Yes alongside the real, lower paid_amount. The real
        // figure must survive; fully_paid itself must be derived, not taken
        // at face value.
        $create = $this->postJson('/api/v1/contribution-records', [
            'budget_program_id' => $prog,
            'name' => 'Jane Doe',
            'amount' => 100000,
            'paid_amount' => 60000,
            'fully_paid' => 'Yes',
        ], $h);

        $create->assertStatus(201);
        $this->assertEquals(60000, (int) $create->json('data.paid_amount'));
        $this->assertEquals('No', $create->json('data.fully_paid'));
        $this->assertEquals(40000, (int) $create->json('data.not_paid_amount'));
    }

    public function test_fully_paid_shortcut_still_defaults_paid_amount_when_none_is_sent(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Pledge Drive'], $h)->json('data.id');

        // No paid_amount at all -- fully_paid=Yes alone is still a valid
        // "mark as fully paid" shortcut.
        $create = $this->postJson('/api/v1/contribution-records', [
            'budget_program_id' => $prog,
            'name' => 'John Doe',
            'amount' => 50000,
            'fully_paid' => 'Yes',
        ], $h);

        $create->assertStatus(201);
        $this->assertEquals(50000, (int) $create->json('data.paid_amount'));
        $this->assertEquals('Yes', $create->json('data.fully_paid'));
        $this->assertEquals(0, (int) $create->json('data.not_paid_amount'));
    }

    // NOTE: MobileApiController::contributionRecordSave (and every other
    // Utils::success()/Utils::error()-based legacy/mobile endpoint) calls
    // exit() directly on response, which terminates the PHPUnit process
    // itself rather than just the request -- confirmed by observation, not
    // theory: running a test against it here truncated the entire suite run
    // after this test. These endpoints are therefore untestable via normal
    // Feature tests without a separate refactor of Utils::success/error to
    // stop calling exit(), which is out of scope for this pass. The
    // equivalent fully_paid fix applied to MobileApiController::
    // contributionRecordSave was instead verified manually via a direct
    // request (see PR notes) rather than an automated regression test.

    public function test_over_budget_category_percentage_is_not_clamped_to_100(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)->json('data.id');
        $cat = $this->postJson('/api/v1/budget-item-categories', ['name' => 'Venue', 'budget_program_id' => $prog], $h)->json('data.id');

        // target 100,000, invested 150,000 -> genuinely 150% done (over budget).
        $this->postJson('/api/v1/budget-items', [
            'name' => 'Hall', 'budget_item_category_id' => $cat, 'unit_price' => 100000, 'quantity' => 1,
        ], $h)->assertStatus(201);

        $item = \App\Models\BudgetItem::where('budget_item_category_id', $cat)->first();
        $item->invested_amount = 150000;
        $item->saveQuietly();

        \App\Models\BudgetItemCategory::find($cat)->updateSelf();

        $category = $this->getJson("/api/v1/budget-item-categories/{$cat}", $h);
        $category->assertOk();
        $this->assertGreaterThan(100, (float) $category->json('data.percentage_done'));
    }
}
