<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Services\Shop\CustomerService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/** Customers & debt book (plan A9, P2-4/P2-10). */
class CustomerController extends TenantAdminController
{
    protected $title = 'Customers';

    protected function grid()
    {
        $grid = new Grid(new Customer());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('balance');
        $grid->quickSearch('name', 'phone');
        $grid->filter(function ($f) {
            $f->disableIdFilter();
            $f->like('name', 'Name');
            $f->where(fn ($q) => $q->where('balance', '>', 0), 'Owes money', 'owes')->checkbox(['1' => 'Only customers with a balance']);
        });
        $grid->column('name', 'Name')->sortable();
        $grid->column('phone', 'Phone');
        $grid->column('balance', 'Owes')->display(fn ($b) => (float) $b > 0 ? '<strong style="color:#c0392b">'.Money::format($b).'</strong>' : Money::format($b))->sortable();
        $grid->column('credit_limit', 'Credit limit')->display(fn ($v) => $v === null ? '—' : Money::format($v));
        $grid->column('is_active', 'Active')->bool();
        $grid->disableExport(false);

        return $grid;
    }

    protected function detail($id)
    {
        $c = Customer::where('company_id', Admin::user()->company_id)->findOrFail($id);
        $show = new Show($c);
        $show->panel()->tools(fn ($t) => $t->append('<a class="btn btn-sm btn-success" href="'.admin_url('customers/'.$c->id.'/pay').'"><i class="fa fa-money"></i> Record payment</a>&nbsp;'));
        $show->field('name');
        $show->field('phone');
        $show->field('email');
        $show->field('address');
        $show->field('balance', 'Owes')->as(fn ($b) => Money::format($b));
        $show->field('credit_limit')->as(fn ($v) => $v === null ? 'No limit' : Money::format($v));
        $show->field('statement', 'Statement')->unescape()->as(function () use ($c) {
            $st = (new CustomerService())->statement($c);
            $h = '<table class="table table-condensed"><thead><tr><th>Date</th><th>Details</th><th class="text-right">Owed</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr></thead><tbody>';
            foreach ($st['entries'] as $e) {
                $h .= '<tr><td>'.e($e['date']).'</td><td>'.e($e['description']).'</td><td class="text-right">'.($e['debit'] ? e(Money::format($e['debit'])) : '').'</td><td class="text-right">'.($e['credit'] ? e(Money::format($e['credit'])) : '').'</td><td class="text-right"><strong>'.e(Money::format($e['balance'])).'</strong></td></tr>';
            }

            return $h.'</tbody></table>';
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Customer());
        $form->hidden('company_id')->default(Admin::user()->company_id);
        $form->text('name')->required();
        $form->mobile('phone')->options(['mask' => '9999999999999']);
        $form->email('email');
        $form->text('address');
        $form->decimal('credit_limit', 'Credit limit')->help('Leave empty for no limit');
        $form->textarea('notes')->rows(2);
        $form->switch('is_active', 'Active')->default(1);
        $form->saving(function (Form $form) {
            $form->company_id = Admin::user()->company_id;
        });

        return $form;
    }

    public function payForm($id)
    {
        $c = Customer::where('company_id', Admin::user()->company_id)->findOrFail($id);

        return \Encore\Admin\Facades\Admin::content(function ($content) use ($c) {
            $content->title('Record payment — '.$c->name)->description('Owes '.Money::format($c->balance));
            $form = new \Encore\Admin\Widgets\Form();
            $form->action(admin_url('customers/'.$c->id.'/pay'));
            $form->decimal('amount', 'Amount received')->required();
            $form->select('method', 'Method')->options(['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank', 'card' => 'Card'])->default('cash');
            $form->text('reference', 'Reference (MoMo / bank ref)');
            $content->body($form);
        });
    }

    public function pay($id)
    {
        $c = Customer::where('company_id', Admin::user()->company_id)->findOrFail($id);
        try {
            (new CustomerService())->receivePayment($c, (float) request('amount'), (string) request('method', 'cash'), (int) Admin::user()->id, request('reference'));
            admin_success('Payment recorded', 'Applied to the oldest unpaid sales first.');
        } catch (BusinessRuleException $e) {
            admin_error('Not recorded', $e->getMessage());
        }

        return redirect(admin_url('customers/'.$c->id));
    }
}
