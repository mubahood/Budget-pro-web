<?php

namespace App\Admin\Controllers;

use App\Models\ProductTemplate;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;

/** Platform admins edit the template packs the setup wizard offers (plan C3). */
class ProductTemplateController extends AdminController
{
    protected $title = 'Product templates';

    protected function grid()
    {
        $grid = new Grid(new ProductTemplate());
        $grid->actions(fn ($actions) => $actions->disableView()); // no detail page: edit shows everything
        $grid->model()->orderBy('business_type')->orderBy('sort_order');
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('business_type', 'Business type')->select(collect(config('onboarding.business_types'))->map(fn ($t) => $t['label']));
            $filter->equal('country', 'Country')->select(collect(config('onboarding.countries'))->map(fn ($c) => $c['name']));
            $filter->like('name', 'Name');
        });
        $grid->quickSearch('name');
        $grid->column('business_type', 'Type')->sortable();
        $grid->column('country', 'Country');
        $grid->column('name', 'Name')->sortable();
        $grid->column('category', 'Category');
        $grid->column('unit', 'Unit');
        $grid->column('prices', 'Prices')->display(fn ($p) => collect($p ?? [])->map(fn ($v, $cur) => $cur.' '.number_format((float) ($v['sell'] ?? 0)))->implode(', '));
        $grid->column('pack_version', 'Version');
        $grid->column('is_active', 'Active')->switch();

        return $grid;
    }

    protected function form()
    {
        $form = new Form(new ProductTemplate());
        $form->tools(fn ($tools) => $tools->disableView());
        $form->select('business_type', 'Business type')->options(collect(config('onboarding.business_types'))->map(fn ($t) => $t['label']))->required();
        $form->select('country', 'Country')->options(collect(config('onboarding.countries'))->map(fn ($c) => $c['name']))->help('Empty = every country');
        $form->text('name', 'Name')->rules('required|max:150');
        $form->text('category', 'Category')->rules('required|max:100');
        $form->text('sub_category', 'Sub-category')->rules('required|max:100');
        $form->text('unit', 'Unit')->default('pcs')->rules('required|max:20');
        $form->embeds('prices', 'Prices', function ($form) {
            foreach (array_unique(array_column(config('onboarding.countries'), 'currency')) as $cur) {
                $form->embeds($cur, $cur, function ($f) {
                    $f->decimal('sell', 'Selling price');
                    $f->decimal('cost', 'Cost price');
                });
            }
        });
        $form->number('sort_order', 'Order')->default(0);
        $form->number('pack_version', 'Pack version')->default(1)->help('Bump when you change a pack so phones refresh it.');
        $form->switch('is_active', 'Active')->default(1);

        return $form;
    }
}
