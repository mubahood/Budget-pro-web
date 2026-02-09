<?php

namespace App\Http\Controllers;

use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Models\Utils;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile API Controller — dedicated endpoints for the Budget Pro mobile app.
 *
 * All endpoints enforce company_id scoping for multi-tenant data isolation.
 * Response format: { code: 1|0, message: string, data: mixed }
 */
class MobileApiController extends BaseController
{
    // ─── HELPERS ─────────────────────────────────────────────

    /**
     * Authenticate the mobile user from request headers/params.
     * Returns the User or aborts with error JSON.
     */
    private function auth(Request $r): User
    {
        $u = Utils::get_user($r);
        if ($u === null) {
            Utils::error('Unauthenticated. Please log in again.');
        }
        return $u;
    }

    // ─── BUDGET PROGRAMS ─────────────────────────────────────

    /**
     * GET /api/mobile/budget-programs
     *
     * List all budget programs for the user's company, with summary stats.
     * Each program includes: category count, item count, contribution count,
     * and all financial totals.
     */
    public function budgetPrograms(Request $r)
    {
        $u = $this->auth($r);

        $programs = DB::table('budget_programs')
            ->where('budget_programs.company_id', $u->company_id)
            ->select([
                'budget_programs.id',
                'budget_programs.created_at',
                'budget_programs.updated_at',
                'budget_programs.company_id',
                'budget_programs.name',
                'budget_programs.total_collected',
                'budget_programs.total_expected',
                'budget_programs.total_in_pledge',
                'budget_programs.budget_total',
                'budget_programs.budget_spent',
                'budget_programs.budget_balance',
                'budget_programs.status',
                'budget_programs.deadline',
                'budget_programs.rsvp',
                'budget_programs.logo',
                'budget_programs.is_default',
                'budget_programs.is_active',
                'budget_programs.groups',
                'budget_programs.title',
                'budget_programs.bottom',
            ])
            ->orderBy('budget_programs.id', 'desc')
            ->get();

        // Enrich each program with aggregate counts
        foreach ($programs as $program) {
            $program->company_text = '';
            $company = DB::table('companies')->where('id', $program->company_id)->first();
            if ($company) {
                $program->company_text = $company->name ?? '';
            }

            $catStats = DB::table('budget_item_categories')
                ->where('budget_program_id', $program->id)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(target_amount),0) as target_sum, COALESCE(SUM(invested_amount),0) as invested_sum')
                ->first();

            $itemCount = DB::table('budget_items')
                ->where('budget_program_id', $program->id)
                ->count();

            $contribStats = DB::table('contribution_records')
                ->where('budget_program_id', $program->id)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total_pledged, COALESCE(SUM(paid_amount),0) as total_paid, COALESCE(SUM(not_paid_amount),0) as total_unpaid')
                ->first();

            $program->category_count = (int) ($catStats->count ?? 0);
            $program->item_count = (int) $itemCount;
            $program->contribution_count = (int) ($contribStats->count ?? 0);
            $program->contributions_total_pledged = (int) ($contribStats->total_pledged ?? 0);
            $program->contributions_total_paid = (int) ($contribStats->total_paid ?? 0);
            $program->contributions_total_unpaid = (int) ($contribStats->total_unpaid ?? 0);

            // Ensure stored values are correct
            $program->budget_total = (int) ($catStats->target_sum ?? $program->budget_total);
            $program->budget_spent = (int) ($catStats->invested_sum ?? $program->budget_spent);
            $program->budget_balance = $program->budget_total - $program->budget_spent;
        }

        Utils::success($programs, 'Budget programs listed successfully.');
    }

    /**
     * GET /api/mobile/budget-program/{id}
     *
     * Get a single budget program with full details including
     * all categories, items, and contribution summary.
     */
    public function budgetProgramDetail(Request $r, $id)
    {
        $u = $this->auth($r);

        $program = DB::table('budget_programs')
            ->where('id', $id)
            ->where('company_id', $u->company_id)
            ->first();

        if (!$program) {
            Utils::error('Budget program not found.');
        }

        // Get categories with their items
        $categories = DB::table('budget_item_categories')
            ->where('budget_program_id', $program->id)
            ->where('company_id', $u->company_id)
            ->orderBy('target_amount', 'desc')
            ->get();

        foreach ($categories as $cat) {
            $cat->budget_program_text = $program->name ?? '';
            $cat->company_text = '';

            $items = DB::table('budget_items')
                ->where('budget_item_category_id', $cat->id)
                ->where('company_id', $u->company_id)
                ->orderBy('target_amount', 'desc')
                ->get();

            foreach ($items as $item) {
                $item->budget_program_text = $program->name ?? '';
                $item->budget_item_category_text = $cat->name ?? '';
                $item->company_text = '';
                $item->created_by_text = '';
                $item->changed_by_text = '';

                $createdBy = DB::table('admin_users')->where('id', $item->created_by_id)->first();
                if ($createdBy) {
                    $item->created_by_text = $createdBy->name ?? '';
                }
                $changedBy = DB::table('admin_users')->where('id', $item->changed_by_id)->first();
                if ($changedBy) {
                    $item->changed_by_text = $changedBy->name ?? '';
                }
            }

            $cat->items = $items;
            $cat->item_count = count($items);
        }

        // Get contribution summary grouped by category
        $contributionStats = DB::table('contribution_records')
            ->where('budget_program_id', $program->id)
            ->where('company_id', $u->company_id)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total_pledged, COALESCE(SUM(paid_amount),0) as total_paid, COALESCE(SUM(not_paid_amount),0) as total_unpaid')
            ->first();

        $program->categories = $categories;
        $program->category_count = count($categories);
        $program->contribution_summary = $contributionStats;

        Utils::success($program, 'Budget program detail loaded.');
    }

    /**
     * POST /api/mobile/budget-program-save
     *
     * Create or update a budget program.
     */
    public function budgetProgramSave(Request $r)
    {
        $u = $this->auth($r);

        $id = $r->get('id');
        $isEdit = false;
        $object = null;

        if ($id) {
            $object = BudgetProgram::withoutGlobalScopes()->where('id', $id)->where('company_id', $u->company_id)->first();
            if (!$object) {
                Utils::error('Budget program not found or access denied.');
            }
            $isEdit = true;
        } else {
            $object = new BudgetProgram();
        }

        // Validate name
        $name = trim($r->get('name', ''));
        if (empty($name)) {
            Utils::error('Program name is required.');
        }

        // Check duplicate name within company
        $duplicate = BudgetProgram::withoutGlobalScopes()
            ->where('name', $name)
            ->where('company_id', $u->company_id)
            ->when($isEdit, fn($q) => $q->where('id', '!=', $id))
            ->first();
        if ($duplicate) {
            Utils::error('A program with this name already exists.');
        }

        $fillable = ['name', 'status', 'deadline', 'rsvp', 'logo', 'is_default', 'is_active', 'groups', 'title', 'bottom'];
        foreach ($fillable as $field) {
            $val = $r->get($field);
            if ($val !== null && $val !== '') {
                $object->$field = $val;
            }
        }

        $object->company_id = $u->company_id;

        try {
            $object->saveQuietly(); // skip boot hooks — we handle validation above
        } catch (\Exception $e) {
            Utils::error('Failed to save: ' . $e->getMessage());
        }

        $saved = BudgetProgram::withoutGlobalScopes()->find($object->id);
        Utils::success($saved, $isEdit ? 'Program updated successfully.' : 'Program created successfully.');
    }

    // ─── BUDGET ITEM CATEGORIES ──────────────────────────────

    /**
     * GET /api/mobile/budget-categories
     *
     * List categories. Optionally filter by budget_program_id.
     */
    public function budgetCategories(Request $r)
    {
        $u = $this->auth($r);

        $query = DB::table('budget_item_categories')
            ->where('company_id', $u->company_id);

        $programId = $r->get('budget_program_id');
        if ($programId) {
            $query->where('budget_program_id', $programId);
        }

        $categories = $query->orderBy('id', 'desc')->get();

        foreach ($categories as $cat) {
            $cat->budget_program_text = '';
            $program = DB::table('budget_programs')->where('id', $cat->budget_program_id)->first();
            if ($program) {
                $cat->budget_program_text = $program->name ?? '';
            }
            $cat->company_text = '';

            $cat->item_count = DB::table('budget_items')
                ->where('budget_item_category_id', $cat->id)
                ->count();
        }

        Utils::success($categories, 'Categories listed successfully.');
    }

    /**
     * POST /api/mobile/budget-category-save
     *
     * Create or update a budget item category.
     */
    public function budgetCategorySave(Request $r)
    {
        $u = $this->auth($r);

        $id = $r->get('id');
        $isEdit = false;
        $object = null;

        if ($id) {
            $object = BudgetItemCategory::withoutGlobalScopes()->where('id', $id)->where('company_id', $u->company_id)->first();
            if (!$object) {
                Utils::error('Category not found or access denied.');
            }
            $isEdit = true;
        } else {
            $object = new BudgetItemCategory();
        }

        $name = trim($r->get('name', ''));
        $programId = $r->get('budget_program_id');

        if (empty($name)) {
            Utils::error('Category name is required.');
        }
        if (empty($programId)) {
            Utils::error('Budget program is required.');
        }

        // Verify program belongs to user's company
        $program = BudgetProgram::withoutGlobalScopes()->where('id', $programId)->where('company_id', $u->company_id)->first();
        if (!$program) {
            Utils::error('Budget program not found or access denied.');
        }

        // Check duplicate name within program
        $duplicate = BudgetItemCategory::withoutGlobalScopes()
            ->where('name', $name)
            ->where('budget_program_id', $programId)
            ->when($isEdit, fn($q) => $q->where('id', '!=', $id))
            ->first();
        if ($duplicate) {
            Utils::error('A category with this name already exists in this program.');
        }

        $object->name = $name;
        $object->budget_program_id = $programId;
        $object->company_id = $u->company_id;

        // Set defaults for new categories
        if (!$isEdit) {
            $object->target_amount = 0;
            $object->invested_amount = 0;
            $object->balance = 0;
            $object->percentage_done = 0;
            $object->is_complete = 'No';
        }

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error('Failed to save: ' . $e->getMessage());
        }

        $saved = BudgetItemCategory::withoutGlobalScopes()->find($object->id);
        $saved->budget_program_text = $program->name;
        $saved->company_text = '';

        Utils::success($saved, $isEdit ? 'Category updated.' : 'Category created.');
    }

    // ─── BUDGET ITEMS ────────────────────────────────────────

    /**
     * GET /api/mobile/budget-items
     *
     * List items. Optionally filter by budget_item_category_id or budget_program_id.
     */
    public function budgetItems(Request $r)
    {
        $u = $this->auth($r);

        $query = DB::table('budget_items')
            ->where('budget_items.company_id', $u->company_id);

        if ($r->get('budget_item_category_id')) {
            $query->where('budget_item_category_id', $r->get('budget_item_category_id'));
        }
        if ($r->get('budget_program_id')) {
            $query->where('budget_program_id', $r->get('budget_program_id'));
        }

        $items = $query->orderBy('id', 'desc')->get();

        foreach ($items as $item) {
            $item->budget_program_text = '';
            $item->budget_item_category_text = '';
            $item->company_text = '';
            $item->created_by_text = '';
            $item->changed_by_text = '';

            $cat = DB::table('budget_item_categories')->where('id', $item->budget_item_category_id)->first();
            if ($cat) {
                $item->budget_item_category_text = $cat->name ?? '';
            }
            $program = DB::table('budget_programs')->where('id', $item->budget_program_id)->first();
            if ($program) {
                $item->budget_program_text = $program->name ?? '';
            }
            $createdBy = DB::table('admin_users')->where('id', $item->created_by_id)->first();
            if ($createdBy) {
                $item->created_by_text = $createdBy->name ?? '';
            }
            $changedBy = DB::table('admin_users')->where('id', $item->changed_by_id)->first();
            if ($changedBy) {
                $item->changed_by_text = $changedBy->name ?? '';
            }
        }

        Utils::success($items, 'Budget items listed successfully.');
    }

    /**
     * POST /api/mobile/budget-item-save
     *
     * Create or update a budget item. Triggers category and program recalculation.
     */
    public function budgetItemSave(Request $r)
    {
        $u = $this->auth($r);

        $id = $r->get('id');
        $isEdit = false;
        $object = null;

        if ($id) {
            $object = BudgetItem::withoutGlobalScopes()->where('id', $id)->where('company_id', $u->company_id)->first();
            if (!$object) {
                Utils::error('Budget item not found or access denied.');
            }
            $isEdit = true;
        } else {
            $object = new BudgetItem();
        }

        // Validate required fields
        $name = trim($r->get('name', ''));
        $categoryId = $r->get('budget_item_category_id');
        $unitPrice = (int) $r->get('unit_price', 0);
        $quantity = (int) $r->get('quantity', 0);

        if (empty($name)) {
            Utils::error('Item name is required.');
        }
        if (empty($categoryId)) {
            Utils::error('Budget category is required.');
        }
        if ($unitPrice <= 0) {
            Utils::error('Unit price must be greater than zero.');
        }
        if ($quantity <= 0) {
            Utils::error('Quantity must be greater than zero.');
        }

        // Verify category belongs to user's company
        $cat = BudgetItemCategory::withoutGlobalScopes()->where('id', $categoryId)->where('company_id', $u->company_id)->first();
        if (!$cat) {
            Utils::error('Budget category not found or access denied.');
        }

        // Check duplicate name within category
        $duplicate = BudgetItem::withoutGlobalScopes()
            ->where('name', $name)
            ->where('budget_item_category_id', $categoryId)
            ->when($isEdit, fn($q) => $q->where('id', '!=', $id))
            ->first();
        if ($duplicate) {
            Utils::error('An item with this name already exists in this category.');
        }

        $object->name = $name;
        $object->budget_item_category_id = $categoryId;
        $object->budget_program_id = $cat->budget_program_id;
        $object->company_id = $u->company_id;
        $object->unit_price = $unitPrice;
        $object->quantity = $quantity;
        $object->target_amount = $unitPrice * $quantity;
        $object->invested_amount = max(0, (int) $r->get('invested_amount', $object->invested_amount ?? 0));
        $object->details = $r->get('details', $object->details ?? '');
        $object->created_by_id = $object->created_by_id ?? $u->id;
        $object->changed_by_id = $u->id;

        // Calculate derived fields
        $object->balance = $object->target_amount - $object->invested_amount;
        $object->percentage_done = $object->target_amount > 0
            ? round(($object->invested_amount / $object->target_amount) * 100)
            : 0;
        $object->is_complete = ($object->percentage_done >= 98 || $object->balance <= 0) ? 'Yes' : 'No';

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error('Failed to save: ' . $e->getMessage());
        }

        // Trigger cascade: category → program recalculation
        try {
            $cat->updateSelf();
        } catch (\Throwable $th) {
            // Log but don't fail the response
        }

        $saved = DB::table('budget_items')->where('id', $object->id)->first();
        $saved->budget_program_text = '';
        $saved->budget_item_category_text = $cat->name ?? '';
        $saved->company_text = '';
        $saved->created_by_text = '';
        $saved->changed_by_text = '';

        $createdBy = DB::table('admin_users')->where('id', $saved->created_by_id)->first();
        if ($createdBy) {
            $saved->created_by_text = $createdBy->name ?? '';
        }
        $changedBy = DB::table('admin_users')->where('id', $saved->changed_by_id)->first();
        if ($changedBy) {
            $saved->changed_by_text = $changedBy->name ?? '';
        }
        $program = DB::table('budget_programs')->where('id', $saved->budget_program_id)->first();
        if ($program) {
            $saved->budget_program_text = $program->name ?? '';
        }

        Utils::success($saved, $isEdit ? 'Item updated.' : 'Item created.');
    }

    // ─── CONTRIBUTION RECORDS ────────────────────────────────

    /**
     * GET /api/mobile/contribution-records
     *
     * List contribution records. Optionally filter by budget_program_id.
     * Includes contributor name, amounts, treasurer info, category.
     */
    public function contributionRecords(Request $r)
    {
        $u = $this->auth($r);

        $query = DB::table('contribution_records')
            ->where('contribution_records.company_id', $u->company_id);

        if ($r->get('budget_program_id')) {
            $query->where('budget_program_id', $r->get('budget_program_id'));
        }

        $records = $query->orderBy('id', 'desc')->get();

        foreach ($records as $rec) {
            $rec->budget_program_text = '';
            $rec->company_text = '';
            $rec->treasurer_text = '';
            $rec->chaned_by_text = '';
            $rec->category_text = $rec->category_id ?? '';

            $program = DB::table('budget_programs')->where('id', $rec->budget_program_id)->first();
            if ($program) {
                $rec->budget_program_text = $program->name ?? '';
            }
            $treasurer = DB::table('admin_users')->where('id', $rec->treasurer_id)->first();
            if ($treasurer) {
                $rec->treasurer_text = $treasurer->name ?? '';
            }
            $changedBy = DB::table('admin_users')->where('id', $rec->chaned_by_id)->first();
            if ($changedBy) {
                $rec->chaned_by_text = $changedBy->name ?? '';
            }
        }

        Utils::success($records, 'Contribution records listed successfully.');
    }

    /**
     * POST /api/mobile/contribution-record-save
     *
     * Create or update a contribution record. Triggers program recalculation.
     */
    public function contributionRecordSave(Request $r)
    {
        $u = $this->auth($r);

        $id = $r->get('id');
        $isEdit = false;
        $object = null;

        if ($id) {
            $object = ContributionRecord::withoutGlobalScopes()->where('id', $id)->where('company_id', $u->company_id)->first();
            if (!$object) {
                Utils::error('Contribution record not found or access denied.');
            }
            $isEdit = true;
        } else {
            $object = new ContributionRecord();
        }

        // Validate
        $name = trim($r->get('name', ''));
        $programId = $r->get('budget_program_id');
        $treasurerId = $r->get('treasurer_id');

        if (empty($name)) {
            Utils::error('Contributor name is required.');
        }
        if (empty($programId)) {
            Utils::error('Budget program is required.');
        }

        // Verify program belongs to user's company
        $program = BudgetProgram::withoutGlobalScopes()->where('id', $programId)->where('company_id', $u->company_id)->first();
        if (!$program) {
            Utils::error('Budget program not found or access denied.');
        }

        // Verify treasurer if provided (optional)
        $treasurer = null;
        if (!empty($treasurerId)) {
            $treasurer = User::where('id', $treasurerId)->where('company_id', $u->company_id)->first();
        }

        // Check duplicate name within program (only on create)
        if (!$isEdit) {
            $duplicate = ContributionRecord::withoutGlobalScopes()
                ->where('name', $name)
                ->where('budget_program_id', $programId)
                ->first();
            if ($duplicate) {
                Utils::error('A contribution record with this name already exists in this program.');
            }
        }

        // Process amounts
        $amount = max(0, (int) $r->get('amount', 0));
        $paidAmount = max(0, (int) $r->get('paid_amount', 0));
        $customAmount = (int) $r->get('custom_amount', 0);
        $customPaidAmount = (int) $r->get('custom_paid_amount', 0);
        $fullyPaid = $r->get('fully_paid', 'No');

        // Apply custom amounts if provided
        if ($customAmount > 0) {
            $amount = $customAmount;
        }
        if ($customPaidAmount > 0) {
            $paidAmount = $customPaidAmount;
        }

        if ($amount <= 0) {
            Utils::error('Pledge amount must be greater than zero.');
        }

        // Clamp paid_amount
        if ($paidAmount > $amount) {
            $paidAmount = $amount;
        }

        // Calculate fully_paid and not_paid
        if ($fullyPaid === 'Yes') {
            $paidAmount = $amount;
            $notPaid = 0;
        } else {
            $notPaid = $amount - $paidAmount;
        }

        if ($paidAmount >= $amount && $amount > 0) {
            $fullyPaid = 'Yes';
            $notPaid = 0;
        }

        $object->name = $name;
        $object->budget_program_id = $programId;
        $object->company_id = $u->company_id;
        $object->treasurer_id = $treasurer ? $treasurer->id : ($object->treasurer_id ?? $u->id);
        $object->chaned_by_id = $u->id;
        $object->amount = $amount;
        $object->paid_amount = $paidAmount;
        $object->not_paid_amount = $notPaid;
        $object->fully_paid = $fullyPaid;
        $object->category_id = $r->get('category_id', $object->category_id ?? 'Family');

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error('Failed to save: ' . $e->getMessage());
        }

        // Clear custom fields and trigger program recalculation
        DB::table('contribution_records')
            ->where('id', $object->id)
            ->update(['custom_paid_amount' => null, 'custom_amount' => null]);

        try {
            BudgetProgram::recalculateFromChildren((int) $programId);
        } catch (\Throwable $th) {
            // Log but don't fail
        }

        // Return enriched record
        $saved = DB::table('contribution_records')->where('id', $object->id)->first();
        $saved->budget_program_text = $program->name ?? '';
        $saved->company_text = '';
        $saved->treasurer_text = $treasurer ? ($treasurer->name ?? '') : ($u->name ?? '');
        $saved->chaned_by_text = $u->name ?? '';
        $saved->category_text = $saved->category_id ?? '';

        Utils::success($saved, $isEdit ? 'Contribution updated.' : 'Contribution created.');
    }

    // ─── DASHBOARD / SUMMARY ─────────────────────────────────

    /**
     * GET /api/mobile/dashboard
     *
     * Returns a compact dashboard summary for the user's company:
     * - Active program count, total budget, total spent, total contributions
     * - Recent contributions (last 10)
     * - Active programs list (name + totals)
     */
    public function dashboard(Request $r)
    {
        $u = $this->auth($r);
        $companyId = $u->company_id;

        // Budget summary
        $budgetSummary = DB::table('budget_programs')
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as program_count, COALESCE(SUM(budget_total),0) as total_budget, COALESCE(SUM(budget_spent),0) as total_spent, COALESCE(SUM(budget_balance),0) as total_balance')
            ->first();

        // Contribution summary
        $contribSummary = DB::table('contribution_records')
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total_pledged, COALESCE(SUM(paid_amount),0) as total_collected, COALESCE(SUM(not_paid_amount),0) as total_unpaid')
            ->first();

        // Active programs
        $activePrograms = DB::table('budget_programs')
            ->where('company_id', $companyId)
            ->where('status', 'Active')
            ->select(['id', 'name', 'budget_total', 'budget_spent', 'budget_balance', 'total_expected', 'total_collected', 'total_in_pledge'])
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Recent contributions
        $recentContributions = DB::table('contribution_records')
            ->where('company_id', $companyId)
            ->select(['id', 'name', 'amount', 'paid_amount', 'not_paid_amount', 'fully_paid', 'category_id', 'created_at'])
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Company info
        $company = DB::table('companies')->where('id', $companyId)->first();

        Utils::success([
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'company_id' => $u->company_id,
            ],
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'currency' => $company->currency ?? 'UGX',
            ] : null,
            'budget_summary' => $budgetSummary,
            'contribution_summary' => $contribSummary,
            'active_programs' => $activePrograms,
            'recent_contributions' => $recentContributions,
        ], 'Dashboard loaded.');
    }

    // ─── GENERIC LIST (backward-compatible) ──────────────────

    /**
     * GET /api/mobile/list/{model}
     *
     * Generic model listing, company-scoped. Kept for backward compatibility
     * with non-budget models (employees, financial periods, etc.).
     */
    public function genericList(Request $r, $model)
    {
        $u = $this->auth($r);

        // Whitelist allowed models for security
        $allowed = [
            'User'              => User::class,
            'BudgetProgram'     => BudgetProgram::class,
            'BudgetItemCategory'=> BudgetItemCategory::class,
            'BudgetItem'        => BudgetItem::class,
            'ContributionRecord'=> ContributionRecord::class,
        ];

        if (!isset($allowed[$model])) {
            Utils::error('Invalid model: ' . $model);
        }

        $modelClass = $allowed[$model];
        $data = $modelClass::withoutGlobalScopes()
            ->where('company_id', $u->company_id)
            ->orderBy('id', 'desc')
            ->limit(10000)
            ->get();

        Utils::success($data, 'Listed successfully.');
    }

    // ─── GENERIC SAVE (backward-compatible) ──────────────────

    /**
     * POST /api/mobile/save/{model}
     *
     * Generic model save, company-scoped. Kept for backward compatibility.
     */
    public function genericSave(Request $r, $model)
    {
        $u = $this->auth($r);

        $allowed = [
            'User'              => User::class,
            'BudgetProgram'     => BudgetProgram::class,
            'BudgetItemCategory'=> BudgetItemCategory::class,
            'BudgetItem'        => BudgetItem::class,
            'ContributionRecord'=> ContributionRecord::class,
        ];

        if (!isset($allowed[$model])) {
            Utils::error('Invalid model: ' . $model);
        }

        $modelClass = $allowed[$model];
        $object = null;
        $isEdit = false;

        $id = $r->get('id');
        if ($id) {
            $object = $modelClass::withoutGlobalScopes()->where('id', $id)->where('company_id', $u->company_id)->first();
            if (!$object) {
                Utils::error('Record not found or access denied.');
            }
            $isEdit = true;
        } else {
            $object = new $modelClass();
        }

        $table = $object->getTable();
        $columns = Schema::getColumnListing($table);
        $except = ['id', 'created_at', 'updated_at'];

        foreach ($r->all() as $key => $value) {
            if (!in_array($key, $columns)) continue;
            if (in_array($key, $except)) continue;
            if ($value === null || $value === '') continue;
            $object->$key = $value;
        }

        $object->company_id = $u->company_id;

        try {
            $object->saveQuietly();
        } catch (\Exception $e) {
            Utils::error('Failed to save: ' . $e->getMessage());
        }

        $saved = $modelClass::withoutGlobalScopes()->find($object->id);
        Utils::success($saved, $isEdit ? 'Updated.' : 'Created.');
    }
}
