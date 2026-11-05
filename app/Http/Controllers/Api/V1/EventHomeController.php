<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionPayment;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\EventBook;
use App\Services\Budget\EventChoice;
use App\Services\Budget\EventExport;
use App\Services\Budget\EventInvitations;
use App\Services\Budget\EventSettings;
use App\Services\Budget\PledgeService;
use App\Services\Onboarding\PublicDemo;
use App\Services\Team\Permissions;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Pledge & Budget phone app asks that sync cannot answer: who this person is on each event and which they are in, and the actions that need the server's own say
 * (a pasted list, closing, documents, a receipt's link). Rights are BudgetAccess's, the same as the web's. Reads and writes of money stay in their own controllers and in sync.
 */
class EventHomeController extends Controller
{
    use ApiResponse;

    /** GET budget/home */
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        $events = EventChoice::events($user);
        $chosen = $user->getAttribute('main_event_id');
        $chosen = is_numeric($chosen) && in_array((int) $chosen, array_column($events, 'id'), true) ? (int) $chosen : null;

        return $this->success([
            'main_event_id' => $chosen ?? EventChoice::workedOut($events),
            'chosen_event_id' => $chosen,
            'can_create' => BudgetAccess::fullAccess($user),
            'can_invite' => Permissions::can($user, 'manage_team'),
            'demo' => PublicDemo::isDemoUser($user),
            'seats' => BudgetAccess::seats($user),
            'titles' => EventInvitations::TITLES,
            'events' => array_map(fn (array $e) => $e + ['can' => BudgetAccess::capabilities($user, $e['id'])], $events),
        ], 'Events listed.');
    }

    /**
     * PUT budget/main-event {event_id|null}: "Set as default event", for this person on every device. null goes back to working it out. A public demo's sign-in is shared by
     * every visitor, so there the choice stays on the phone: the answer says done and nothing is written.
     */
    public function mainEvent(Request $request): JsonResponse
    {
        $data = $request->validate(['event_id' => ['present', 'nullable', 'integer']]);
        $user = $request->user();
        $id = $data['event_id'] === null ? null : (int) $data['event_id'];
        if ($id !== null && ! in_array($id, array_column(EventChoice::events($user), 'id'), true)) {
            return $this->notFound('Event not found.');
        }
        if (! PublicDemo::isDemoUser($user)) {
            DB::table('admin_users')->where('id', $user->id)->update(['main_event_id' => $id]);
        }

        return $this->success(['chosen_event_id' => $id], 'Default event saved.');
    }

    /** GET budget-programs/{id}/withdrawn: pledges taken off the book, with why, for the list they can be put back from. */
    public function withdrawn(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $rows = app(EventBook::class)->withdrawn((int) $program->company_id, (int) $program->id);
        BudgetAccess::hidePhones($rows, $request->user());
        // Who may put each one back depends on who entered it and when (BudgetAccess::canWithdraw), which only the server knows for sure.
        $open = EventBook::isOpen($program->status);
        $pledges = DB::table('contribution_records')->whereIn('id', $rows->pluck('id')->all())->get(['id', 'budget_program_id', 'treasurer_id', 'created_at'])->keyBy('id');

        return $this->success(['pledges' => $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $r->name, 'phone' => $r->phone ?? null, 'amount' => (string) $r->amount, 'category_id' => $r->category_id ?? null,
            'withdrawn_at' => $r->withdrawn_at ?? null, 'withdrawn_reason' => $r->withdrawn_reason ?? null, 'withdrawn_by' => $r->withdrawn_by ?? null,
            'may_put_back' => $open && isset($pledges[$r->id]) && BudgetAccess::canWithdraw($request->user(), $pledges[$r->id]),
        ])->values()->all()], 'Withdrawn pledges listed.');
    }

    /** POST budget-programs/{id}/pledges/paste {text}: how the list reads, line by line. Writes nothing. */
    public function paste(Request $request, $id): JsonResponse
    {
        $program = $this->writable($request, $id);
        if ($program instanceof JsonResponse) {
            return $program;
        }
        $data = $request->validate(['text' => ['required', 'string', 'max:60000']]);
        $rows = app(PledgeService::class)->parse($program, (string) $data['text']);

        return $this->success(['rows' => $rows, 'ready' => count(array_filter($rows, fn ($r) => PledgeService::isReady($r))), 'to_check' => count(array_filter($rows, fn ($r) => ($r['check'] ?? null) !== null && ($r['error'] ?? null) === null && empty($r['duplicate'])))], 'List read.');
    }

    /** POST budget-programs/{id}/pledges/import {text, amounts_are_right?}: read again here, so what is added is what the server read, never what a client says it read. */
    public function import(Request $request, $id): JsonResponse
    {
        $program = $this->writable($request, $id);
        if ($program instanceof JsonResponse) {
            return $program;
        }
        $data = $request->validate(['text' => ['required', 'string', 'max:60000'], 'amounts_are_right' => ['nullable', 'boolean']]);
        $service = app(PledgeService::class);
        try {
            $added = $service->import($request->user(), $program, $service->parse($program, (string) $data['text']), $request->boolean('amounts_are_right'));
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(['added' => $added], $added === 1 ? '1 pledge added.' : $added.' pledges added.');
    }

    /** POST budget-programs/{id}/close {held_by?, note?}: moves no money. It says who holds what is in hand, for the final account. */
    public function close(Request $request, $id): JsonResponse
    {
        $program = $this->managed($request, $id);
        if ($program instanceof JsonResponse) {
            return $program;
        }
        $data = $request->validate(['held_by' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:300']]);
        $held = trim((string) ($data['held_by'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));
        try {
            if ($held !== '' || $note !== '') {
                app(EventSettings::class)->update($request->user(), $program, ['settings' => ['closing' => ['held_by' => $held, 'note' => $note]]]);
                $program = $program->fresh() ?? $program;
            }
            $program->status = 'Inactive';
            $program->save();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['status' => $program->status, 'open' => false], 'Event closed. Everything is kept; reopen it any time.');
    }

    /** POST budget-programs/{id}/reopen {} */
    public function reopen(Request $request, $id): JsonResponse
    {
        $program = $this->managed($request, $id);
        if ($program instanceof JsonResponse) {
            return $program;
        }
        try {
            $program->status = 'Active';
            $program->save();
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['status' => $program->status, 'open' => true], 'Event reopened: pledges and payments can be recorded again.');
    }

    /** GET budget-programs/{id}/summary?names=1: the words for the committee's WhatsApp group. No names unless asked and allowed. */
    public function summary(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $names = $request->boolean('names');
        if ($names && ! BudgetAccess::canShareNames($request->user(), (int) $program->id)) {
            return $this->refused('Only the chair or the treasurer can share a list with names.');
        }
        $text = app(EventExport::class)->whatsappSummary($this->company($program), (int) $program->id, $names);

        return $this->success(['text' => $text, 'names' => $names], 'Summary ready.');
    }

    /** GET budget-programs/{id}/documents: what this person may take, and in which formats. */
    public function documents(Request $request, $id): JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $user = $request->user();
        $list = [];
        foreach (EventExport::DOCUMENTS as $doc) {
            $list[] = ['doc' => $doc, 'formats' => EventExport::FORMATS[$doc], 'allowed' => EventExport::allowed($user, (int) $program->id, $doc)];
        }

        return $this->success(['documents' => $list, 'share_names' => BudgetAccess::canShareNames($user, (int) $program->id)], 'Documents listed.');
    }

    /** GET budget-programs/{id}/documents/{doc}.{format}?names&blank&group&status: the file. Rights as the web's; a file that cannot be made says so in words. */
    public function document(Request $request, $id, string $doc, string $format): Response
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        $user = $request->user();
        if (! EventExport::allowed($user, (int) $program->id, $doc)) {
            return $this->refused('Your role on this event does not allow taking this document.');
        }
        $names = $request->boolean('names');
        if ($names && ! BudgetAccess::canShareNames($user, (int) $program->id)) {
            return $this->refused('Only the chair or the treasurer can take the report with names.');
        }
        try {
            $file = app(EventExport::class)->make($this->company($program), (int) $program->id, $doc, $format, [
                'names' => $names, 'blank' => $request->boolean('blank'), 'group' => (string) $request->query('group', ''), 'status' => (string) $request->query('status', 'all'),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('This document could not be made. Nothing was lost. Please try again.', 500);
        }
        if ($file === null) {
            return $this->notFound('This document does not come in that format.');
        }

        return EventExport::respond($file, 'attachment');
    }

    /** POST contribution-payments/{id}/receipt-link {}: the link a person can open with no account, for that payment alone. A reversal has no receipt. */
    public function receiptLink(Request $request, $id): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $payment = ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('id', (int) $id)->where('is_deleted', 0)->first();
        if ($payment === null || ! BudgetAccess::canOpen($request->user(), (int) $payment->budget_program_id)) {
            return $this->notFound('Payment not found.');
        }
        if (! BudgetAccess::canRemind($request->user(), (int) $payment->budget_program_id)) {
            return $this->refused('Your role on this event does not allow sending receipts.');
        }
        if ($payment->reverses_id) {
            return $this->error('A reversal has no receipt.', 422);
        }
        $path = EventExport::receiptLink($companyId, (string) $payment->uuid);
        $url = url($path);

        return $this->success(['url' => $url, 'pdf_url' => $url.'&format=pdf', 'days' => EventExport::RECEIPT_DAYS], 'Link ready.');
    }

    private function event(Request $request, $id): ?BudgetProgram
    {
        $program = BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', (int) $request->user()->company_id)->where('id', (int) $id)->where('is_deleted', 0)->first();

        return $program !== null && BudgetAccess::canOpen($request->user(), (int) $program->id) ? $program : null;
    }

    private function writable(Request $request, $id): BudgetProgram|JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        if (! BudgetAccess::can($request->user(), (int) $program->id, BudgetAccess::PLEDGE)) {
            return $this->refused('Your role on this event does not allow adding pledges.');
        }
        if (! EventBook::isOpen($program->status)) {
            return $this->error('This event is closed. Ask the chair to reopen it.', 422, ['code' => 'event_closed']);
        }

        return $program;
    }

    private function managed(Request $request, $id): BudgetProgram|JsonResponse
    {
        $program = $this->event($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }

        return BudgetAccess::canManageEvent($request->user(), (int) $program->id) ? $program : $this->refused('Only the chair of this event or the owner can close or reopen it.');
    }

    private function company(BudgetProgram $program): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($program->company_id);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => 'forbidden']], 403);
    }
}
