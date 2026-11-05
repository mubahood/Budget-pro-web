<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BudgetProgram;
use App\Models\EventCardTemplate;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\CardFiles;
use App\Services\Budget\CardFonts;
use App\Services\Budget\CardTemplates;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invitation card designs for the phone. Anyone with a seat reads them and makes cards; only those CardTemplates allows change them, and the shared demo takes no files. A design
 * has files, so it changes here and not by sync; its figures come down to phones as event_card_templates. Multipart edits are POST, since PHP reads no multipart on PUT.
 */
class EventCardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CardTemplates $cards = new CardTemplates) {}

    /** GET budget-programs/{id}/card-templates */
    public function index(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $rows = $this->cards->forEvent((int) $program->company_id, (int) $program->id);

        return $this->success([
            'templates' => $rows->map(fn (EventCardTemplate $t) => $this->present($t))->values()->all(),
            'may_change' => BudgetAccess::canEditSettings($request->user(), (int) $program->id),
            'limit' => CardTemplates::MAX_PER_EVENT,
            'fonts' => CardFonts::list(),
            'defaults' => CardTemplates::DEFAULTS,
        ], 'Card designs listed.');
    }

    /** POST budget-programs/{id}/card-templates, multipart: card (png, jpg, webp or pdf), picture (the chosen page, for a pdf), name, is_default and the figures. */
    public function store(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $request->validate($this->fileRules() + ['card' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf']]);

        return $this->attempt(fn () => $this->created($this->present($this->cards->create($request->user(), $program, $this->input($request), $request->file('card'), $request->file('picture'))), 'Card saved.'));
    }

    /** POST card-templates/{id}, multipart or JSON: the figures, and a new file when one is sent. */
    public function update(Request $request, $id): JsonResponse
    {
        $template = $this->template($request, $id);
        if ($template === null) {
            return $this->notFound('Card design not found.');
        }
        $request->validate($this->fileRules());

        return $this->attempt(fn () => $this->success($this->present($this->cards->update($request->user(), $template, $this->input($request), $request->file('card'), $request->file('picture'))), 'Card saved.'));
    }

    /** POST card-templates/{id}/default {default} */
    public function setDefault(Request $request, $id): JsonResponse
    {
        $template = $this->template($request, $id);
        if ($template === null) {
            return $this->notFound('Card design not found.');
        }
        $data = $request->validate(['default' => ['required', 'boolean']]);

        return $this->attempt(function () use ($request, $template, $data) {
            $this->cards->setDefault($request->user(), $template, (bool) $data['default']);

            return $this->success($this->present($template->fresh() ?? $template), 'Saved.');
        });
    }

    /** DELETE card-templates/{id} */
    public function destroy(Request $request, $id): JsonResponse
    {
        $template = $this->template($request, $id);
        if ($template === null) {
            return $this->notFound('Card design not found.');
        }

        return $this->attempt(function () use ($request, $template) {
            $this->cards->delete($request->user(), $template);

            return $this->success(null, 'Card design deleted. The guest list keeps its names.');
        });
    }

    /** POST budget-programs/{id}/card-templates/sample {} */
    public function sample(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }

        return $this->attempt(fn () => $this->created($this->present($this->cards->addSample($request->user(), $program)), 'Sample card added.'));
    }

    /** GET card-templates/{id}/file/{picture|source}: its address changes when its picture changes, so a phone may keep it. */
    public function file(Request $request, $id, string $kind): Response
    {
        $template = $this->template($request, $id);
        if ($template === null) {
            return $this->notFound('Card design not found.');
        }
        $isSource = $kind === 'source';
        $path = CardFiles::path($isSource ? $template->source_path : $template->background_path);
        if ($path === null) {
            return $this->notFound('This file is not here.');
        }

        return response()->file($path, [
            'Content-Type' => $isSource ? 'application/pdf' : $template->background_mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(EventCardTemplate $t): array
    {
        return [
            'id' => (int) $t->id, 'uuid' => (string) $t->uuid, 'budget_program_id' => (int) $t->budget_program_id, 'name' => (string) $t->name, 'source_type' => (string) $t->source_type,
            'background_w' => (int) $t->background_w, 'background_h' => (int) $t->background_h, 'pdf_page' => (int) $t->pdf_page, 'has_source' => (bool) $t->source_path,
            'font_key' => CardFonts::exists($t->font_key) ? $t->font_key : CardFonts::DEFAULT, 'font_size' => (int) $t->font_size, 'color' => (string) $t->color, 'name_y' => (float) $t->name_y,
            'letter_case' => (string) $t->letter_case, 'letter_spacing' => (int) $t->letter_spacing, 'max_width' => (int) $t->max_width, 'is_default' => (bool) $t->is_default,
            'file_version' => substr(md5((string) $t->background_path), 0, 10), 'server_seq' => (int) $t->server_seq, 'version' => (int) $t->version,
        ];
    }

    /** @return array<string, mixed> */
    private function fileRules(): array
    {
        return ['card' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'], 'picture' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp']];
    }

    /** @return array<string, mixed> what CardTemplates checks: nothing else is taken from the request */
    private function input(Request $request): array
    {
        return $request->only(['name', 'is_default', 'font_key', 'font_size', 'color', 'name_y', 'letter_case', 'letter_spacing', 'max_width', 'pdf_page']);
    }

    private function attempt(callable $work): JsonResponse
    {
        try {
            return $work();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : 422, $e->toErrors());
        }
    }

    private function event(Request $request, $id): ?BudgetProgram
    {
        $program = BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();

        return $program !== null && BudgetAccess::canOpen($request->user(), (int) $program->id) ? $program : null;
    }

    private function template(Request $request, $id): ?EventCardTemplate
    {
        $row = EventCardTemplate::withoutGlobalScopes()->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();

        return $row !== null && BudgetAccess::canOpen($request->user(), (int) $row->budget_program_id) ? $row : null;
    }
}
