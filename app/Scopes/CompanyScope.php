<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class CompanyScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // Only apply company scope if user is authenticated
        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();

        // Skip scope if user doesn't have a company_id
        if (!$user->company_id) {
            return;
        }

        // Check if the model has company_id column
        if (!$this->hasCompanyIdColumn($model)) {
            return;
        }

        // Apply the company filter
        $builder->where($model->getTable() . '.company_id', '=', $user->company_id);
    }

    /**
     * Check if the model has a company_id column
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function hasCompanyIdColumn(Model $model)
    {
        // Get the table name
        $table = $model->getTable();
        
        // Check if company_id exists in fillable or other attributes
        $columns = Schema::getColumnListing($table);
        
        return in_array('company_id', $columns);
    }

    /**
     * Extend the query builder with functions to bypass the scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    public function extend(Builder $builder)
    {
        // Add a method to get all records without company scope
        $builder->macro('withoutCompanyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });

        // Add a method to filter by specific company
        $builder->macro('forCompany', function (Builder $builder, $companyId) {
            $model = $builder->getModel();
            return $builder->withoutGlobalScope($this)
                           ->where($model->getTable() . '.company_id', '=', $companyId);
        });

        // Add a method to get records from all companies
        $builder->macro('allCompanies', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
