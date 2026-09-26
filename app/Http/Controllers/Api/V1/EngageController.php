<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Engage\DebtReminders;
use App\Services\Engage\MomoCollections;
use App\Support\Rules\CompanyRules;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Debt reminders and mobile-money collections settings (plan Part E2/E3). */
class EngageController extends Controller
{
    use ApiResponse;

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->user()->company_id);
    }

    public function settings(Request $request, MomoCollections $momo)
    {
        $c = $this->company($request);

        return $this->success([
            'debt_reminders_enabled' => (bool) $c->debt_reminders_enabled, 'credit_terms_days' => (int) $c->credit_terms_days,
            'momo' => ['ready' => (bool) $c->momo_subaccount_id, 'phone' => $c->momo_payout_phone, 'network' => $c->momo_payout_network, 'networks' => $momo->networks($c)],
        ], 'Settings.');
    }

    public function updateSettings(Request $request, MomoCollections $momo)
    {
        $c = $this->company($request);
        $data = $request->validate(CompanyRules::only($c, CompanyRules::SECTIONS['customers']));
        CompanyRules::apply($c, $data)->saveQuietly();

        return $this->settings($request, $momo);
    }

    /** PUT company/momo { phone, network } — the number the shop's mobile-money sales reach. */
    public function setupMomo(Request $request, MomoCollections $momo)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'network' => ['required', 'string', 'max:20']]);
        try {
            $momo->setup($this->company($request), $data['phone'], $data['network']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->settings($request, $momo);
    }

    /** GET momo-requests/{id} — status, checking with the provider while pending. */
    public function momoStatus(Request $request, MomoCollections $momo, $id)
    {
        /** @var object{status: string, tx_ref: string}|null $req */
        $req = DB::table('momo_requests')->where('company_id', $request->user()->company_id)->find($id);
        if (! $req) {
            return $this->notFound('Request not found.');
        }
        if ($req->status === 'pending') {
            $req = $momo->settle($req->tx_ref);
        }

        return $this->success($req, ucfirst((string) $req->status).'.');
    }

    public function remind(Request $request, DebtReminders $reminders, $id)
    {
        $q = Customer::withoutGlobalScopes()->where('company_id', $request->user()->company_id)->where('is_deleted', false);
        $customer = strlen((string) $id) === 36 ? $q->where('uuid', $id)->first() : $q->find($id);
        if (! $customer) {
            return $this->notFound('Customer not found.');
        }
        try {
            $reminders->remind($customer);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['last_reminded_at' => $customer->last_reminded_at], 'Reminder sent.');
    }
}
