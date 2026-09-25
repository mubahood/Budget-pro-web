<?php

namespace Tests\Feature\Admin;

use App\Models\BudgetProgram;
use App\Models\DataExport;
use App\Models\FinancialCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P0-2: admin-panel show/edit/delete of another tenant's row must 404, for
 * every model carrying CompanyScope, now that the scope is live under the
 * `admin` guard.
 */
class TenantIdorTest extends AdminTestCase
{
    private function rowsFor(int $companyId): array
    {
        $program = new BudgetProgram();
        $program->company_id = $companyId;
        $program->name = 'Program '.uniqid();
        $program->save();

        $category = new FinancialCategory();
        $category->company_id = $companyId;
        $category->name = 'Cat '.uniqid();
        $category->save();

        $export = new DataExport();
        $export->company_id = $companyId;
        $export->category_id = 'Family';
        $export->save();

        return ['budget-programs' => $program->id, 'financial-categories' => $category->id, 'data-exports' => $export->id];
    }

    public function test_show_edit_and_delete_of_another_tenants_rows_return_404(): void
    {
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');
        $theirs = $this->rowsFor($b['company']->id);

        foreach ($theirs as $resource => $id) {
            $this->assertSame(404, $this->asAdmin($a['user'])->get("/{$resource}/{$id}")->getStatusCode(), "show /{$resource}/{$id}");
            $this->assertSame(404, $this->asAdmin($a['user'])->get("/{$resource}/{$id}/edit")->getStatusCode(), "edit /{$resource}/{$id}");
            $this->assertSame(404, $this->asAdmin($a['user'])->put("/{$resource}/{$id}", ['name' => 'Hijacked'])->getStatusCode(), "update /{$resource}/{$id}");
            $this->assertSame(404, $this->asAdmin($a['user'])->delete("/{$resource}/{$id}")->getStatusCode(), "delete /{$resource}/{$id}");
        }
    }

    public function test_own_rows_are_still_reachable(): void
    {
        $a = $this->makeTenant('company');
        $mine = $this->rowsFor($a['company']->id);

        foreach ($mine as $resource => $id) {
            $this->asAdmin($a['user'])->get("/{$resource}/{$id}")->assertOk();
            $this->asAdmin($a['user'])->get("/{$resource}/{$id}/edit")->assertOk();
        }
    }

    public function test_company_scope_is_live_for_the_admin_guard(): void
    {
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');
        $this->rowsFor($a['company']->id);
        $this->rowsFor($b['company']->id);

        $this->asAdmin($a['user']);
        $this->assertSame(1, BudgetProgram::count());
        $this->assertSame((int) $a['company']->id, (int) BudgetProgram::first()->company_id);
    }

    public function test_another_tenants_employee_is_not_visible_or_deletable(): void
    {
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');

        $this->asAdmin($a['user'])->get('/employees/'.$b['user']->id)->assertNotFound();
        $this->asAdmin($a['user'])->get('/employees/'.$b['user']->id.'/edit')->assertNotFound();
        $this->asAdmin($a['user'])->delete('/employees/'.$b['user']->id)->assertNotFound();
        $this->assertNotNull(User::find($b['user']->id));
    }

    public function test_a_changed_hidden_shop_field_cannot_write_into_another_shop(): void
    {
        $mine = $this->makeTenant('company');
        $other = $this->makeTenant('company');
        \App\Models\FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $mine['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = DB::table('financial_categories')->insertGetId(['company_id' => $mine['company']->id, 'name' => 'Rent', 'created_at' => now(), 'updated_at' => now()]);
        $this->asAdmin($mine['user'])->post('/financial-records', ['financial_category_id' => $cat, 'type' => 'Expense', 'date' => now()->toDateString(), 'amount' => '1500',
            'payment_method' => 'Cash', 'description' => 'Tampered', 'company_id' => $other['company']->id, 'created_by_id' => $other['user']->id, 'user_id' => $other['user']->id]);
        $row = DB::table('financial_records')->where('description', 'Tampered')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $mine['company']->id, (int) $row->company_id);
        $this->assertSame((int) $mine['user']->id, (int) $row->created_by_id);
        $this->assertSame(0, DB::table('financial_records')->where('company_id', $other['company']->id)->count());
    }
}
