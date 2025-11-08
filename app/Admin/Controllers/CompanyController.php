<?php

namespace App\Admin\Controllers;

use App\Models\Company;
use App\Models\User;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Facades\DB;

class CompanyController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Companies';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Company());
        
        // SAAS: Filter to show only user's own company unless super admin
        $user = auth()->user();
        if ($user->user_type !== 'admin') {
            // Regular users can only see their own company
            $grid->model()->where('id', $user->company_id);
        }

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'phone_number', 'phone_number_2', 'email');

        $grid->column('id', __('ID'))->hide();
        $grid->column('created_at', __('Registered'))
            ->display(function ($created_at) {
                return date('Y-m-d', strtotime($created_at));
            })->sortable();

        $grid->column('owner_id', __('Owner'))->display(function ($owner_id) {
            $user = User::find($owner_id);
            if ($user == null) {
                return 'Not found';
            }
            return $user->name;
        })->sortable();

        $grid->column('name', __('Company Name'))->sortable();
        $grid->column('email', __('Email'));
        $grid->column('website', __('Website'))->hide();
        $grid->column('about', __('About'))->hide();
        $grid->column('status', __('Status'))
            ->display(function ($status) {
                return $status == 'active' ? 'Active' : 'Inactive';
            })->sortable();
        $grid->column('license_expire', __('License Expire'))
            ->display(function ($license_expire) {
                return date('Y-m-d', strtotime($license_expire));
            })->sortable();
        $grid->column('address', __('Address'))->hide();
        $grid->column('phone_number', __('Phone Number'))->hide();
        $grid->column('phone_number_2', __('Phone number 2'))->hide();
        $grid->column('pobox', __('Pobox'))->hide();
        $grid->column('color', __('Color'))->hide();
        $grid->column('slogan', __('Slogan'))->hide();
        $grid->column('facebook', __('Facebook'))->hide();
        $grid->column('twitter', __('Twitter'))->hide();

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(Company::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('owner_id', __('Owner id'));
        $show->field('name', __('Name'));
        $show->field('email', __('Email'));
        $show->field('logo', __('Logo'));
        $show->field('website', __('Website'));
        $show->field('about', __('About'));
        $show->field('status', __('Status'));
        $show->field('license_expire', __('License expire'));
        $show->field('address', __('Address'));
        $show->field('phone_number', __('Phone number'));
        $show->field('phone_number_2', __('Phone number 2'));
        $show->field('pobox', __('Pobox'));
        $show->field('color', __('Color'));
        $show->field('slogan', __('Slogan'));
        $show->field('facebook', __('Facebook'));
        $show->field('twitter', __('Twitter'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new Company());
        
        $user = auth()->user();
        
        // SAAS: Ensure users can only edit their own company
        $form->saving(function (Form $form) use ($user) {
            // Prevent changing company_id or owner for non-admins
            if ($user->user_type !== 'admin') {
                if ($form->model()->id && $form->model()->id != $user->company_id) {
                    admin_error('Access Denied', 'You cannot edit other companies.');
                    return back();
                }
            }
        });

        // Only super admins can assign company owner
        if ($user->user_type === 'admin') {
            $admin_role_users = DB::table('admin_role_users')->where([
                'role_id' => 2,
            ])->get();
            
            $company_admins = [];
            foreach ($admin_role_users as $key => $value) {
                $u = User::find($value->user_id);
                if ($u == null) {
                    continue;
                }
                $company_admins[$u->id] = $u->name . ' - ' . $u->id;
            }
            
            $form->select('owner_id', __('Company owner'))
                ->options($company_admins)
                ->rules('required');
        } else {
            // Regular users cannot change owner
            $form->display('owner_id', __('Company Owner'))->with(function ($value) {
                $owner = User::find($value);
                return $owner ? $owner->name : 'N/A';
            });
        }
        $form->text('name', __('Company name'))->rules('required');
        $form->email('email', __('Email'));
        $form->image('logo', __('Logo'));
        $form->url('website', __('Website'));
        $form->textarea('about', __('About Company'));
        
        // Only super admins can change status and license
        if ($user->user_type === 'admin') {
            $form->select('status', __('Status'))
                ->options(['Active' => 'Active', 'Inactive' => 'Inactive'])
                ->default('Active');
            $form->date('license_expire', __('License expire'))->default(date('Y-m-d'));
        } else {
            $form->display('status', __('Status'));
            $form->display('license_expire', __('License Expires'));
        }
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
