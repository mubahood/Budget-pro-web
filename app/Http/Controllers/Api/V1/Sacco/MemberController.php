<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoMember;
use App\Models\SaccoMemberRelation;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\Members\Nin;
use App\Services\Sacco\Reports\SaccoReportLinks;
use App\Services\Sacco\Reports\SaccoReports;
use App\Services\Sacco\SaccoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Members (04 §13.2): search, register, edit, identity check, status, nominees and next of kin, documents, import, statement. */
class MemberController extends SaccoController
{
    /** GET sacco/members?q&status&per_page: by name, number, phone or NIN (looked up by its hash, never stored in a log). */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $cid = $this->cid($request);
        $q = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));
        if (filled($term = trim((string) $request->query('q')))) {
            $hash = preg_match('/^[A-Za-z0-9]{8,20}$/', $term) === 1 ? Nin::hash($term) : null;
            $q->where(fn ($w) => $w->where('member_no', $term)->orWhere('first_name', 'like', $term.'%')->orWhere('last_name', 'like', $term.'%')
                ->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $term).'%')->when($hash !== null, fn ($x) => $x->orWhere('nin_hash', $hash)));
        }
        $p = $q->orderBy('member_no')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn (SaccoMember $m) => Present::member($m))->all());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id) {
            $m = app(MemberService::class)->find($this->company($request), $id);
            $rel = SaccoMemberRelation::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->whereNull('ended_on')->get()->toArray();

            return $this->success(Present::member($m) + ['relations' => $rel]);
        });
    }

    /** POST sacco/members (sacco_members) */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $r = app(MemberService::class)->register($this->company($request), $request->all(), $this->user($request));

            return $this->created(['member' => Present::member($r['member']), 'duplicates' => $r['duplicates']], 'Member registered.');
        });
    }

    /** PUT sacco/members/{id} (sacco_members) */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Present::member(app(MemberService::class)->update($this->company($request), $id, $request->all(), $this->user($request))), 'Member saved.'));
    }

    /** POST sacco/members/{id}/kyc {decision: verified|rejected, note?} (sacco_kyc; never the officer who captured the member) */
    public function kyc(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->success(Present::member(app(MemberService::class)->verifyKyc($this->company($request), $id, $this->user($request), $d['decision'], $d['note'] ?? null)), 'Identity checked.'));
    }

    /** POST sacco/members/{id}/status {status, reason?} (sacco_members; applicant → active needs the entrance fee and minimum shares) */
    public function status(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:'.implode(',', SaccoMember::STATUSES)], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->success(Present::member(app(MemberService::class)->setStatus($this->company($request), $id, $d['status'], $this->user($request), $d['reason'] ?? null)), 'Status changed.'));
    }

    /** GET sacco/members/{id}/relations */
    public function relations(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id) {
            $m = app(MemberService::class)->find($this->company($request), $id);

            return $this->success(SaccoMemberRelation::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)
                ->when(! $request->boolean('all'), fn ($q) => $q->whereNull('ended_on'))->orderBy('id')->get()->makeHidden('nin_encrypted')->toArray());
        });
    }

    /**
     * POST sacco/members/{id}/relations (sacco_members): next of kin {role: next_of_kin, name, relationship?, phone?}, or the
     * whole nomination {role: nominee, nominees: [{name, share_bp, relationship?, phone?, nin?}], witness1, witness2, signed_on?}.
     */
    public function addRelation(Request $request, int $id): JsonResponse
    {
        $svc = app(MemberService::class);

        return $this->attempt(function () use ($request, $id, $svc) {
            if ($request->input('role') === 'nominee') {
                $out = $svc->nominate($this->company($request), $id, (array) $request->input('nominees', []), (string) $request->input('witness1', ''), (string) $request->input('witness2', ''),
                    $request->input('signed_on'), $this->user($request), $request->integer('document_file_id') ?: null);

                return $this->created(array_map(fn ($r) => $r->makeHidden('nin_encrypted')->toArray(), $out), 'Nominees saved.');
            }
            $d = $request->validate(['name' => ['required', 'string', 'max:150'], 'relationship' => ['nullable', 'string', 'max:60'], 'phone' => ['nullable', 'string', 'max:30']]);

            return $this->created($svc->addNextOfKin($this->company($request), $id, $d['name'], $d['relationship'] ?? null, $d['phone'] ?? null, $this->user($request))->makeHidden('nin_encrypted')->toArray(), 'Next of kin saved.');
        });
    }

    /** DELETE sacco/members/{id}/relations/{relationId} (sacco_members): ended, never deleted. */
    public function endRelation(Request $request, int $id, int $relationId): JsonResponse
    {
        return $this->attempt(function () use ($request, $id, $relationId) {
            $rel = SaccoMemberRelation::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('member_id', $id)->find($relationId);
            if ($rel === null) {
                return $this->notFound('That relation is not this member\'s.');
            }

            return $this->success(app(MemberService::class)->endRelation($this->company($request), $relationId, $this->user($request))->makeHidden('nin_encrypted')->toArray(), 'Ended.');
        });
    }

    /** POST sacco/members/{id}/documents {type, file_id, notes?} (sacco_members): a file uploaded through POST uploads. */
    public function document(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['type' => ['required', 'string', 'max:16'], 'file_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->created(app(MemberService::class)->attachDocument($this->company($request), $id, $d['type'], (int) $d['file_id'], $this->user($request), $d['notes'] ?? null)->toArray(), 'Document attached.'));
    }

    /** POST sacco/members/import {csv, dry_run=true} (sacco_members) */
    public function import(Request $request): JsonResponse
    {
        $d = $request->validate(['csv' => ['required', 'string', 'max:5000000'], 'dry_run' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->success(app(MemberService::class)->import($this->company($request), $d['csv'], $this->user($request), $d['dry_run'] ?? true), 'Import checked.'));
    }

    /** GET sacco/members/{id}/statement?from&to&format=json|pdf|xlsx|csv (sacco_reports): JSON, or a signed download link. */
    public function statement(Request $request, int $id): JsonResponse
    {
        $user = $this->user($request);
        if (! SaccoAccess::can($user, 'sacco_reports') && ! SaccoAccess::can($user, 'sacco_audit_view')) {
            return $this->error('Your role does not allow statements.', 403, ['code' => 'forbidden', 'permission' => 'sacco_reports']);
        }
        $params = ['member_id' => $id] + $request->only(['from', 'to', 'account_type', 'account_id']);
        $format = (string) $request->query('format', 'json');

        return $this->attempt(fn () => $format === 'json'
            ? $this->success((new SaccoReports())->build($this->company($request), 'member_statement', $params, $user))
            : $this->success(SaccoReportLinks::make($this->company($request), $user, 'member_statement', $params, $format), 'Download ready.'));
    }
}
