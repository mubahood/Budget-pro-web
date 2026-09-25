<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Onboarding\OnboardingService;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Web setup wizard (plan C2/C12): business → products → money → team → done. Same service as the app. */
class SetupController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    private function company(): Company
    {
        abort_unless(Permissions::can(Admin::user(), 'manage_settings'), 403, 'Only the owner can run the setup.');

        return Company::withoutGlobalScopes()->findOrFail(Admin::user()->company_id);
    }

    public function index(Content $content, Request $request)
    {
        $company = $this->company();
        $state = $this->onboarding->state($company);
        $step = $request->query('step', in_array($state['step'], ['account', 'first_sale', 'done'], true) ? ($state['step'] === 'done' ? 'done' : 'business') : $state['step']);
        if ($state['step'] === 'first_sale' && ! $request->has('step')) {
            $step = 'done';
        }

        if ($step === 'done' && ! $state['completed_at']) {
            $state = $this->onboarding->markStep($company, 'done');
        }

        return $content->title('Set up your shop')->description('About five minutes — everything also works offline in the app')->body(view('admin.setup', [
            'company' => $company, 'state' => $state, 'step' => $step, 'checklist' => $this->onboarding->checklist($company),
            'pack' => $step === 'products' ? $this->onboarding->templates($company) : null,
        ]));
    }

    public function business(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'business_type' => ['required', Rule::in(array_keys(config('onboarding.business_types')))],
            'country' => ['required', Rule::in(array_keys(config('onboarding.countries')))],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        $company = $this->onboarding->saveBusiness($this->company(), $data);
        $this->onboarding->markStep($company, 'business');

        return redirect(admin_url('setup?step=products'));
    }

    public function products(Request $request)
    {
        $picks = collect($request->input('items', []))->filter(fn ($i) => ! empty($i['pick']))->map(fn ($i, $key) => [
            'key' => (int) $key, 'selling_price' => is_numeric($i['selling_price'] ?? null) ? (float) $i['selling_price'] : null,
            'buying_price' => is_numeric($i['buying_price'] ?? null) ? (float) $i['buying_price'] : null,
            'opening_stock' => is_numeric($i['opening_stock'] ?? null) ? (float) $i['opening_stock'] : 0,
        ])->values()->all();
        if ($picks === []) {
            admin_warning('Nothing selected', 'Tick the products you sell, or skip this step.');

            return redirect(admin_url('setup?step=products'));
        }
        try {
            $r = $this->onboarding->applyTemplates($this->company(), Admin::user(), $picks);
            admin_success('Products added', "{$r['created']} products are ready to sell.".($r['skipped'] ? ' Already there: '.implode(', ', array_slice($r['skipped'], 0, 5)).'.' : ''));
        } catch (BusinessRuleException $e) {
            admin_error('Could not add products', $e->getMessage());

            return redirect(admin_url('setup?step=products'));
        }

        return redirect(admin_url('setup?step=money'));
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        try {
            $parsed = $this->onboarding->parseCsv((string) file_get_contents($request->file('file')->getRealPath()));
            if ($parsed['errors'] !== []) {
                admin_error('Some rows need fixing', collect($parsed['errors'])->take(8)->map(fn ($e) => "Row {$e['row']}: {$e['message']}")->implode('<br>'));

                return redirect(admin_url('setup?step=products'));
            }
            $r = $this->onboarding->createProducts($this->company(), Admin::user(), $parsed['rows']);
            $this->onboarding->markStep($this->company(), 'products');
            admin_success('Imported', "{$r['created']} products imported.");
        } catch (BusinessRuleException $e) {
            admin_error('Import failed', $e->getMessage());

            return redirect(admin_url('setup?step=products'));
        }

        return redirect(admin_url('setup?step=money'));
    }

    public function money(Request $request)
    {
        $data = $request->validate([
            'payment_methods' => ['required', 'array', 'min:1'], 'payment_methods.*' => [Rule::in(array_keys(config('onboarding.payment_methods')))],
            'momo_providers' => ['nullable', 'array'], 'opening_float' => ['nullable', 'numeric', 'min:0'],
            'receipt_channels' => ['nullable', 'array'], 'receipt_channels.*' => [Rule::in(['whatsapp', 'print', 'sms'])],
            'negative_stock_policy' => ['nullable', Rule::in(['allow', 'flag', 'block'])],
        ]);
        $company = $this->onboarding->saveMoney($this->company(), $data);
        $this->onboarding->markStep($company, 'money');

        return redirect(admin_url('setup?step=team'));
    }

    public function team(Request $request, TeamService $team)
    {
        $data = $request->validate(['role' => ['required', 'string'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'name' => ['nullable', 'string', 'max:150']]);
        $company = $this->company();
        try {
            $team->invite(Admin::user(), $data['role'], $data['phone'] ?? null, $data['email'] ?? null, $data['name'] ?? null);
            admin_success('Invite sent', 'They will get a link to join '.$company->name.'.');
        } catch (BusinessRuleException $e) {
            admin_error('Invite not sent', $e->getMessage());

            return redirect(admin_url('setup?step=team'));
        }
        $this->onboarding->markStep($company, 'team');

        return redirect(admin_url('setup?step=done'));
    }

    public function skip(string $step)
    {
        $company = $this->company();
        try {
            $this->onboarding->markStep($company, $step, true);
        } catch (BusinessRuleException) {
            abort(404);
        }
        $next = config('onboarding.steps')[array_search($step, config('onboarding.steps'), true) + 1] ?? 'done';

        return redirect(admin_url('setup?step='.($next === 'first_sale' ? 'done' : $next)));
    }

    public function dismiss()
    {
        $this->onboarding->dismissChecklist(Company::withoutGlobalScopes()->findOrFail(Admin::user()->company_id));

        return redirect(admin_url('/'));
    }
}
