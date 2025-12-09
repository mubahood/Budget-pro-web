<?php

namespace App\Models;

use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Company Model
 *
 * Represents a company/organization in the multi-tenant system.
 * Each company has its own isolated data and settings.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string|null $phone_number
 * @property string|null $phone_number_2
 * @property string|null $email
 * @property string|null $address
 * @property string|null $slogan
 * @property string|null $about
 * @property string|null $logo
 * @property string $currency
 * @property string $status
 * @property string|null $license_package
 * @property \Illuminate\Support\Carbon|null $license_expire
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @package App\Models
 */
class Company extends Model
{
    use AuditLogger, HasFactory;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'license_expire' => 'date',
    ];

    /**
     * Boot method for model events.
     * Handles company creation and update events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Update owner's company_id when company is updated
        static::updated(function ($company) {
            $owner = User::find($company->owner_id);
            if ($owner == null) {
                throw new \Exception('Owner not found');
            }
            $owner->company_id = $company->id;
            $owner->save();
        });

        // Set up company on creation
        static::created(function ($company) {
            $owner = User::find($company->owner_id);
            if ($owner == null) {
                throw new \Exception('Owner not found');
            }
            $owner->company_id = $company->id;
            $owner->save();

            // Prepare default account categories
            self::prepare_account_categories($company->id);
        });
    }

    /**
     * Prepare default account categories for a new company.
     * Creates standard income and expense categories.
     *
     * @param int $company_id The ID of the company
     * @return void
     */
    public static function prepare_account_categories($company_id)
    {
        $company = Company::find($company_id);
        if ($company == null) {
            throw new \Exception('Company not found');
        }
        $sales_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Sales'],
        ])->first();
        if ($sales_account_category == null) {
            $sales_account_category = new FinancialCategory();
            $sales_account_category->company_id = $company_id;
            $sales_account_category->name = 'Sales';
            $sales_account_category->save();
        }

        $purchase_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Purchase'],
        ])->first();
        if ($purchase_account_category == null) {
            $purchase_account_category = new FinancialCategory();
            $purchase_account_category->company_id = $company_id;
            $purchase_account_category->name = 'Purchase';
            $purchase_account_category->save();
        }

        $expense_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Expense'],
        ])->first();

        if ($expense_account_category == null) {
            $expense_account_category = new FinancialCategory();
            $expense_account_category->company_id = $company_id;
            $expense_account_category->name = 'Expense';
            $expense_account_category->save();
        }
    }
}
