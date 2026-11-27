<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Services\Unity\CardTokens;
use App\Services\Unity\MemberActions;
use App\Services\Unity\MemberStatement;
use App\Services\Unity\UnityMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What happens to a member over time (approve, reject, transfer, suspend, reinstate, exit, died, a photo), their history,
 * statement and card. Writes need unity_members (ApiPermissionMap); me/card and me/statement are each person's own.
 */
class MemberActionsController extends UnityController
{
    /** POST unity/members/{id}/photo (multipart photo: jpeg/png, up to 5 MB) */
    public function photo(Request $request, int $id): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:5120']]);

        return $this->act($request, $id, fn ($m) => MemberActions::photo($this->company($request), $m, $request->user(), $request->file('photo')), 'Photo saved.');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn ($m) => MemberActions::approve($m, $request->user()), 'Approved.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($request, $id, fn ($m) => MemberActions::reject($m, $request->user(), $d['reason']), 'Rejected.');
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($request, $id, fn ($m) => MemberActions::suspend($m, $request->user(), $d['reason']), 'Suspended.');
    }

    public function reinstate(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn ($m) => MemberActions::reinstate($m, $request->user()), 'Reinstated.');
    }

    public function exit(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500'], 'on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->act($request, $id, fn ($m) => MemberActions::exit($m, $request->user(), $d['reason'], $d['on'] ?? null), 'Exit recorded.');
    }

    public function died(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'place' => ['sometimes', 'nullable', 'string', 'max:150']]);

        return $this->act($request, $id, fn ($m) => MemberActions::died($m, $request->user(), $d['on'], $d['place'] ?? null), 'Recorded. May they rest in peace.');
    }

    public function transfer(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['chapter_id' => ['required', 'integer'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->act($request, $id, fn ($m) => MemberActions::transfer($m, $request->user(), (int) $d['chapter_id'], $d['note'] ?? null), 'Transferred.');
    }

    /** GET unity/members/{id}/history: newest first. */
    public function history(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(MemberActions::history(UnityMembers::find($this->cid($request), $id)), 'History.'));
    }

    /** GET unity/members/{id}/statement: money only with unity_finance (or for the member's own record). */
    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $m = UnityMembers::find($this->cid($request), $id);
            $own = \App\Services\Unity\UnityAccess::memberId($this->cid($request), $request->user()) === (int) $m->id;

            return $this->success(MemberStatement::build($m, $own || $this->can($request, 'unity_finance')), 'Statement.');
        });
    }

    /** GET unity/me/statement: the signed-in person's own, money included. */
    public function myStatement(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(MemberStatement::build($this->ownMember($request), true), 'Your statement.'));
    }

    /** GET unity/members/{id}/card */
    public function card(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(CardTokens::card(UnityMembers::find($this->cid($request), $id)), 'Card.'));
    }

    /** GET unity/me/card */
    public function myCard(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(CardTokens::card($this->ownMember($request)), 'Your card.'));
    }

    /** @param callable(\App\Models\SaccoMember): mixed $fn */
    private function act(Request $request, int $id, callable $fn, string $message): JsonResponse
    {
        return $this->attempt(function () use ($request, $id, $fn, $message) {
            $m = UnityMembers::find($this->cid($request), $id);
            $fn($m);

            return $this->success(UnityMembers::present(UnityMembers::find($this->cid($request), $id)), $message);
        });
    }
}
