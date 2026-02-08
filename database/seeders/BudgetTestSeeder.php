<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * BudgetTestSeeder
 *
 * Creates a brand new test account with dummy data and verifies
 * that every calculation in the Budget + Contribution module
 * sums up correctly from A to Z.
 *
 * Run with: php artisan db:seed --class=BudgetTestSeeder
 */
class BudgetTestSeeder extends Seeder
{
    /**
     * Keeps track of pass/fail assertions for the final report.
     */
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════╗');
        $this->command->info('║   BUDGET MODULE — FULL END-TO-END TEST SEEDER   ║');
        $this->command->info('╚══════════════════════════════════════════════════╝');
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 1: Create a fresh test company + user
        // ───────────────────────────────────────────────
        $this->command->info('STEP 1: Creating test company and user...');

        // Clean up any previous test run
        $this->cleanup();

        // Create admin_user first (needed as owner_id for company)
        $userId = DB::table('admin_users')->insertGetId([
            'username'   => 'test_budget_user@example.com',
            'password'   => Hash::make('password123'),
            'name'       => 'Budget Test User',
            'first_name' => 'Budget',
            'last_name'  => 'Tester',
            'email'      => 'test_budget_user@example.com',
            'avatar'     => null,
            'company_id' => null,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create company
        $companyId = DB::table('companies')->insertGetId([
            'owner_id'    => $userId,
            'name'        => 'Budget Test Company (AUTO-TEST)',
            'email'       => 'test_budget_company@example.com',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Link user to company
        DB::table('admin_users')->where('id', $userId)->update([
            'company_id' => $companyId,
        ]);

        // Assign role 2 (Company Owner)
        DB::table('admin_role_users')->insert([
            'role_id' => 2,
            'user_id' => $userId,
        ]);

        $this->command->info("   ✓ User ID: {$userId}");
        $this->command->info("   ✓ Company ID: {$companyId}");
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 2: Create a Budget Program
        // ───────────────────────────────────────────────
        $this->command->info('STEP 2: Creating budget program...');

        $programId = DB::table('budget_programs')->insertGetId([
            'company_id'     => $companyId,
            'name'           => 'Church Building Fund 2026 (TEST)',
            'title'          => 'Church Building Fund 2026 Test',
            'status'         => 'Active',
            'is_default'     => 'Yes',
            'deadline'       => '2026-12-31',
            'total_collected' => 0,
            'total_expected'  => 0,
            'total_in_pledge' => 0,
            'budget_total'    => 0,
            'budget_spent'    => 0,
            'budget_balance'  => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->command->info("   ✓ Budget Program ID: {$programId}");
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 3: Create Budget Item Categories
        // ───────────────────────────────────────────────
        $this->command->info('STEP 3: Creating budget item categories...');

        $categories = [
            [
                'name'     => 'Construction Materials (TEST)',
                'items'    => [
                    ['name' => 'Cement Bags',        'quantity' => 500, 'unit_price' => 35000, 'invested_amount' => 10000000],
                    ['name' => 'Iron Sheets',        'quantity' => 200, 'unit_price' => 45000, 'invested_amount' => 5000000],
                    ['name' => 'Sand (Trucks)',       'quantity' => 10,  'unit_price' => 300000, 'invested_amount' => 3000000],
                    ['name' => 'Bricks',             'quantity' => 10000, 'unit_price' => 500,  'invested_amount' => 4500000],
                ],
            ],
            [
                'name'     => 'Labour Costs (TEST)',
                'items'    => [
                    ['name' => 'Foreman Salary',     'quantity' => 6, 'unit_price' => 800000, 'invested_amount' => 4800000],
                    ['name' => 'Mason Workers',      'quantity' => 10, 'unit_price' => 500000, 'invested_amount' => 3000000],
                    ['name' => 'Plumber',            'quantity' => 1,  'unit_price' => 1500000, 'invested_amount' => 0],
                ],
            ],
            [
                'name'     => 'Furnishing (TEST)',
                'items'    => [
                    ['name' => 'Pews/Benches',       'quantity' => 50, 'unit_price' => 200000, 'invested_amount' => 10000000],
                    ['name' => 'Altar Set',          'quantity' => 1,  'unit_price' => 5000000, 'invested_amount' => 5000000],
                ],
            ],
        ];

        $categoryIds = [];
        foreach ($categories as $catData) {
            $catId = DB::table('budget_item_categories')->insertGetId([
                'budget_program_id' => $programId,
                'company_id'        => $companyId,
                'name'              => $catData['name'],
                'target_amount'     => 0,
                'invested_amount'   => 0,
                'balance'           => 0,
                'percentage_done'   => 0,
                'is_complete'       => 'No',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            $categoryIds[$catData['name']] = $catId;
            $this->command->info("   ✓ Category '{$catData['name']}' ID: {$catId}");
        }
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 4: Create Budget Items
        // ───────────────────────────────────────────────
        $this->command->info('STEP 4: Creating budget items...');

        $allItems = []; // track for verification
        foreach ($categories as $catData) {
            $catId = $categoryIds[$catData['name']];
            foreach ($catData['items'] as $itemData) {
                $targetAmount = $itemData['quantity'] * $itemData['unit_price'];
                $balance = $targetAmount - $itemData['invested_amount'];
                $percentageDone = $targetAmount > 0
                    ? round(($itemData['invested_amount'] / $targetAmount) * 100, 2)
                    : 0;
                $isComplete = $percentageDone >= 98 ? 'Yes' : 'No';

                $itemId = DB::table('budget_items')->insertGetId([
                    'budget_program_id'       => $programId,
                    'budget_item_category_id' => $catId,
                    'company_id'              => $companyId,
                    'created_by_id'           => $userId,
                    'changed_by_id'           => $userId,
                    'name'                    => $itemData['name'],
                    'quantity'                => $itemData['quantity'],
                    'unit_price'              => $itemData['unit_price'],
                    'target_amount'           => $targetAmount,
                    'invested_amount'         => $itemData['invested_amount'],
                    'balance'                 => $balance,
                    'percentage_done'         => $percentageDone,
                    'is_complete'             => $isComplete,
                    'approved'                => 'No',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);

                $allItems[] = [
                    'id'              => $itemId,
                    'name'            => $itemData['name'],
                    'category_id'     => $catId,
                    'target_amount'   => $targetAmount,
                    'invested_amount' => $itemData['invested_amount'],
                    'balance'         => $balance,
                    'percentage_done' => $percentageDone,
                    'is_complete'     => $isComplete,
                ];

                $this->command->info("   ✓ Item '{$itemData['name']}': target={$targetAmount}, invested={$itemData['invested_amount']}, balance={$balance}, %={$percentageDone}");
            }
        }
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 5: Now trigger category updateSelf() via batch fix
        //   (simulates what happens when items are created/updated)
        // ───────────────────────────────────────────────
        $this->command->info('STEP 5: Recalculating categories from items (updateSelf)...');

        foreach ($categoryIds as $catName => $catId) {
            $cat = \App\Models\BudgetItemCategory::withoutGlobalScopes()->find($catId);
            if ($cat) {
                $cat->updateSelf();
                $this->command->info("   ✓ Category '{$catName}' recalculated");
            }
        }
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 6: Create Contribution Records
        // ───────────────────────────────────────────────
        $this->command->info('STEP 6: Creating contribution records...');

        $contributions = [
            ['name' => 'John Mukasa',     'category_id' => 'Family',  'amount' => 500000,  'paid_amount' => 500000,  'fully_paid' => 'Yes'],
            ['name' => 'Sarah Nambi',     'category_id' => 'Friend',  'amount' => 200000,  'paid_amount' => 200000,  'fully_paid' => 'Yes'],
            ['name' => 'Peter Okello',    'category_id' => 'Member',  'amount' => 1000000, 'paid_amount' => 600000,  'fully_paid' => 'No'],
            ['name' => 'Grace Achieng',   'category_id' => 'Sponsor', 'amount' => 5000000, 'paid_amount' => 2000000, 'fully_paid' => 'No'],
            ['name' => 'David Ssempala',  'category_id' => 'MTK',     'amount' => 300000,  'paid_amount' => 300000,  'fully_paid' => 'Yes'],
            ['name' => 'Ruth Nalwanga',   'category_id' => 'Family',  'amount' => 150000,  'paid_amount' => 100000,  'fully_paid' => 'No'],
            ['name' => 'Moses Kato',      'category_id' => 'Member',  'amount' => 800000,  'paid_amount' => 800000,  'fully_paid' => 'Yes'],
            ['name' => 'Faith Akite',     'category_id' => 'Friend',  'amount' => 250000,  'paid_amount' => 0,       'fully_paid' => 'No'],
            ['name' => 'James Ochieng',   'category_id' => 'Sponsor', 'amount' => 2000000, 'paid_amount' => 1500000, 'fully_paid' => 'No'],
            ['name' => 'Esther Birungi',  'category_id' => 'Other',   'amount' => 100000,  'paid_amount' => 100000,  'fully_paid' => 'Yes'],
        ];

        $allContribs = [];
        foreach ($contributions as $c) {
            $notPaid = $c['fully_paid'] === 'Yes' ? 0 : ($c['amount'] - $c['paid_amount']);
            $contribId = DB::table('contribution_records')->insertGetId([
                'budget_program_id' => $programId,
                'company_id'        => $companyId,
                'treasurer_id'      => $userId,
                'chaned_by_id'      => $userId,
                'name'              => $c['name'],
                'category_id'       => $c['category_id'],
                'amount'            => $c['amount'],
                'paid_amount'       => $c['paid_amount'],
                'not_paid_amount'   => $notPaid,
                'fully_paid'        => $c['fully_paid'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $allContribs[] = [
                'id'              => $contribId,
                'name'            => $c['name'],
                'amount'          => $c['amount'],
                'paid_amount'     => $c['paid_amount'],
                'not_paid_amount' => $notPaid,
                'fully_paid'      => $c['fully_paid'],
            ];
            $this->command->info("   ✓ '{$c['name']}': pledged={$c['amount']}, paid={$c['paid_amount']}, balance={$notPaid}");
        }
        $this->command->info('');

        // ───────────────────────────────────────────────
        // STEP 7: Cascade program totals
        // ───────────────────────────────────────────────
        $this->command->info('STEP 7: Recalculating program totals (recalculateFromChildren)...');
        \App\Models\BudgetProgram::recalculateFromChildren($programId);
        $this->command->info("   ✓ Program #{$programId} recalculated");
        $this->command->info('');

        // ════════════════════════════════════════════════
        // VERIFICATION: Check every number from A to Z
        // ════════════════════════════════════════════════
        $this->command->info('╔══════════════════════════════════════════════════╗');
        $this->command->info('║           VERIFICATION — CHECKING SUMS          ║');
        $this->command->info('╚══════════════════════════════════════════════════╝');
        $this->command->info('');

        // ── VERIFY BUDGET ITEMS ──
        $this->command->info('── Budget Items ──');
        foreach ($allItems as $expected) {
            $actual = DB::table('budget_items')->where('id', $expected['id'])->first();
            $this->assert(
                "Item '{$expected['name']}' target_amount",
                (float)$expected['target_amount'],
                (float)$actual->target_amount
            );
            $this->assert(
                "Item '{$expected['name']}' invested_amount",
                (float)$expected['invested_amount'],
                (float)$actual->invested_amount
            );
            $this->assert(
                "Item '{$expected['name']}' balance",
                (float)$expected['balance'],
                (float)$actual->balance
            );

            // Verify target = unit_price * quantity
            $derivedTarget = (float)$actual->unit_price * (float)$actual->quantity;
            $this->assert(
                "Item '{$expected['name']}' target = price × qty ({$actual->unit_price} × {$actual->quantity})",
                $derivedTarget,
                (float)$actual->target_amount
            );

            // Verify percentage (DB stores as INT, so compare at integer precision)
            $expectedPct = $actual->target_amount > 0
                ? round(((float)$actual->invested_amount / (float)$actual->target_amount) * 100, 0)
                : 0;
            $this->assert(
                "Item '{$expected['name']}' percentage_done",
                (int)$expectedPct,
                (int)$actual->percentage_done
            );

            // Verify is_complete
            $expectedComplete = $expectedPct >= 98 ? 'Yes' : 'No';
            $this->assert(
                "Item '{$expected['name']}' is_complete",
                $expectedComplete,
                $actual->is_complete
            );
        }
        $this->command->info('');

        // ── VERIFY CATEGORIES ──
        $this->command->info('── Budget Item Categories ──');
        foreach ($categories as $catData) {
            $catId = $categoryIds[$catData['name']];
            $actual = DB::table('budget_item_categories')->where('id', $catId)->first();

            // Calculate expected from items
            $expectedTarget = 0;
            $expectedInvested = 0;
            foreach ($catData['items'] as $itemData) {
                $expectedTarget += ($itemData['quantity'] * $itemData['unit_price']);
                $expectedInvested += $itemData['invested_amount'];
            }
            $expectedBalance = $expectedTarget - $expectedInvested;
            $expectedPct = $expectedTarget > 0
                ? round(($expectedInvested / $expectedTarget) * 100, 0)
                : 0;
            $expectedComplete = ($expectedPct >= 98 || $expectedBalance <= 0) ? 'Yes' : 'No';

            $this->assert(
                "Category '{$catData['name']}' target_amount",
                (float)$expectedTarget,
                (float)$actual->target_amount
            );
            $this->assert(
                "Category '{$catData['name']}' invested_amount",
                (float)$expectedInvested,
                (float)$actual->invested_amount
            );
            $this->assert(
                "Category '{$catData['name']}' balance",
                (float)$expectedBalance,
                (float)$actual->balance
            );
            $this->assert(
                "Category '{$catData['name']}' percentage_done",
                (int)$expectedPct,
                (int)$actual->percentage_done
            );
            $this->assert(
                "Category '{$catData['name']}' is_complete",
                $expectedComplete,
                $actual->is_complete
            );

            // Cross-check: SUM of items under this category should match
            $itemAggregates = DB::table('budget_items')
                ->where('budget_item_category_id', $catId)
                ->selectRaw('SUM(target_amount) as sum_target, SUM(invested_amount) as sum_invested')
                ->first();
            $this->assert(
                "Category '{$catData['name']}' target = SUM(items.target)",
                (float)$itemAggregates->sum_target,
                (float)$actual->target_amount
            );
            $this->assert(
                "Category '{$catData['name']}' invested = SUM(items.invested)",
                (float)$itemAggregates->sum_invested,
                (float)$actual->invested_amount
            );
        }
        $this->command->info('');

        // ── VERIFY CONTRIBUTIONS ──
        $this->command->info('── Contribution Records ──');
        foreach ($allContribs as $expected) {
            $actual = DB::table('contribution_records')->where('id', $expected['id'])->first();
            $this->assert(
                "Contribution '{$expected['name']}' amount",
                (float)$expected['amount'],
                (float)$actual->amount
            );
            $this->assert(
                "Contribution '{$expected['name']}' paid_amount",
                (float)$expected['paid_amount'],
                (float)$actual->paid_amount
            );
            $this->assert(
                "Contribution '{$expected['name']}' not_paid_amount",
                (float)$expected['not_paid_amount'],
                (float)$actual->not_paid_amount
            );
            $this->assert(
                "Contribution '{$expected['name']}' fully_paid",
                $expected['fully_paid'],
                $actual->fully_paid
            );

            // Verify: paid + not_paid = amount (always)
            $this->assert(
                "Contribution '{$expected['name']}' paid + not_paid = amount",
                (float)$actual->amount,
                (float)$actual->paid_amount + (float)$actual->not_paid_amount
            );

            // Verify: paid_amount <= amount (never exceeds)
            $paidOk = (float)$actual->paid_amount <= (float)$actual->amount;
            $this->assert(
                "Contribution '{$expected['name']}' paid <= pledged",
                true,
                $paidOk
            );
        }
        $this->command->info('');

        // ── VERIFY PROGRAM TOTALS ──
        $this->command->info('── Budget Program Totals ──');
        $program = DB::table('budget_programs')->where('id', $programId)->first();

        // Expected budget totals (from categories)
        $expectedBudgetTotal = 0;
        $expectedBudgetSpent = 0;
        foreach ($categories as $catData) {
            foreach ($catData['items'] as $itemData) {
                $expectedBudgetTotal += ($itemData['quantity'] * $itemData['unit_price']);
                $expectedBudgetSpent += $itemData['invested_amount'];
            }
        }
        $expectedBudgetBalance = $expectedBudgetTotal - $expectedBudgetSpent;

        // Expected contribution totals
        $expectedTotalExpected = 0;
        $expectedTotalCollected = 0;
        $expectedTotalInPledge = 0;
        foreach ($contributions as $c) {
            $expectedTotalExpected += $c['amount'];
            $expectedTotalCollected += $c['paid_amount'];
            $notPaid = $c['fully_paid'] === 'Yes' ? 0 : ($c['amount'] - $c['paid_amount']);
            $expectedTotalInPledge += $notPaid;
        }

        $this->assert('Program budget_total',    (float)$expectedBudgetTotal,    (float)$program->budget_total);
        $this->assert('Program budget_spent',    (float)$expectedBudgetSpent,    (float)$program->budget_spent);
        $this->assert('Program budget_balance',  (float)$expectedBudgetBalance,  (float)$program->budget_balance);
        $this->assert('Program total_expected',  (float)$expectedTotalExpected,  (float)$program->total_expected);
        $this->assert('Program total_collected', (float)$expectedTotalCollected, (float)$program->total_collected);
        $this->assert('Program total_in_pledge', (float)$expectedTotalInPledge,  (float)$program->total_in_pledge);

        // Cross-check: budget_balance = budget_total - budget_spent
        $this->assert(
            'Program budget_balance = total - spent',
            (float)$program->budget_total - (float)$program->budget_spent,
            (float)$program->budget_balance
        );

        // Cross-check: total_expected = total_collected + total_in_pledge
        $this->assert(
            'Program total_expected = collected + pledge',
            (float)$program->total_expected,
            (float)$program->total_collected + (float)$program->total_in_pledge
        );

        // Cross-check: program budget_total = SUM(categories.target_amount)
        $catSum = DB::table('budget_item_categories')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(target_amount),0) as t, COALESCE(SUM(invested_amount),0) as i')
            ->first();
        $this->assert(
            'Program budget_total = SUM(categories.target)',
            (float)$catSum->t,
            (float)$program->budget_total
        );
        $this->assert(
            'Program budget_spent = SUM(categories.invested)',
            (float)$catSum->i,
            (float)$program->budget_spent
        );

        // Cross-check: program contributions = SUM(contribution_records)
        $contribSum = DB::table('contribution_records')
            ->where('budget_program_id', $programId)
            ->selectRaw('COALESCE(SUM(amount),0) as e, COALESCE(SUM(paid_amount),0) as c, COALESCE(SUM(not_paid_amount),0) as p')
            ->first();
        $this->assert(
            'Program total_expected = SUM(contributions.amount)',
            (float)$contribSum->e,
            (float)$program->total_expected
        );
        $this->assert(
            'Program total_collected = SUM(contributions.paid)',
            (float)$contribSum->c,
            (float)$program->total_collected
        );
        $this->assert(
            'Program total_in_pledge = SUM(contributions.not_paid)',
            (float)$contribSum->p,
            (float)$program->total_in_pledge
        );

        $this->command->info('');

        // ── VERIFY: Category totals rolled into program ──
        $this->command->info('── Category → Program Roll-up ──');
        $allCatTarget = 0;
        $allCatInvested = 0;
        foreach ($categoryIds as $catName => $catId) {
            $cat = DB::table('budget_item_categories')->where('id', $catId)->first();
            $allCatTarget += (float)$cat->target_amount;
            $allCatInvested += (float)$cat->invested_amount;
        }
        $this->assert('Sum of all category targets = program budget_total', $allCatTarget, (float)$program->budget_total);
        $this->assert('Sum of all category invested = program budget_spent', $allCatInvested, (float)$program->budget_spent);
        $this->command->info('');

        // ════════════════════════════════════════════════
        // FINAL REPORT
        // ════════════════════════════════════════════════
        $this->command->info('╔══════════════════════════════════════════════════╗');
        $this->command->info('║               FINAL TEST REPORT                 ║');
        $this->command->info('╚══════════════════════════════════════════════════╝');
        $this->command->info('');

        $total = $this->passed + $this->failed;
        $this->command->info("   Total Assertions: {$total}");
        $this->command->info("   ✅ Passed: {$this->passed}");
        if ($this->failed > 0) {
            $this->command->error("   ❌ Failed: {$this->failed}");
        } else {
            $this->command->info("   ❌ Failed: 0");
        }
        $this->command->info('');

        // Print summary of expected numbers
        $this->command->info('── DATA SUMMARY ──');
        $this->command->info("   Budget Items:      " . count($allItems));
        $this->command->info("   Categories:        " . count($categoryIds));
        $this->command->info("   Contributions:     " . count($allContribs));
        $this->command->info("   Budget Total:      UGX " . number_format($expectedBudgetTotal));
        $this->command->info("   Budget Spent:      UGX " . number_format($expectedBudgetSpent));
        $this->command->info("   Budget Balance:    UGX " . number_format($expectedBudgetBalance));
        $this->command->info("   Total Expected:    UGX " . number_format($expectedTotalExpected));
        $this->command->info("   Total Collected:   UGX " . number_format($expectedTotalCollected));
        $this->command->info("   Total In Pledge:   UGX " . number_format($expectedTotalInPledge));
        $this->command->info('');

        if ($this->failed === 0) {
            $this->command->info('🎉  ALL TESTS PASSED — Budget module is bulletproof!');
        } else {
            $this->command->error('⚠️  SOME TESTS FAILED — see details above.');
            foreach ($this->results as $r) {
                if (!$r['pass']) {
                    $this->command->error("   FAIL: {$r['label']}  expected={$r['expected']}  actual={$r['actual']}");
                }
            }
        }

        $this->command->info('');
        $this->command->info("   Login: test_budget_user@example.com / password123");
        $this->command->info('');
    }

    /**
     * Assert two values are equal and track the result.
     */
    private function assert(string $label, $expected, $actual): void
    {
        $pass = $expected == $actual; // loose comparison for numbers vs strings
        if ($pass) {
            $this->passed++;
            $this->command->info("   ✅ {$label}: {$actual}");
        } else {
            $this->failed++;
            $this->command->error("   ❌ {$label}: expected={$expected} actual={$actual}");
        }
        $this->results[] = [
            'label'    => $label,
            'expected' => $expected,
            'actual'   => $actual,
            'pass'     => $pass,
        ];
    }

    /**
     * Clean up any previous test data.
     */
    private function cleanup(): void
    {
        $testUser = DB::table('admin_users')
            ->where('email', 'test_budget_user@example.com')
            ->first();

        if ($testUser) {
            $companyId = $testUser->company_id;

            if ($companyId) {
                // Delete budget items
                DB::table('budget_items')->where('company_id', $companyId)->delete();
                // Delete budget item categories
                DB::table('budget_item_categories')->where('company_id', $companyId)->delete();
                // Delete contribution records
                DB::table('contribution_records')->where('company_id', $companyId)->delete();
                // Delete budget programs
                DB::table('budget_programs')->where('company_id', $companyId)->delete();
                // Delete financial categories
                DB::table('financial_categories')->where('company_id', $companyId)->delete();
                // Delete company
                DB::table('companies')->where('id', $companyId)->delete();
            }

            // Delete role assignment
            DB::table('admin_role_users')->where('user_id', $testUser->id)->delete();
            // Delete user
            DB::table('admin_users')->where('id', $testUser->id)->delete();

            $this->command->info('   ✓ Previous test data cleaned up');
        }
    }
}
