<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetProgram;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\CommitteeService;
use App\Services\Budget\EventBook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class BudgetProgramController extends BaseCrudController
{
    protected string $modelClass = BudgetProgram::class;

    protected string $resourceName = 'Budget program';

    // Financial totals are rollups computed from children.
    // event_type … payment_details: the event's settings (App\Services\Budget\EventSettings, cleaned by the model);
    // budget-programs/{id}/settings changes them for the chair and the treasurer too.
    protected array $writable = ['name', 'status', 'deadline', 'rsvp', 'logo', 'title', 'bottom', 'groups', 'is_default', 'is_active',
        'event_type', 'template_key', 'venue', 'settings', 'payment_details'];

    protected array $searchable = ['name', 'title'];

    protected array $sortable = ['id', 'name', 'deadline', 'created_at'];

    protected array $filterable = ['status', 'is_active', 'is_default'];

    /** Only the events this person has a seat on (BudgetAccess), wherever a list or a detail reads them. */
    protected function scopedQuery(Request $request)
    {
        return BudgetAccess::limitToSeats(parent::scopedQuery($request), $request->user(), 'budget_programs.id');
    }

    /**
     * GET budget-programs/{id}/figures: the event's money in one set of words (PB-14): In hand, Collected, Still owed,
     * Paid out, Budget, Still to find, with Extra, how the money came and who is holding it. Read only.
     */
    public function figures(Request $request, $id)
    {
        $program = $this->findOwned($request, $id);
        if ($program === null) {
            return $this->notFound('Budget program not found.');
        }

        return $this->success(app(EventBook::class)->position($this->companyId($request), (int) $program->getKey()), 'Figures loaded.');
    }

    /** GET budget-programs/{id}/team: who can be named as having taken money or a pledge, for a searchable list. Read only. */
    public function team(Request $request, $id)
    {
        $program = $this->findOwned($request, $id);
        if ($program === null) {
            return $this->notFound('Budget program not found.');
        }

        return $this->success(app(EventBook::class)->team($this->companyId($request), (int) $program->getKey()), 'Team listed.');
    }

    /**
     * POST budget-programs/{id}/print-link — a 30-minute link to this event's budget PDF (routes/web.php
     * `budget-program.pdf`) for the phone's browser, which has no session. Only the caller's own company.
     */
    public function printLink(Request $request, $id)
    {
        $program = BudgetProgram::where('company_id', (int) $request->user()->company_id)->find((int) $id);
        if ($program === null || ! BudgetAccess::canOpen($request->user(), (int) $program->id)) {
            return $this->error('Budget program not found.', 404);
        }
        $path = URL::temporarySignedRoute('budget-program.pdf', now()->addMinutes(30), ['id' => $program->id, 'company_id' => $program->company_id], false);

        return $this->success(['url' => url($path), 'expires_in' => 1800], 'Link ready.');
    }

    /** Whoever creates the event chairs it (CommitteeService::seatCreator). */
    protected function afterSave(Request $request, Model $model, bool $wasCreated): void
    {
        if ($wasCreated && $model instanceof BudgetProgram) {
            CommitteeService::seatCreator($model, (int) $request->user()->id);
        }
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'max:50'],
            'deadline' => ['nullable', 'date'],
            'rsvp' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:191'],
            'bottom' => ['nullable', 'string', 'max:1000'],
            'groups' => ['nullable', 'max:4000'],
            'event_type' => ['nullable', 'string', 'max:40'],
            'template_key' => ['nullable', 'string', 'max:60'],
            'venue' => ['nullable', 'string', 'max:191'],
            'settings' => ['nullable'],
            'payment_details' => ['nullable'],
            'is_default' => ['nullable'],
            'is_active' => ['nullable'],
        ];
    }
}
