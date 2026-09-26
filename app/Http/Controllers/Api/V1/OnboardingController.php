<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\Onboarding\OnboardingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Setup wizard + getting-started checklist + modules (plan C2/C4, Appendix F). */
class OnboardingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->user()->company_id);
    }

    private function payload(Company $company): array
    {
        return [
            'state' => $this->onboarding->state($company),
            'checklist' => $this->onboarding->checklist($company),
            'checklist_v2' => $this->onboarding->checklistV2($company),
            'company' => new CompanyResource($company),
            'presets' => [
                'countries' => config('onboarding.countries'),
                'business_types' => collect(config('onboarding.business_types'))->map(fn ($t) => $t['label']),
                'modules' => config('onboarding.modules'),
                'payment_methods' => config('onboarding.payment_methods'),
                'steps' => config('onboarding.steps'),
            ],
        ];
    }

    public function show(Request $request)
    {
        return $this->success($this->payload($this->company($request)), 'Setup.');
    }

    /** POST onboarding/steps/{step} { skipped?: bool, seconds?: int } */
    public function step(Request $request, string $step)
    {
        $data = $request->validate(['skipped' => ['nullable', 'boolean'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        $company = $this->company($request);
        try {
            $this->onboarding->markStep($company, $step, (bool) ($data['skipped'] ?? false), $data['seconds'] ?? null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($this->payload($company->fresh()), 'Saved.');
    }

    public function business(Request $request)
    {
        $data = $request->validate(\App\Support\Rules\OnboardingRules::business());
        $company = $this->onboarding->saveBusiness($this->company($request), $data);
        $this->onboarding->markStep($company, 'business', false, $data['seconds'] ?? null);

        return $this->success($this->payload($company->fresh()), 'Business saved.');
    }

    public function money(Request $request)
    {
        $data = $request->validate(\App\Support\Rules\OnboardingRules::money());
        $company = $this->onboarding->saveMoney($this->company($request), $data);
        $this->onboarding->markStep($company, 'money', false, $data['seconds'] ?? null);

        return $this->success($this->payload($company->fresh()), 'Money settings saved.');
    }

    public function templates(Request $request)
    {
        $type = $request->query('business_type');
        if ($type !== null && ! array_key_exists($type, config('onboarding.business_types'))) {
            return $this->error('Unknown business type.', 422, ['code' => 'invalid_business_type']);
        }

        return $this->success($this->onboarding->templates($this->company($request), $type), 'Template pack.');
    }

    /** POST onboarding/templates/apply { items: [{key, selling_price?, buying_price?, opening_stock?}] } */
    public function applyTemplates(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.key' => ['required', 'integer'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.buying_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.opening_stock' => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $r = $this->onboarding->applyTemplates($this->company($request), $request->user(), $data['items']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($r, "{$r['created']} products added.");
    }

    /** POST onboarding/import (multipart `file` — CSV or Excel .xlsx — or `csv` text) { dry_run?: bool } — preview first, then commit. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['nullable', 'file', 'max:2048', 'mimes:csv,txt,xlsx,zip'], 'csv' => ['nullable', 'string', 'max:2000000'], 'dry_run' => ['nullable', 'boolean']]);
        $contents = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('csv', '');
        try {
            $parsed = $this->onboarding->parseCsv($contents);
            if ($request->boolean('dry_run')) {
                return $this->success($parsed + ['count' => count($parsed['rows'])], 'Preview.');
            }
            if ($parsed['errors'] !== []) {
                return $this->error('Fix the rows with problems first.', 422, ['code' => 'import_errors', 'rows' => $parsed['errors']]);
            }
            $r = $this->onboarding->createProducts($this->company($request), $request->user(), $parsed['rows']);
            if ($r['created'] > 0) {
                $this->onboarding->markStep($this->company($request), 'products');
                \App\Services\Onboarding\OnboardingEvents::record((int) $request->user()->company_id, 'import_done', ['created' => $r['created'], 'skipped' => count($r['skipped'])]);
            }
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created($r, "{$r['created']} products imported.");
    }

    public function dismissChecklist(Request $request)
    {
        $this->onboarding->dismissChecklist($this->company($request));

        return $this->success(null, 'Checklist hidden.');
    }

    /** PUT company/modules { modules: [...] } — hidden modules keep their data. */
    public function modules(Request $request)
    {
        $data = $request->validate(['modules' => ['required', 'array', 'min:1'], 'modules.*' => [Rule::in(array_keys(config('onboarding.modules')))]]);
        $company = $this->company($request);
        $company->enabled_modules = array_values(array_unique($data['modules']));
        $company->save();

        return $this->success(new CompanyResource($company->fresh()), 'Modules updated.');
    }
}
