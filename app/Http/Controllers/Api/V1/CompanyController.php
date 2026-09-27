<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Rules\CompanyRules;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

    /**
     * POST company/logo (multipart `file`: jpg, jpeg, png or webp, at most 2 MB) — manage_settings (ApiPermissionMap).
     * Stored like the web Business settings (budget-pro-new Settings\Business::storeLogo): public storage
     * "images/logo-{company}-{random}.{ext}", saved to company.logo through CompanyRules. Returns {logo, logo_url}.
     */
    public function logo(Request $request)
    {
        $company = Company::find($request->user()->company_id);
        if ($company === null) {
            return $this->notFound('Company not found.');
        }
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']], [], ['file' => 'logo']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = $file->extension() ?: 'png';
        }
        $rel = 'images/logo-'.$company->id.'-'.Str::random(12).'.'.$ext;
        $dest = public_path('storage/'.$rel);
        File::ensureDirectoryExists(dirname($dest));
        File::put($dest, $file->get());
        CompanyRules::apply($company, ['logo' => $rel])->save();

        return $this->success(['logo' => $rel, 'logo_url' => url('storage/'.$rel), 'company' => new CompanyResource($company->fresh())], 'Logo saved.');
    }

    /**
     * POST company/currency { to, mode: relabel|convert, rate? (1 old = rate new), password } — owner only.
     * POST company/currency/preview { to, mode, rate? } — what it would change, nothing saved.
     * Through CurrencyChangeService, like the web settings screen: logged, synced rows re-sequenced.
     */
    public function currency(Request $request, bool $preview = false)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:8'],
            'mode' => ['required', 'in:relabel,convert'],
            'rate' => ['nullable', 'numeric', 'gt:0'],
            'password' => [$preview ? 'nullable' : 'required', 'string'],
        ]);
        $company = Company::find($request->user()->company_id);
        if ($company === null) {
            return $this->notFound('Company not found.');
        }
        if ((int) $company->owner_id !== (int) $request->user()->id) {
            return $this->error('Only the owner can change the currency.', 403, ['code' => 'owner_only']);
        }
        $svc = app(\App\Services\Shop\CurrencyChangeService::class);
        $rate = isset($data['rate']) ? (float) $data['rate'] : null;
        try {
            if ($preview) {
                return $this->success($svc->preview($company, $data['to'], $data['mode'], $rate), 'Preview.');
            }
            $change = $svc->change($company, $request->user(), $data['to'], $data['mode'], $rate, (string) $data['password']);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['change' => $change, 'company' => new CompanyResource($company->fresh())], 'Currency changed to '.strtoupper($data['to']).'.');
    }

    public function currencyPreview(Request $request)
    {
        return $this->currency($request, true);
    }
}
