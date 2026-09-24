<?php

namespace App\Admin\Controllers;

use App\Models\Unit;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;

/** Units of measure (P2-1). */
class UnitController extends TenantAdminController
{
    protected $title = 'Units';

    protected function grid()
    {
        $grid = new Grid(new Unit());
        $grid->model()->where('company_id', Admin::user()->company_id);
        $grid->column('name');
        $grid->column('abbreviation', 'Short');
        $grid->column('factor', 'Pieces per unit');
        $grid->disableExport();

        return $grid;
    }

    protected function form()
    {
        $form = new Form(new Unit());
        $form->hidden('company_id')->default(Admin::user()->company_id);
        $form->text('name')->required()->placeholder('Crate');
        $form->text('abbreviation', 'Short name')->required()->placeholder('crt');
        $form->decimal('factor', 'How many pieces in one')->default(1)->rules('required|numeric|min:0.001');
        $form->saving(fn (Form $form) => $form->company_id = Admin::user()->company_id);

        return $form;
    }
}
