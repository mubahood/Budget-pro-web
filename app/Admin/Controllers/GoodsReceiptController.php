<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Services\Shop\GoodsReceiptService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Widgets\Form as WidgetForm;

/** Receive stock (GRN-lite, P2-7/P2-10). Created through GoodsReceiptService (same path as API and sync). */
class GoodsReceiptController extends TenantAdminController
{
    protected $title = 'Receive stock';

    protected function grid()
    {
        $grid = new Grid(new GoodsReceipt());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('id');
        $grid->actions(fn ($a) => $a->disableEdit()->disableDelete());
        $grid->column('number', 'No.');
        $grid->column('received_on', 'Date');
        $grid->column('supplier.name', 'Supplier');
        $grid->column('invoice_ref', 'Invoice');
        $grid->column('total_cost', 'Total')->display(fn ($v) => Money::format($v));
        $grid->column('amount_paid', 'Paid')->display(fn ($v) => Money::format($v));

        return $grid;
    }

    protected function detail($id)
    {
        $grn = GoodsReceipt::where('company_id', Admin::user()->company_id)->with('items.product')->findOrFail($id);
        $show = new Show($grn);
        $show->field('number');
        $show->field('received_on');
        $show->field('invoice_ref');
        $show->field('items', 'Items')->unescape()->as(function () use ($grn) {
            $h = '<table class="table table-condensed"><tr><th>Product</th><th>Qty</th><th class="text-right">Unit cost</th><th class="text-right">Line</th></tr>';
            foreach ($grn->items as $i) {
                $h .= '<tr><td>'.e($i->product?->name).'</td><td>'.e((float) $i->quantity).'</td><td class="text-right">'.e(Money::format($i->unit_cost)).'</td><td class="text-right">'.e(Money::format((float) $i->quantity * (float) $i->unit_cost)).'</td></tr>';
            }

            return $h.'</table>';
        });
        $show->field('total_cost')->as(fn ($v) => Money::format($v));
        $show->field('amount_paid')->as(fn ($v) => Money::format($v));

        return $show;
    }

    public function create(\Encore\Admin\Layout\Content $content)
    {
        $companyId = Admin::user()->company_id;
        $form = new WidgetForm();
        $form->action(admin_url('goods-receipts'));
        $form->select('supplier_id', 'Supplier')->options(Supplier::where('company_id', $companyId)->pluck('name', 'id'))->default(request('supplier_id'));
        $form->text('invoice_ref', 'Supplier invoice no.');
        $form->date('received_on', 'Received on')->default(now()->toDateString());
        $rows = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->orderBy('name')->get(['id', 'name', 'buying_price']);
        $products = $rows->mapWithKeys(fn ($p) => [$p->id => $p->name.' (cost '.Money::format($p->buying_price).')']);
        $costs = $rows->mapWithKeys(fn ($p) => [$p->id => (float) $p->buying_price]);
        $form->table('items', 'Products received', function ($t) use ($products) {
            $t->select('stock_item_id', 'Product')->options($products);
            $t->decimal('quantity', 'Quantity');
            $t->decimal('unit_cost', 'Cost per piece')->help('Filled with the product\'s current cost; change it if the supplier charged differently.');
            $t->text('batch_number', 'Batch (optional)');
            $t->date('expiry_date', 'Expires (optional)');
        });
        $locations = \Illuminate\Support\Facades\DB::table('locations')->where('company_id', $companyId)->where('is_active', true)->pluck('name', 'id');
        if ($locations->count() > 1) {
            $form->select('location_id', 'Received at')->options($locations)->default(\App\Services\Shop\LocationStock::defaultLocation((int) $companyId));
        }
        $form->decimal('amount_paid', 'Paid now')->default(0)->help('Anything unpaid becomes what you owe the supplier.');
        $form->select('payment_method', 'Paid with')->options(['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'])->default('cash');

        // Pre-fill the cost with the product's buying price when a product is picked (blank also means that price).
        \Encore\Admin\Facades\Admin::script('var bpCosts = '.json_encode($costs).';'
            .'$(document).off("change.bpcost").on("change.bpcost", "select[name$=\'[stock_item_id]\']", function () {'
            .'var cost = bpCosts[this.value]; var input = $(this).closest("tr, .form-group, .has-many-items-form, table").find("input[name$=\'[unit_cost]\']").first();'
            .'if (input.length && !input.val() && cost !== undefined) { input.val(cost); } });');

        return $content->title('Receive stock')->body($form);
    }

    public function store()
    {
        $lines = array_values(array_filter((array) request('items', []), fn ($l) => ! empty($l['stock_item_id']) && (float) ($l['quantity'] ?? 0) > 0 && empty($l['_remove_'])));
        try {
            $grn = (new GoodsReceiptService())->receive((int) Admin::user()->company_id, (int) Admin::user()->id, $lines, request('supplier_id') ? (int) request('supplier_id') : null,
                request('invoice_ref'), (float) request('amount_paid', 0), (string) request('payment_method', 'cash'), request('received_on'), null, null, null, null, request('location_id') ? (int) request('location_id') : null);
            admin_success('Stock received', $grn->number.' — '.Money::format($grn->total_cost));

            return redirect(admin_url('goods-receipts/'.$grn->id));
        } catch (BusinessRuleException $e) {
            admin_error('Not saved', $e->getMessage());

            return back()->withInput();
        }
    }

    protected function form()
    {
        return new \Encore\Admin\Form(new GoodsReceipt());
    }
}
