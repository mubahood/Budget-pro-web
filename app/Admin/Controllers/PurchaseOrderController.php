<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Services\Shop\PurchaseOrderService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;

/** Purchase orders on the web (plan A5, P4-1): draft → send (WhatsApp) → receive (partials) → closed. */
class PurchaseOrderController extends TenantAdminController
{
    protected $title = 'Purchase orders';

    private function orders(): PurchaseOrderService
    {
        return new PurchaseOrderService();
    }

    private function find($id): PurchaseOrder
    {
        return PurchaseOrder::withoutGlobalScopes()->where('company_id', Admin::user()->company_id)->with(['supplier', 'items.product'])->findOrFail($id);
    }

    protected function grid()
    {
        $grid = new Grid(new PurchaseOrder());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('id');
        $grid->actions(fn ($a) => $a->disableEdit()->disableDelete());
        $grid->filter(function ($f) {
            $f->disableIdFilter();
            $f->equal('status')->select(array_combine(PurchaseOrder::STATUSES, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), PurchaseOrder::STATUSES)));
            $f->equal('supplier_id', 'Supplier')->select(Supplier::where('company_id', Admin::user()->company_id)->pluck('name', 'id'));
        });
        $grid->column('number', 'No.');
        $grid->column('order_date', 'Date')->display(fn ($v) => $v ? substr((string) $v, 0, 10) : '');
        $grid->column('supplier.name', 'Supplier');
        $grid->column('status', 'Status')->label(['draft' => 'default', 'sent' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'cancelled' => 'danger']);
        $grid->column('expected_date', 'Expected')->display(fn ($v) => $v ? substr((string) $v, 0, 10) : '');
        $grid->column('subtotal', 'Total')->display(fn ($v) => Money::format($v));

        return $grid;
    }

    public function show($id, Content $content)
    {
        $po = $this->find($id);
        $progress = $this->orders()->progress($po);

        return $content->title('Purchase order '.$po->number)->body(view('admin.purchase-order', ['po' => $po, 'progress' => $progress, 'text' => $this->orders()->text($po)]));
    }

    public function create(Content $content)
    {
        $companyId = Admin::user()->company_id;
        $form = new WidgetForm();
        $form->action(admin_url('purchase-orders'));
        $form->select('supplier_id', 'Supplier')->options(Supplier::where('company_id', $companyId)->pluck('name', 'id'))->default(request('supplier_id'));
        $form->date('expected_date', 'Needed by');
        $products = StockItem::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id');
        $form->table('items', 'Products', function ($t) use ($products) {
            $t->select('stock_item_id', 'Product')->options($products);
            $t->decimal('quantity', 'Quantity');
            $t->decimal('unit_cost', 'Cost per piece')->help('Empty = last cost');
        });
        $form->textarea('notes', 'Note to supplier')->rows(2);

        return $content->title('New purchase order')->body($form);
    }

    public function store()
    {
        $lines = array_values(array_filter((array) request('items', []), fn ($l) => ! empty($l['stock_item_id']) && (float) ($l['quantity'] ?? 0) > 0 && empty($l['_remove_'])));
        try {
            $po = $this->orders()->create((int) Admin::user()->company_id, (int) Admin::user()->id, $lines, request('supplier_id') ? (int) request('supplier_id') : null, request('expected_date') ?: null, request('notes') ?: null);
            admin_success('Draft saved', $po->number.' — '.Money::format($po->subtotal).'. Send it to the supplier when ready.');

            return redirect(admin_url('purchase-orders/'.$po->id));
        } catch (BusinessRuleException $e) {
            admin_error('Not saved', $e->getMessage());

            return back()->withInput();
        }
    }

    public function send($id)
    {
        try {
            $r = $this->orders()->send($this->find($id), (bool) request('via_api'));
        } catch (BusinessRuleException $e) {
            admin_error('Not sent', $e->getMessage());

            return back();
        }
        if ($r['whatsapp_url'] && ! request('via_api')) {
            return redirect()->away($r['whatsapp_url']);
        }
        admin_success('Order sent', request('via_api') ? 'The supplier was messaged.' : 'Marked as sent. The supplier has no phone number — copy the text below.');

        return redirect(admin_url('purchase-orders/'.$id));
    }

    public function receive($id)
    {
        $lines = [];
        foreach ((array) request('received', []) as $itemId => $row) {
            $lines[] = ['purchase_order_item_id' => (int) $itemId, 'quantity' => $row['quantity'] ?? 0, 'unit_cost' => $row['unit_cost'] ?? null];
        }
        try {
            $grn = $this->orders()->receive($this->find($id), (int) Admin::user()->id, $lines, (float) request('amount_paid', 0), (string) request('payment_method', 'cash'), request('invoice_ref') ?: null);
            admin_success('Stock received', $grn->number.' — '.Money::format($grn->total_cost));
        } catch (BusinessRuleException $e) {
            admin_error('Not received', $e->getMessage());
        }

        return redirect(admin_url('purchase-orders/'.$id));
    }

    public function cancel($id)
    {
        try {
            $this->orders()->cancel($this->find($id));
            admin_success('Order closed', 'Anything not yet delivered is no longer expected.');
        } catch (BusinessRuleException $e) {
            admin_error('Not closed', $e->getMessage());
        }

        return redirect(admin_url('purchase-orders/'.$id));
    }

    protected function form()
    {
        return new \Encore\Admin\Form(new PurchaseOrder());
    }
}
