<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The authenticated tenant's own company profile. A user can only ever read or
 * update their own company (resolved from the token), never another tenant's.
 */
class CompanyController extends Controller
{
    use ApiResponse;

    public function show(Request $request)
    {
        $company = Company::find($request->user()->company_id);

        if ($company === null) {
            return $this->notFound('Company not found.');
        }

        return $this->success(new CompanyResource($company), 'Company loaded.');
    }

    public function update(Request $request)
    {
        $company = Company::find($request->user()->company_id);

        if ($company === null) {
            return $this->notFound('Company not found.');
        }

        // Only the company owner may edit the company profile.
        if ((int) $company->owner_id !== (int) $request->user()->id) {
            return $this->forbidden('Only the company owner can update company details.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'phone_number_2' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'string', 'max:191'],
            'about' => ['nullable', 'string', 'max:2000'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', Rule::in(config('saas.currencies'))],
            'settings_worker_can_create_stock_item' => ['nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_create_stock_record' => ['nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_create_stock_category' => ['nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_view_balance' => ['nullable', Rule::in(['Yes', 'No'])],
            'settings_worker_can_view_stats' => ['nullable', Rule::in(['Yes', 'No'])],
        ]);

        foreach ($data as $key => $value) {
            $company->{$key} = $value;
        }
        $company->save();

        return $this->success(new CompanyResource($company->fresh()), 'Company updated successfully.');
    }
}
