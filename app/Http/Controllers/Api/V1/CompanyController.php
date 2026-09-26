<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Rules\CompanyRules;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

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

        // One rule set with the web settings screen (budget-pro-new): the currency is locked once there are sales.
        $data = $request->validate(CompanyRules::rules($company));
        CompanyRules::apply($company, $data);
        $company->save();

        return $this->success(new CompanyResource($company->fresh()), 'Company updated successfully.');
    }
}
