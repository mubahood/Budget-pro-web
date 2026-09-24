<?php

namespace App\Admin\Controllers;

use App\Models\User;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Team members of the logged-in admin's company (P0-2 rewrite).
 *
 * The User model has no CompanyScope on purpose (platform admins manage all
 * users), so every read/write here checks company_id explicitly.
 */
class EmployeesController extends TenantAdminController
{
    protected $title = 'Team';

    /** Roles a company may assign to its own staff (never the platform admin role). */
    private const ASSIGNABLE_ROLE_SLUGS = ['company', 'worker'];

    private function companyId(): int
    {
        return (int) Admin::user()->company_id;
    }

    /** 404 (not 403) for other tenants' users so ids don't leak existence. */
    private function findEmployee($id): User
    {
        return User::where('company_id', $this->companyId())->findOrFail($id);
    }

    private function assignableRoles(): array
    {
        return DB::table('admin_roles')->whereIn('slug', self::ASSIGNABLE_ROLE_SLUGS)->pluck('name', 'id')->all();
    }

    protected function grid()
    {
        $grid = new Grid(new User());

        $grid->model()->where('company_id', $this->companyId())->orderBy('created_at', 'desc');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('first_name', __('First Name'));
            $filter->like('last_name', __('Last Name'));
            $filter->like('phone_number', __('Phone Number'));
            $filter->like('email', __('Email'));
            $filter->equal('status', __('Status'))->select(['Active' => 'Active', 'Inactive' => 'Inactive']);
        });

        $grid->quickSearch('first_name', 'last_name', 'phone_number', 'email')->placeholder('Search by name, phone or email');
        $grid->disableBatchActions();

        $grid->column('avatar', __('Photo'))->lightbox(['width' => 50, 'height' => 50])->width(60);
        $grid->column('name', __('Full Name'))->display(fn ($name) => "<strong>{$name}</strong>")->sortable();
        $grid->column('email', __('Email / login'))->sortable();
        $grid->column('phone_number', __('Phone'))->display(fn ($p) => $p ? "<a href='tel:{$p}'>{$p}</a>" : '—');
        $grid->column('roles', __('Role'))->display(function ($roles) {
            return collect($roles)->pluck('name')->map(fn ($n) => "<span class='badge badge-primary'>{$n}</span>")->implode(' ');
        });
        $grid->column('status', __('Status'))
            ->using(['Active' => 'Active', 'Inactive' => 'Inactive'])
            ->dot(['Active' => 'success', 'Inactive' => 'danger'])->sortable();
        $grid->column('created_at', __('Added'))->display(fn ($c) => date('d M Y', strtotime($c)))->sortable();

        $grid->actions(function ($actions) {
            // An owner must not delete their own login from the team screen.
            if ((int) $actions->getKey() === (int) Admin::user()->id) {
                $actions->disableDelete();
            }
        });

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show($this->findEmployee($id));

        $show->field('name', __('Name'));
        $show->field('email', __('Email / login'));
        $show->field('phone_number', __('Phone'));
        $show->field('phone_number_2', __('Phone 2'));
        $show->field('sex', __('Gender'));
        $show->field('dob', __('Date of birth'));
        $show->field('address', __('Address'));
        $show->field('status', __('Status'));
        $show->field('roles', __('Role'))->as(fn ($roles) => collect($roles)->pluck('name')->implode(', '));
        $show->field('created_at', __('Added'));

        $show->panel()->tools(function ($tools) {
            $tools->disableDelete();
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new User());

        // Block edit/update of other tenants' users before laravel-admin loads them.
        if ($form->isEditing()) {
            $this->findEmployee(request()->route('employee'));
        }

        $editingId = $form->isEditing() ? (int) request()->route('employee') : null;
        $roles = $this->assignableRoles();
        $defaultRole = (int) array_search('Company Worker', $roles, true);

        $form->hidden('company_id')->default($this->companyId());

        $form->divider('Personal information');
        $form->text('first_name', __('First name'))->rules('required|max:100')->required();
        $form->text('last_name', __('Last name'))->rules('required|max:100')->required();
        $form->radio('sex', __('Gender'))->options(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Prefer not to say'])->default('Male');
        $form->date('dob', __('Date of birth'))->rules('nullable|date|before:today');

        $form->divider('Contact & login');
        $form->email('email', __('Email (used to log in)'))
            ->rules('required|email|max:191|unique:admin_users,email'.($editingId ? ','.$editingId : ''))
            ->required()
            ->help('The employee signs in with this email.');
        $form->mobile('phone_number', __('Phone'))->rules('required|max:30')->required()->placeholder('+256 700 000 000');
        $form->mobile('phone_number_2', __('Phone 2'))->rules('nullable|max:30');
        $form->textarea('address', __('Address'))->rows(2)->rules('nullable|max:500');

        $form->divider('Account');
        $form->password('password', __('Password'))
            ->rules($form->isEditing() ? 'nullable|min:8|confirmed' : 'required|min:8|confirmed')
            ->help($form->isEditing() ? 'Leave blank to keep the current password.' : 'At least 8 characters. Share it with the employee; they can change it under Settings.');
        $form->password('password_confirmation', __('Confirm password'));
        $form->multipleSelect('roles', __('Role'))
            ->options($roles)
            ->default($defaultRole ? [$defaultRole] : [])
            ->rules('required')
            ->help('Company Worker: sells and records stock. Company Owner: full control of this company.');
        $form->image('avatar', __('Photo'))->uniqueName()->rules('nullable|image|max:2048');
        $form->radio('status', __('Status'))->options(['Active' => 'Active', 'Inactive' => 'Inactive (cannot log in)'])->default('Active');

        $form->ignore(['password_confirmation']);

        $form->saving(function (Form $form) use ($roles) {
            $form->company_id = $this->companyId();

            $current = $form->model()->password;
            if ($form->password === null || $form->password === '') {
                // Blank on edit = keep the existing hash (this version of laravel-admin has no deleteInput()).
                $form->password = $current;
            } elseif ($form->password !== $current) {
                $form->password = Hash::make($form->password);
            }

            // Only roles a tenant may assign; drop anything else (e.g. a forged admin role id).
            $allowed = array_map('intval', array_keys($roles));
            $form->roles = array_values(array_intersect(array_map('intval', (array) $form->roles), $allowed));
        });

        return $form;
    }

    public function destroy($id)
    {
        $employee = $this->findEmployee($id);
        if ((int) $employee->id === (int) Admin::user()->id) {
            return response()->json(['status' => false, 'message' => 'You cannot delete your own account.']);
        }

        return parent::destroy($id);
    }
}
