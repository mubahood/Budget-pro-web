<?php

namespace App\Admin\Controllers;

use App\Models\Company;
use App\Models\User;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin view of every tenant company. Reachable only through the
 * `admin.platform:all` route group (P0-1); tenants edit their own company on
 * CompanyEditController instead.
 */
class CompanyController extends AdminController
{
    protected $title = 'Companies';

    protected function grid()
    {
        $grid = new Grid(new Company());
        $grid->model()->orderBy('id', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'phone_number', 'phone_number_2', 'email');

        $grid->column('id', __('ID'))->sortable();
        $grid->column('created_at', __('Registered'))->display(fn ($c) => date('Y-m-d', strtotime($c)))->sortable();
        $grid->column('owner_id', __('Owner'))->display(function ($owner_id) {
            $user = User::find($owner_id);

            return $user ? $user->name : 'Not found';
        });
        $grid->column('name', __('Company Name'))->sortable();
        $grid->column('email', __('Email'));
        $grid->column('currency', __('Currency'));
        $grid->column('status', __('Status'))
            ->display(fn ($status) => strcasecmp((string) $status, 'active') === 0 ? 'Active' : 'Inactive')
            ->sortable();
        $grid->column('license_expire', __('License Expire'))
            ->display(fn ($d) => $d ? date('Y-m-d', strtotime($d)) : '—')->sortable();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(Company::findOrFail($id));

        foreach (['id', 'created_at', 'updated_at', 'owner_id', 'name', 'email', 'logo', 'website', 'about', 'status',
            'currency', 'license_expire', 'address', 'phone_number', 'phone_number_2', 'pobox', 'color', 'slogan', 'facebook', 'twitter'] as $field) {
            $show->field($field, __(ucwords(str_replace('_', ' ', $field))));
        }

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Company());

        $ownerRoleId = DB::table('admin_roles')->where('slug', 'company')->value('id');
        $ownerIds = DB::table('admin_role_users')->where('role_id', $ownerRoleId)->pluck('user_id');
        $owners = User::whereIn('id', $ownerIds)->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->email])->all();

        $form->select('owner_id', __('Company owner'))->options($owners)->rules('required');
        $form->text('name', __('Company name'))->rules('required');
        $form->email('email', __('Email'));
        $form->select('currency', __('Currency'))
            ->options(array_combine(config('saas.currencies'), config('saas.currencies')))
            ->default(config('saas.default_currency'));
        $form->image('logo', __('Logo'));
        $form->url('website', __('Website'));
        $form->textarea('about', __('About Company'));
        $form->select('status', __('Status'))->options(['Active' => 'Active', 'Inactive' => 'Inactive'])->default('Active');
        $form->date('license_expire', __('License expire'));
        $form->text('address', __('Address'));
        $form->text('phone_number', __('Phone number'));
        $form->text('phone_number_2', __('Phone number 2'));
        $form->text('pobox', __('Pobox'));
        $form->color('color', __('Color'));
        $form->text('slogan', __('Slogan'));
        $form->url('facebook', __('Facebook'));
        $form->url('twitter', __('Twitter'));

        return $form;
    }
}
