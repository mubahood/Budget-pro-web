<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseReturn;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Services\Shop\PurchaseReturnService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Widgets\Form as WidgetForm;

/** Goods sent back to suppliers (plan A5, P4-1). */
class PurchaseReturnController extends TenantAdminController
{
    protected $title = 'Returns to supplier';

    protected function grid()
    {
        $grid = new Grid(new PurchaseReturn());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('id');
        $grid->actions(fn ($a) => $a->disableEdit()->disableDelete());
        $suppliers = Supplier::where('company_id', Admin::user()->company_id)->pluck('name', 'id');
        $grid->column('number', 'No.');
        $grid->column('returned_on', 'Date')->display(fn ($v) => substr((string) $v, 0, 10));
        $grid->column('supplier_id', 'Supplier')->display(fn ($v) => $suppliers[$v] ?? '—');
        $grid->column('reason', 'Reason');
        $grid->column('total_value', 'Value')->display(fn ($v) => Money::format($v));
        $grid->column('refund_amount', 'Refunded')->display(fn ($v) => Money::format($v));

        return $grid;
    }

    protected function detail($id)
    {
        $ret = PurchaseReturn::where('company_id', Admin::user()->company_id)->with('items')->findOrFail($id);
        $names = StockItem::withoutGlobalScopes()->whereIn('id', $ret->items->pluck('stock_item_id'))->pluck('name', 'id');
        $show = new Show($ret);
        $show->field('number');
        $show->field('returned_on');
        $show->field('reason');
        $show->field('items', 'Items')->unescape()->as(function () use ($ret, $names) {
            $h = '<table class="table table-condensed"><tr><th>Product</th><th>Qty</th><th class="text-right">Cost</th></tr>';
            foreach ($ret->items as $i) {
                $h .= '<tr><td>'.e($names[$i->stock_item_id] ?? '').'</td><td>'.e((float) $i->quantity).'</td><td class="text-right">'.e(Money::format($i->unit_cost)).'</td></tr>';
            }

            return $h.'</table>';
        });
        $show->field('total_value')->as(fn ($v) => Money::format($v));
        $show->field('refund_amount')->as(fn ($v) => Money::format($v));

        return $show;
    }

    public function create(\Encore\Admin\Layout\Content $content)
    {
        $companyId = Admin::user()->company_id;
        $form = new WidgetForm();
        $form->action(admin_url('purchase-returns'));
        $form->select('supplier_id', 'Supplier')->options(Supplier::where('company_id', $companyId)->pluck('name', 'id'));
        $form->text('reason', 'Reason')->placeholder('Damaged on arrival, expired, wrong item…');
        $products = StockItem::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id');
        $form->table('items', 'Products going back', function ($t) use ($products) {
            $t->select('stock_item_id', 'Product')->options($products);
            $t->decimal('quantity', 'Quantity');
            $t->decimal('unit_cost', 'Cost per piece')->help('Empty = current cost');
        });
        $form->decimal('refund_amount', 'Refund received now')->default(0)->help('Leave 0 if the supplier will credit your account instead.');
        $form->select('refund_method', 'Refund in')->options(['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'])->default('cash');

        return $content->title('Return goods to a supplier')->body($form);
    }

    public function store()
    {
        $lines = array_values(array_filter((array) request('items', []), fn ($l) => ! empty($l['stock_item_id']) && (float) ($l['quantity'] ?? 0) > 0 && empty($l['_remove_'])));
        try {
            $ret = (new PurchaseReturnService())->create((int) Admin::user()->company_id, (int) Admin::user()->id, $lines, request('supplier_id') ? (int) request('supplier_id') : null,
                request('reason') ?: null, (float) request('refund_amount', 0), (string) request('refund_method', 'cash'));
            admin_success('Return recorded', $ret->number.' — '.Money::format($ret->total_value));

            return redirect(admin_url('purchase-returns/'.$ret->id));
        } catch (BusinessRuleException $e) {
            admin_error('Not saved', $e->getMessage());

            return back()->withInput();
        }
    }

    protected function form()
    {
        return new \Encore\Admin\Form(new PurchaseReturn());
    }
}
