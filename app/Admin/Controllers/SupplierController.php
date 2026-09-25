<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Supplier;
use App\Services\Shop\SupplierService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/** Suppliers (basic, P2-4/P2-10). */
class SupplierController extends TenantAdminController
{
    protected $title = 'Suppliers';

    protected function grid()
    {
        $grid = new Grid(new Supplier());
        $grid->model()->where('company_id', Admin::user()->company_id)->where('is_deleted', 0)->orderByDesc('balance')->orderBy('name');
        $grid->quickSearch('name', 'phone');
        $grid->filter(function ($f) {
            $f->disableIdFilter();
            $f->like('name', 'Name');
            $f->where(fn ($q) => $q->where('balance', '>', 0), 'We owe them', 'owed')->radio(['' => 'All suppliers', '1' => 'Only suppliers we owe']);
        });
        $grid->actions(function ($actions) {
            $row = $actions->row;
            if ((float) $row->balance > 0) {
                $actions->prepend('<a class="btn btn-xs btn-primary" href="'.admin_url('suppliers/'.$row->id.'/pay').'"><i class="fa fa-money"></i> Pay</a> ');
            }
            $actions->prepend('<a class="btn btn-xs btn-default" href="'.admin_url('goods-receipts/create?supplier_id='.$row->id).'"><i class="fa fa-truck"></i> Receive stock</a> ');
        });
        $grid->setActionClass(\Encore\Admin\Grid\Displayers\Actions::class);
        $grid->column('name')->sortable();
        $grid->column('phone');
        $grid->column('balance', 'We owe')->display(fn ($b) => (float) $b > 0 ? '<strong style="color:#c0392b">'.e(Money::format($b)).'</strong>' : e(Money::format($b)))->sortable();
        $grid->column('payment_terms_days', 'Terms (days)');

        return $grid;
    }

    protected function detail($id)
    {
        $s = Supplier::where('company_id', Admin::user()->company_id)->findOrFail($id);
        $show = new Show($s);
        $show->panel()->tools(fn ($t) => $t->append('<a class="btn btn-sm btn-success" href="'.admin_url('goods-receipts/create?supplier_id='.$s->id).'"><i class="fa fa-truck"></i> Receive stock</a>&nbsp;'
            .'<a class="btn btn-sm btn-primary" href="'.admin_url('suppliers/'.$s->id.'/pay').'"><i class="fa fa-money"></i> Pay supplier</a>&nbsp;'));
        $show->field('name');
        $show->field('phone');
        $show->field('email');
        $show->field('balance', 'We owe')->as(fn ($b) => Money::format($b));
        $show->field('notes');

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Supplier());
        $form->hidden('company_id')->default(Admin::user()->company_id);
        $form->text('name')->required();
        $form->text('phone');
        $form->email('email');
        $form->text('address');
        $form->number('payment_terms_days', 'Payment terms (days)')->default(0)->min(0);
        $form->number('lead_time_days', 'Days to deliver')->default(7)->min(0)->help('Used by the reorder list: order enough to last until the next delivery.');
        $form->textarea('notes')->rows(2);
        $form->saving(fn (Form $form) => $form->company_id = Admin::user()->company_id);

        return $form;
    }

    public function payForm($id)
    {
        $s = Supplier::where('company_id', Admin::user()->company_id)->findOrFail($id);

        return Admin::content(function ($content) use ($s) {
            $content->title('Pay '.$s->name)->description('We owe '.Money::format($s->balance));
            $form = new \Encore\Admin\Widgets\Form();
            $form->action(admin_url('suppliers/'.$s->id.'/pay'));
            $form->decimal('amount', 'Amount paid')->required();
            $form->select('method', 'Method')->options(['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank'])->default('cash');
            $form->text('reference', 'Reference');
            $content->body($form);
        });
    }

    public function pay($id)
    {
        $s = Supplier::where('company_id', Admin::user()->company_id)->findOrFail($id);
        try {
            (new SupplierService())->pay($s, (float) request('amount'), (string) request('method', 'cash'), (int) Admin::user()->id, request('reference'));
            admin_success('Payment recorded');
        } catch (BusinessRuleException $e) {
            admin_error('Not recorded', $e->getMessage());
        }

        return redirect(admin_url('suppliers/'.$s->id));
    }
}
