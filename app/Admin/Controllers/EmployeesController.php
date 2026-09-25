<?php

namespace App\Admin\Controllers;

use App\Models\User;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
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

    private function companyId(): int
    {
        return (int) Admin::user()->company_id;
    }

    /** 404 (not 403) for other tenants' users so ids don't leak existence. */
    private function findEmployee($id): User
    {
        return User::where('company_id', $this->companyId())->findOrFail($id);
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
        $grid->column('id', __('Role'))->display(function () {
            $role = \App\Services\Team\Permissions::roleOf($this);

            return "<span class='badge badge-primary'>".e(config("permissions.roles.{$role}.label", 'No access')).'</span>';
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
        $show->field('id', __('Role'))->as(fn () => config('permissions.roles.'.\App\Services\Team\Permissions::roleOf($this).'.label', 'No access'));
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
        $teamRoles = collect(config('permissions.roles'))->except('owner')->map(fn ($r) => $r['label'])->all();
        $currentRole = $editingId ? \App\Services\Team\Permissions::roleOf(User::withoutGlobalScopes()->findOrFail($editingId)) : 'cashier';
        if ($currentRole === 'owner') {
            $form->display('team_role_label', __('Role'))->default('Owner');
        } else {
            $form->select('team_role', __('Role'))->options($teamRoles)->default($currentRole)->rules('required|in:'.implode(',', array_keys($teamRoles)))->required()
                ->help('Cashier sells; Stock keeper receives and counts stock; Accountant sees money; Manager can do everything except billing and team.');
        }
        $form->image('avatar', __('Photo'))->uniqueName()->rules('nullable|image|max:2048');
        $form->radio('status', __('Status'))->options(['Active' => 'Active', 'Inactive' => 'Inactive (cannot log in)'])->default('Active');

        $form->ignore(['password_confirmation', 'team_role', 'team_role_label']);

        $form->saving(function (Form $form) {
            $form->company_id = $this->companyId();

            $current = $form->model()->password;
            if ($form->password === null || $form->password === '') {
                // Blank on edit = keep the existing hash (this version of laravel-admin has no deleteInput()).
                $form->password = $current;
            } elseif ($form->password !== $current) {
                $form->password = Hash::make($form->password);
            }

            if (! $form->isEditing()) {
                $company = \App\Models\Company::withoutGlobalScopes()->findOrFail($this->companyId());
                if (! (new \App\Services\Billing\Quotas())->allows($company, 'users')) {
                    admin_error('Plan limit reached', 'Your plan does not allow more team members. Upgrade under Billing.');

                    return back()->withInput();
                }
            }
        });

        // The company role (plan C5) drives both the app permissions and the web admin role.
        $form->saved(function (Form $form) {
            $role = request('team_role');
            $user = User::withoutGlobalScopes()->find($form->model()->id);
            if ($user && $role && array_key_exists($role, config('permissions.roles'))) {
                $company = \App\Models\Company::withoutGlobalScopes()->findOrFail($this->companyId());
                if ((int) $company->owner_id !== (int) $user->id) {
                    app(\App\Services\Team\TeamService::class)->setRole($company, $user, $role);
                }
            }
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
