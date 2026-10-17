<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContributionRecord;
use App\Services\Budget\BudgetAccess;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class ContributionRecordController extends BaseCrudController
{
    protected string $modelClass = ContributionRecord::class;

    protected string $resourceName = 'Contribution';

    // not_paid_amount is derived by the model; paid_amount is the sum of the payments (a figure sent here is
    // posted as a payment of the difference, for older apps: ContributionRecord::prepare).
    protected array $writable = ['budget_program_id', 'treasurer_id', 'name', 'phone', 'amount', 'paid_amount', 'fully_paid', 'custom_amount', 'custom_paid_amount', 'category_id'];

    protected array $searchable = ['name'];

    protected array $sortable = ['id', 'name', 'amount', 'paid_amount', 'created_at'];

    protected array $filterable = ['budget_program_id', 'treasurer_id', 'fully_paid', 'phone'];

    protected array $listWith = ['budgetProgram', 'treasurer'];

    /**
     * POST contribution-records/print-link { category_id } — a 30-minute link to the printable pledge
     * list of this person's own company (routes/web.php `data-exports.print`), to open in the phone's
     * browser or share. Relative-signed, so the host's http/https proxy cannot invalidate it.
     */
    public function printLink(Request $request)
    {
        $data = $request->validate(['category_id' => ['required', 'string', 'max:191']]);
        $path = URL::temporarySignedRoute('data-exports.print', now()->addMinutes(30), [
            'id' => $data['category_id'],
            'company_id' => (int) $request->user()->company_id,
        ], false);

        return $this->success(['url' => url($path), 'expires_in' => 1800], 'Link ready.');
    }

    public function store(Request $request)
    {
        // The API caller acts as treasurer by default (no admin session in API context).
        if (empty($request->input('treasurer_id'))) {
            $request->merge(['treasurer_id' => (int) $request->user()->id]);
        }
        if (($refusal = $this->refuse($request, (int) $request->input('budget_program_id'), null)) !== null) {
            return $refusal;
        }

        return parent::store($request);
    }

    public function update(Request $request, $id)
    {
        $existing = $this->findOwned($request, $id);
        if ($existing !== null) {
            foreach (array_unique(array_filter([(int) $existing->budget_program_id, (int) $request->input('budget_program_id')])) as $programId) {
                if (($refusal = $this->refuse($request, $programId, $existing)) !== null) {
                    return $refusal;
                }
            }
        }

        return parent::update($request, $id);
    }

    /**
     * Per event (BudgetAccess): secretaries, members, treasurers and chairs record pledges; a paid figure that
     * changes is a payment, which only treasurers and chairs record. The owner and manage_budget do everything.
     */
    private function refuse(Request $request, int $programId, ?Model $existing): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($programId <= 0 || BudgetAccess::fullAccess($user)) {
            return null; // validation answers a missing event; full access needs no more checks
        }
        if (! BudgetAccess::can($user, $programId, BudgetAccess::PLEDGE)) {
            return $this->forbiddenFor(BudgetAccess::PLEDGE, 'Your role on this event does not allow recording pledges.');
        }
        $paid = $request->input('custom_paid_amount') ?? $request->input('paid_amount');
        $changesPaid = ($paid !== null && $paid !== '' && Decimal::valid($paid) && Decimal::cmp((string) $paid, $existing ? (string) $existing->paid_amount : '0') !== 0)
            || ($request->input('fully_paid') === 'Yes' && ($existing === null || $existing->fully_paid !== 'Yes'));
        if ($changesPaid && ! BudgetAccess::can($user, $programId, BudgetAccess::PAYMENT)) {
            return $this->forbiddenFor(BudgetAccess::PAYMENT, 'Only the treasurer or the chair of this event can record payments.');
        }

        return null;
    }

    private function forbiddenFor(string $ability, string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => 'forbidden', 'permission' => $ability]], 403);
    }

    protected function fill(Model $model, array $validated, Request $request): void
    {
        parent::fill($model, $validated, $request);
        $model->chaned_by_id = (int) $request->user()->id; // who changed it last (and received a paid figure sent with it)
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;

        return [
            'budget_program_id' => [
                $existing ? 'sometimes' : 'required',
                Rule::exists('budget_programs', 'id')->where('company_id', $companyId),
            ],
            'treasurer_id' => [
                'nullable',
                Rule::exists('admin_users', 'id')->where('company_id', $companyId),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'], // normalised to E.164 by the model (422 when it is not a phone number)
            'amount' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'fully_paid' => ['nullable', Rule::in(['Yes', 'No'])],
            'custom_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * Contribution records are protected from deletion at the model level to preserve
     * the pledge audit trail.
     */
    public function destroy(Request $request, $id)
    {
        return $this->error('Contribution records cannot be deleted to preserve the pledge audit trail.', 422);
    }
}
