<?php

namespace App\Admin\Controllers;

use App\Models\Shift;
use App\Services\Shop\ShiftService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/** Cash-up history (P2-8); shifts are opened/closed from the till (app or API). */
class ShiftController extends TenantAdminController
{
    protected $title = 'Shifts & cash-up';

    protected function grid()
    {
        $grid = new Grid(new Shift());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('id');
        $grid->disableCreateButton();
        $grid->actions(fn ($a) => $a->disableEdit()->disableDelete());
        $grid->column('number', 'Shift');
        $grid->column('openedBy.name', 'Cashier');
        $grid->column('opened_at', 'Opened')->display(fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M H:i') : '');
        $grid->column('closed_at', 'Closed')->display(fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M H:i') : '<span class="label label-success">open</span>');
        $grid->column('sales_total', 'Sales')->display(fn ($v) => Money::format($v));
        $grid->column('expected_cash', 'Expected cash')->display(fn ($v) => Money::format($v));
        $grid->column('counted_cash', 'Counted')->display(fn ($v) => $v === null ? '—' : Money::format($v));
        $grid->column('variance', 'Variance')->display(fn ($v) => $v === null ? '—' : '<strong style="color:'.((float) $v < 0 ? '#c0392b' : '#27ae60').'">'.Money::format($v).'</strong>');

        return $grid;
    }

    protected function detail($id)
    {
        $shift = Shift::where('company_id', Admin::user()->company_id)->findOrFail($id);
        $show = new Show($shift);
        $show->field('number');
        $show->field('opening_float')->as(fn ($v) => Money::format($v));
        $show->field('totals', 'By payment method')->unescape()->as(function () use ($shift) {
            $t = (new ShiftService())->totals($shift);
            $h = '<table class="table table-condensed"><tr><th>Method</th><th>Count</th><th class="text-right">Total</th></tr>';
            foreach ($t['by_method'] as $m => $r) {
                $h .= '<tr><td>'.e(ucfirst(str_replace('_', ' ', $m))).'</td><td>'.$r['count'].'</td><td class="text-right">'.e(Money::format($r['total'])).'</td></tr>';
            }

            return $h.'</table>';
        });
        $show->field('expected_cash')->as(fn ($v) => Money::format($v));
        $show->field('counted_cash')->as(fn ($v) => $v === null ? '—' : Money::format($v));
        $show->field('variance')->as(fn ($v) => $v === null ? '—' : Money::format($v));
        $show->field('notes');

        return $show;
    }

    protected function form()
    {
        return new \Encore\Admin\Form(new Shift());
    }
}
