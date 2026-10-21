<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoApproval;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Maker-checker requests (04 §7, §13.2). GET sacco/approvals?status=pending&action: each item with its `summary`,
 * `is_mine` and `requested_by_name`, and the frozen payload in full; approve and reject need sacco_approve (never on
 * one's own request), cancel is the maker's own.
 */
class ApprovalController extends SaccoController
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request) && ! SaccoAccess::can($request->user(), 'sacco_approve')) {
            return $this->refuseRead();
        }
        $status = (string) $request->query('status', '');
        $q = SaccoApproval::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('action'), fn ($w, $a) => $w->where('action', $a))
            ->when($request->boolean('mine'), fn ($w) => $w->where('requested_by_id', $request->user()->id));
        if ($status === 'pending' || $status === 'approved') {
            $q->where('status', $status)->where('expires_at', '>=', now());
        } elseif ($status === 'expired') {
            $q->where(fn ($w) => $w->where('status', 'expired')->orWhere(fn ($x) => $x->whereIn('status', ['pending', 'approved'])->where('expires_at', '<', now())));
        } elseif ($status !== '') {
            $q->where('status', $status);
        }
        $p = $q->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($a) => Present::approval($a, $this->user($request)))->all());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request) && ! SaccoAccess::can($request->user(), 'sacco_approve')) {
            return $this->refuseRead();
        }
        $a = SaccoApproval::withoutGlobalScopes()->with('votes')->where('company_id', $this->cid($request))->find($id);

        return $a === null ? $this->notFound('That request is not in this SACCO.') : $this->success(Present::approval($a, $this->user($request)) + ['votes' => $a->votes->toArray()]);
    }

    /** POST sacco/approvals/{id}/approve {note?}: executes once enough checkers approve. */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoApprovals::class)->approve($this->company($request), $id, $this->user($request), $request->input('note')), $this->user($request)), 'Approved.'));
    }

    /** POST sacco/approvals/{id}/reject {note} */
    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoApprovals::class)->reject($this->company($request), $id, $this->user($request), (string) $request->input('note', '')), $this->user($request)), 'Rejected.'));
    }

    /** POST sacco/approvals/{id}/cancel: the maker takes it back. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoApprovals::class)->cancel($this->company($request), $id, $this->user($request)), $this->user($request)), 'Cancelled.'));
    }

    /** POST sacco/approvals/{id}/execute: run an approved request again after a refusal (sacco_approve). */
    public function execute(Request $request, int $id): JsonResponse
    {
        if (! SaccoAccess::can($request->user(), 'sacco_approve')) {
            return $this->error('Your role does not allow this.', 403, ['code' => 'forbidden', 'permission' => 'sacco_approve']);
        }

        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoApprovals::class)->execute($this->company($request), $id, $this->user($request)), $this->user($request)), 'Done.'));
    }
}
