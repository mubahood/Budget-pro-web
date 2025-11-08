<?php

namespace App\Admin\Controllers;

use App\Models\User;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class EmployeesController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Employees';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new User());
        $u = Admin::user();
        
        $grid->model()->where('company_id', $u->company_id)
            ->orderBy('created_at', 'desc');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            
            $filter->like('first_name', __('First Name'));
            $filter->like('last_name', __('Last Name'));
            $filter->like('phone_number', __('Phone Number'));
            $filter->like('email', __('Email'));
            
            $filter->equal('sex', __('Gender'))
                ->select(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other']);
                
            $filter->equal('status', __('Status'))
                ->select(['Active' => 'Active', 'Inactive' => 'Inactive']);
                
            $filter->between('dob', __('Date of Birth Range'))->date();
            $filter->between('created_at', __('Registration Date Range'))->date();
        });

        $grid->quickSearch('first_name', 'last_name', 'phone_number', 'email')
            ->placeholder('Search by name, phone or email');
            
        $grid->disableBatchActions();

        $grid->column('id', __('ID'))->sortable();

        $grid->column('avatar', __('Photo'))
            ->lightbox(['width' => 50, 'height' => 50])
            ->width(60);
            
        $grid->column('name', __('Full Name'))
            ->display(function ($name) {
                return "<strong>{$name}</strong>";
            })->sortable();
            
        $grid->column('email', __('Email'))
            ->display(function ($email) {
                return $email ?? 'N/A';
            })->sortable()->hide();
            
        $grid->column('phone_number', __('Primary Phone'))
            ->display(function ($phone_number) {
                return $phone_number ? "<a href='tel:{$phone_number}'>{$phone_number}</a>" : 'N/A';
            });
            
        $grid->column('phone_number_2', __('Secondary Phone'))
            ->display(function ($phone_number_2) {
                return $phone_number_2 ? "<a href='tel:{$phone_number_2}'>{$phone_number_2}</a>" : '-';
            })->hide();
            
        $grid->column('sex', __('Gender'))
            ->using(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other'])
            ->label([
                'Male' => 'primary',
                'Female' => 'danger',
                'Other' => 'info'
            ])->sortable()
            ->filter(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other']);
            
        $grid->column('dob', __('Date of Birth'))
            ->display(function ($dob) {
                if ($dob) {
                    $age = floor((time() - strtotime($dob)) / 31556926);
                    return date('d M Y', strtotime($dob)) . " ({$age} yrs)";
                }
                return 'N/A';
            })->sortable()->hide();
            
        $grid->column('address', __('Address'))
            ->display(function ($address) {
                return $address ? substr($address, 0, 30) . '...' : 'N/A';
            })->hide();
            
        $grid->column('status', __('Status'))
            ->using(['Active' => 'Active', 'Inactive' => 'Inactive'])
            ->dot([
                'Active' => 'success',
                'Inactive' => 'danger'
            ])->sortable()
            ->filter(['Active' => 'Active', 'Inactive' => 'Inactive']);

        $grid->column('created_at', __('Registered'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })->sortable()->hide();

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
        $show = new Show(User::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('username', __('Username'));
        $show->field('password', __('Password'));
        $show->field('name', __('Name'));
        $show->field('avatar', __('Avatar'));
        $show->field('remember_token', __('Remember token'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('company_id', __('Company id'));
        $show->field('first_name', __('First name'));
        $show->field('last_name', __('Last name'));
        $show->field('phone_number', __('Phone number'));
        $show->field('phone_number_2', __('Phone number 2'));
        $show->field('address', __('Address'));
        $show->field('sex', __('Sex'));
        $show->field('dob', __('Dob'));
        $show->field('status', __('Status'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new User());

        $u = Admin::user();
        $form->hidden('company_id')->default($u->company_id);

        $form->divider('Personal Information');

        $form->text('first_name', __('First Name'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter employee first name')
            ->placeholder('e.g., John');
            
        $form->text('last_name', __('Last Name'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter employee last name')
            ->placeholder('e.g., Doe');
            
        $form->radio('sex', __('Gender'))
            ->options([
                'Male' => 'Male',
                'Female' => 'Female',
                'Other' => 'Prefer not to say'
            ])
            ->rules('required')
            ->required()
            ->default('Male');
            
        $form->date('dob', __('Date of Birth'))
            ->rules('nullable|date|before:today')
            ->help('Select employee date of birth');

        $form->divider('Contact Information');

        $form->mobile('phone_number', __('Primary Phone Number'))
            ->rules('required|max:20')
            ->required()
            ->help('Main contact number')
            ->placeholder('+256 700 000 000');
            
        $form->mobile('phone_number_2', __('Secondary Phone Number'))
            ->rules('nullable|max:20')
            ->help('Alternative contact number (optional)')
            ->placeholder('+256 700 000 001');
            
        $form->email('email', __('Email Address'))
            ->rules('nullable|email|max:255')
            ->help('Employee email address (will be used as username if provided)')
            ->placeholder('john.doe@example.com');
            
        $form->textarea('address', __('Physical Address'))
            ->rows(2)
            ->rules('nullable|max:500')
            ->help('Current residential address')
            ->placeholder('Enter full address: District, Village, etc.');

        $form->divider('Account Information');

        $form->image('avatar', __('Profile Photo'))
            ->uniqueName()
            ->rules('nullable|image|max:2048')
            ->help('Upload employee photo (max 2MB)');

        $form->radio('status', __('Employment Status'))
            ->options([
                'Active' => 'Active (Currently Employed)',
                'Inactive' => 'Inactive (Suspended/Left)'
            ])
            ->rules('required')
            ->required()
            ->default('Active')
            ->help('Set employee status');

        $form->html('<div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Note:</strong> Employee will receive login credentials via SMS/Email if contact information is provided.
        </div>');

        $form->saved(function (Form $form) {
            admin_success('Success', 'Employee record saved successfully!');
        });

        return $form;
    }
}
