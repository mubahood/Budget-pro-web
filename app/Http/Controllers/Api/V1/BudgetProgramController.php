<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetProgram;
use App\Services\Budget\CommitteeService;
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

    /**
     * POST budget-programs/{id}/print-link — a 30-minute link to this event's budget PDF (routes/web.php
     * `budget-program.pdf`) for the phone's browser, which has no session. Only the caller's own company.
     */
    public function printLink(Request $request, $id)
    {
        $program = BudgetProgram::where('company_id', (int) $request->user()->company_id)->find((int) $id);
        if ($program === null) {
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
