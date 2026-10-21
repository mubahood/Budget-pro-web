<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BudgetProgram;
use App\Services\Budget\BudgetTemplates;
use App\Services\Budget\EventSettings;
use App\Services\Budget\MessageTemplates;
use App\Services\Budget\TemplateApplier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An event's settings and its budget template (App\Services\Budget\EventSettings, TemplateApplier):
 *
 *   GET  budget-programs/{id}/settings        type, venue, pledge groups, message texts (own and default), presets,
 *                                             payment details, and what the caller may change (`can`)
 *   PUT  budget-programs/{id}/settings        any of: event_type, venue, groups (list of names), settings (object,
 *                                             merged), payment_details (object). Settings: the chair or the owner;
 *                                             payment details: also the treasurer.
 *   POST budget-programs/{id}/apply-template  {template, guests?, plan?}: the template's categories and items into
 *                                             the event's budget (merge rules in TemplateApplier; never deletes).
 *                                             Without a plan, the whole template for the guests.
 */
class BudgetProgramSettingsController extends Controller
{
    use ApiResponse;

    public function show(Request $request, $id): JsonResponse
    {
        $program = $this->program($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }

        return $this->success((new EventSettings())->present($request->user(), $program), 'Event settings.');
    }

    public function update(Request $request, $id): JsonResponse
    {
        $program = $this->program($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $data = $request->validate([
            'event_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'venue' => ['sometimes', 'nullable', 'string', 'max:191'],
            'groups' => ['sometimes', 'nullable', 'array', 'max:'.EventSettings::MAX_GROUPS],
            'groups.*' => ['string', 'max:60'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.messages' => ['sometimes', 'nullable', 'array'],
            'settings.messages.*' => ['nullable', 'string', 'max:'.EventSettings::MAX_MESSAGE],
            'settings.message_lang' => ['sometimes', 'nullable', Rule::in(MessageTemplates::LANGS)],
            'settings.presets' => ['sometimes', 'nullable', 'array', 'max:'.EventSettings::MAX_PRESETS],
            'settings.presets.*' => ['numeric', 'min:1'],
            'settings.currency_display' => ['sometimes', 'nullable', 'string', 'max:12'],
            'settings.guests' => ['sometimes', 'nullable', 'integer', 'min:'.BudgetTemplates::MIN_GUESTS, 'max:'.BudgetTemplates::MAX_GUESTS],
            'settings.meeting' => ['sometimes', 'nullable', 'array'],
            'settings.meeting.when' => ['nullable', 'string', 'max:120'],
            'settings.meeting.where' => ['nullable', 'string', 'max:120'],
            'payment_details' => ['sometimes', 'nullable', 'array'],
            'payment_details.*' => ['nullable', 'string', 'max:300'],
        ]);
        if (isset($data['settings']['messages'])) {
            $unknown = array_diff(array_keys($data['settings']['messages']), MessageTemplates::KINDS);
            if ($unknown !== []) {
                return $this->validationError(['settings.messages' => ['Unknown message kind: '.implode(', ', $unknown).'.']]);
            }
        }

        try {
            $program = (new EventSettings())->update($request->user(), $program, $data);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : 422, $e->toErrors());
        }

        return $this->success((new EventSettings())->present($request->user(), $program), 'Event settings saved.');
    }

    public function applyTemplate(Request $request, $id): JsonResponse
    {
        $program = $this->program($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $data = $request->validate([
            'template' => ['required', 'string', 'max:60'],
            'guests' => ['nullable', 'integer', 'min:'.BudgetTemplates::MIN_GUESTS, 'max:'.BudgetTemplates::MAX_GUESTS],
            'plan' => ['nullable', 'array', 'max:60'],
            'plan.*.name' => ['required_with:plan', 'string', 'max:100'],
            'plan.*.items' => ['required_with:plan', 'array', 'max:'.TemplateApplier::MAX_LINES],
            'plan.*.items.*.name' => ['required', 'string', 'max:150'],
            'plan.*.items.*.unit' => ['nullable', 'string', 'max:30'],
            'plan.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'plan.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        if (BudgetTemplates::find($data['template']) === null) {
            return $this->notFound('This budget template was not found.');
        }
        $applier = new TemplateApplier();
        try {
            $report = isset($data['plan'])
                ? $applier->apply($request->user(), $program, $data['plan'], $data['template'], isset($data['guests']) ? (int) $data['guests'] : null)
                : $applier->applyTemplate($request->user(), $program, $data['template'], isset($data['guests']) ? (int) $data['guests'] : null);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : 422, $e->toErrors());
        }

        return $this->success($report, $report['items_added'] > 0 ? "Added {$report['items_added']} budget items." : 'Nothing new to add: every item is already in the budget.');
    }

    private function program(Request $request, $id): ?BudgetProgram
    {
        return BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', (int) $request->user()->company_id)
            ->where('id', (int) $id)->where('is_deleted', 0)->first();
    }
}
