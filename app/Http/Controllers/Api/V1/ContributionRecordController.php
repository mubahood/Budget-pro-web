<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventBook;
use App\Services\Budget\PledgeService;
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
    // received_by_id: who took the pledge (OB-06), someone on the event's team; the caller when left out.
    protected array $writable = ['budget_program_id', 'treasurer_id', 'received_by_id', 'name', 'phone', 'amount', 'paid_amount', 'fully_paid', 'custom_amount', 'custom_paid_amount', 'category_id'];

    protected array $searchable = ['name'];

    protected array $sortable = ['id', 'name', 'amount', 'paid_amount', 'created_at'];

    protected array $filterable = ['budget_program_id', 'treasurer_id', 'fully_paid', 'phone'];

    protected array $listWith = ['budgetProgram', 'treasurer'];

    /** @var array<string, bool> caller:event => may they see its phone numbers, so a long list asks once per event */
    private array $phonesOn = [];

    /**
     * Only the events this person has a seat on (PB-09), wherever a list, a search or a detail reads pledges. An in-kind
     * pledge has no money in it and is web only until PB-32, so older clients never meet a row they cannot show.
     */
    protected function scopedQuery(Request $request)
    {
        return BudgetAccess::limitToSeats(parent::scopedQuery($request), $request->user(), $this->table().'.budget_program_id')
            ->where(fn ($q) => $q->whereNull($this->table().'.kind')->orWhere($this->table().'.kind', '!=', EventBook::KIND_IN_KIND));
    }

    /** What each pledge adds to the older shape: Extra, and who took it. A viewer's copy has no phone number (the field stays, empty). */
    protected function transform(Model $model)
    {
        if ($model instanceof ContributionRecord) {
            $user = request()->user();
            $event = (int) $model->budget_program_id;
            $key = ((int) $user?->id).':'.$event;
            $this->phonesOn[$key] ??= BudgetAccess::canSeePhones($user, $event);
            if (! $this->phonesOn[$key]) {
                $model->setAttribute('phone', null);
            }
            $model->setAttribute('extra_amount', Decimal::max(Decimal::sub((string) $model->paid_amount, (string) $model->amount), '0'));
            $model->setAttribute('received_by_id', $model->received_by_id ?? $model->treasurer_id);
            BudgetAccess::hideNested($model, $user);
        }

        return $model;
    }

    /** Someone who may not see phone numbers cannot look for one either. */
    protected function applyFilters(Request $request, $query): void
    {
        $filters = $request->input('filter', []);
        if (is_array($filters) && array_key_exists('phone', $filters)) {
            $query->where(fn ($q) => BudgetAccess::limitToPhones($q, $request->user(), $this->table().'.budget_program_id'));
        }
        parent::applyFilters($request, $query);
    }

    /**
     * POST contribution-records/print-link { category_id } — a 30-minute link to the printable pledge
     * list of this person's own company (routes/web.php `data-exports.print`), to open in the phone's
     * browser or share. Relative-signed, so the host's http/https proxy cannot invalidate it.
     */
    public function printLink(Request $request)
    {
        $data = $request->validate(['category_id' => ['required', 'string', 'max:191'], 'budget_program_id' => ['nullable', 'integer']]);
        $companyId = (int) $request->user()->company_id;
        $link = ['id' => $data['category_id'], 'company_id' => $companyId];
        // An older app names no event: it gets its most recent open one that has this group and that it may take away (PB-09),
        // and the page says so. Someone with full access on an account with no such event gets an empty list, as before.
        if (empty($data['budget_program_id'])) {
            $program = $this->olderAppsEvent($request, (string) $data['category_id']);
            if ($program !== null) {
                $link += ['program' => (int) $program->id, 'older' => 1];
            } elseif (! BudgetAccess::fullAccess($request->user())) {
                return $this->error('Your role does not allow taking this list away. Ask the chair.', 403);
            }
        }
        // A newer app says which event: the list is that event's alone (PB-10), and takes the right to take names away (PB-09).
        if (! empty($data['budget_program_id'])) {
            $program = BudgetProgram::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find((int) $data['budget_program_id']);
            if ($program === null) {
                return $this->error('Budget program not found.', 404);
            }
            if (! BudgetAccess::canExport($request->user(), (int) $program->id)) {
                return $this->error('Your role on this event does not allow taking the list away. Ask the chair.', 403);
            }
            $link['program'] = (int) $program->id;
        }
        $path = URL::temporarySignedRoute('data-exports.print', now()->addMinutes(30), $link, false);

        return $this->success(['url' => url($path), 'expires_in' => 1800], 'Link ready.');
    }

    private function olderAppsEvent(Request $request, string $group): ?BudgetProgram
    {
        $companyId = (int) $request->user()->company_id;
        $withGroup = ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])->where('company_id', $companyId)->where('category_id', $group)->where('is_deleted', 0)->pluck('budget_program_id')->unique();
        $mine = BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', $companyId)->where('is_deleted', 0)->whereIn('id', $withGroup)->orderByDesc('id')->get()
            ->filter(fn (BudgetProgram $p) => BudgetAccess::canExport($request->user(), (int) $p->id));

        return $mine->first(fn (BudgetProgram $p) => EventBook::isOpen($p->status)) ?? $mine->first();
    }

    /** POST contribution-records/{id}/withdraw {reason}: PledgeService::withdraw. Who may: BudgetAccess::canWithdraw. */
    public function withdraw(Request $request, $id)
    {
        $pledge = $this->findOwned($request, $id);
        if ($pledge === null) {
            return $this->notFound('Pledge not found.');
        }
        if (! $pledge instanceof ContributionRecord || ! BudgetAccess::canWithdraw($request->user(), $pledge)) {
            return $this->forbiddenFor(BudgetAccess::WITHDRAW, 'Only the chair or the treasurer can withdraw this pledge. You can withdraw one you entered today with no payment.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            $done = (new PledgeService())->withdraw($request->user(), $pledge, $data['reason']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($done->withoutRelations(), 'Pledge withdrawn.');
    }

    /** POST contribution-records/{id}/put-back: a withdrawn pledge returns, as a new sync version. */
    public function putBack(Request $request, $id)
    {
        $pledge = ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])->where('company_id', $this->companyId($request))->where('is_deleted', 1)->whereNotNull('withdrawn_at')->find((int) $id);
        if ($pledge === null || ! BudgetAccess::canOpen($request->user(), (int) $pledge->budget_program_id)) {
            return $this->notFound('Pledge not found.');
        }
        if (! BudgetAccess::canWithdraw($request->user(), $pledge)) {
            return $this->forbiddenFor(BudgetAccess::WITHDRAW, 'Only the chair or the treasurer can put a pledge back.');
        }

        try {
            $done = (new PledgeService())->putBack($request->user(), $pledge);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success($done->withoutRelations(), 'Pledge put back.');
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
            // Below what is paid is refused here; an older phone's edit through sync is accepted and shows as extra (PB-03).
            $custom = $request->input('custom_amount');
            $wanted = Decimal::valid($custom) && Decimal::cmp((string) $custom, '0') > 0 ? $custom : $request->input('amount');
            if (Decimal::valid($wanted)) {
                try {
                    PledgeService::refuseBelowPaid($this->companyId($request), (int) $existing->getKey(), Decimal::of((string) $wanted));
                } catch (BusinessRuleException $e) {
                    return $this->error($e->getMessage(), 422, $e->toErrors());
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
        if (BudgetAccess::changesPaidFigure($request->only(['custom_paid_amount', 'paid_amount', 'fully_paid']), $existing) && ! BudgetAccess::can($user, $programId, BudgetAccess::PAYMENT)) {
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
        if (! $model->exists && empty($model->received_by_id)) {
            $model->received_by_id = (int) $request->user()->id;
        }
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $companyId = (int) $request->user()->company_id;
        $exact = ContributionPayments::exactRule($companyId);
        $event = (int) ($request->input('budget_program_id') ?: $existing?->budget_program_id);

        return [
            'received_by_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $event) {
                if ((int) $value > 0 && ! BudgetAccess::onTeam($companyId, $event, (int) $value)) {
                    $fail("Choose someone on this event's team.");
                }
            }],
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
            'amount' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0', $exact],
            'paid_amount' => ['nullable', 'numeric', 'min:0', $exact],
            'fully_paid' => ['nullable', Rule::in(['Yes', 'No'])],
            'custom_amount' => ['nullable', 'numeric', 'min:0', $exact],
            'custom_paid_amount' => ['nullable', 'numeric', 'min:0', $exact],
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
