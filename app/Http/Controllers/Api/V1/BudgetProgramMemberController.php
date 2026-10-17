<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BudgetProgram;
use App\Models\BudgetProgramMember;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\CommitteeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An event's committee (roadmap 02 §6): GET/POST budget-programs/{id}/members, PUT/PATCH/DELETE
 * budget-programs/{id}/members/{memberId}. Every team member reads it; the chair of the event, the owner
 * and anyone with manage_budget change it (BudgetAccess::COMMITTEE). Inviting someone new by phone also
 * needs manage_team (it adds a person to the account).
 */
class BudgetProgramMemberController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CommitteeService $committee = new CommitteeService())
    {
    }

    public function index(Request $request, $id): JsonResponse
    {
        $program = $this->program($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }

        return $this->success([
            'members' => $this->committee->list($program),
            'my_role' => BudgetAccess::roleOn($request->user(), (int) $program->id),
            'abilities' => BudgetAccess::abilities($request->user(), (int) $program->id),
            'roles' => BudgetAccess::ROLES,
        ], 'Committee listed.');
    }

    /** {user_id, role} for a teammate, or {phone|email, name?, role} to invite someone new. */
    public function store(Request $request, $id): JsonResponse
    {
        $program = $this->program($request, $id);
        if ($program === null) {
            return $this->notFound('Event not found.');
        }
        if (! BudgetAccess::can($request->user(), (int) $program->id, BudgetAccess::COMMITTEE)) {
            return $this->refused(BudgetAccess::COMMITTEE, 'Only the chair of this event or the owner can change its committee.');
        }
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('admin_users', 'id')->where('company_id', $companyId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'role' => ['required', Rule::in(BudgetAccess::ROLES)],
            'uuid' => ['nullable', 'uuid'],
        ]);
        if (empty($data['user_id']) && ! empty($data['phone'] ?? $data['email'] ?? null) && ! \App\Services\Team\Permissions::can($request->user(), 'manage_team')) {
            return $this->refused('manage_team', 'Only the owner or a manager can invite new people to the account. Ask them to invite this person, then add them here.');
        }
        if (! empty($data['uuid']) && ($same = BudgetProgramMember::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $data['uuid'])->first()) !== null) {
            return $this->success(CommitteeService::present($same), 'Already on the committee.'); // a retried request
        }

        try {
            $member = $this->committee->add($request->user(), $program, $data);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->created(CommitteeService::present($member), $member->status === 'invited' ? 'Invite sent. They join the committee when they accept.' : 'Added to the committee.');
    }

    /** {role} */
    public function update(Request $request, $id, $memberId): JsonResponse
    {
        [$program, $member, $refusal] = $this->target($request, $id, $memberId);
        if ($refusal !== null) {
            return $refusal;
        }
        $data = $request->validate(['role' => ['required', Rule::in(BudgetAccess::ROLES)]]);

        try {
            $member = $this->committee->setRole($member, $data['role']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(CommitteeService::present($member), 'Role changed.');
    }

    public function destroy(Request $request, $id, $memberId): JsonResponse
    {
        [$program, $member, $refusal] = $this->target($request, $id, $memberId);
        if ($refusal !== null) {
            return $refusal;
        }
        $this->committee->remove($member);

        return $this->success(null, 'Removed from the committee. They are still on your team.');
    }

    /** @return array{0: ?BudgetProgram, 1: ?BudgetProgramMember, 2: ?JsonResponse} */
    private function target(Request $request, $id, $memberId): array
    {
        $program = $this->program($request, $id);
        $member = $program === null ? null : BudgetProgramMember::withoutGlobalScopes()->where('company_id', $program->company_id)
            ->where('budget_program_id', $program->id)->where('id', (int) $memberId)->where('is_deleted', 0)->first();
        if ($program === null || $member === null) {
            return [null, null, $this->notFound('Committee member not found.')];
        }
        if (! BudgetAccess::can($request->user(), (int) $program->id, BudgetAccess::COMMITTEE)) {
            return [null, null, $this->refused(BudgetAccess::COMMITTEE, 'Only the chair of this event or the owner can change its committee.')];
        }

        return [$program, $member, null];
    }

    private function program(Request $request, $id): ?BudgetProgram
    {
        return BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', (int) $request->user()->company_id)
            ->where('id', (int) $id)->where('is_deleted', 0)->first();
    }

    private function refused(string $ability, string $message): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => 'forbidden', 'permission' => $ability]], 403);
    }
}
