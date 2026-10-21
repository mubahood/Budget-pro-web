<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoOfficer;
use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoSettingsService;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\SaccoSummary;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Setup, settings, the overview, officers, the audit trail and the ledger check (04 §13.2). */
class BooksController extends SaccoController
{
    /** POST sacco/setup (the owner, once): mode, names, defaults; seeds the chart, periods and products. */
    public function setup(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created(app(SaccoSetupService::class)->setup($this->company($request), $request->all(), $this->user($request))->toArray(), 'SACCO set up.'));
    }

    /** GET sacco/settings */
    public function settings(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $s = SaccoSetting::for($this->cid($request));

        return $s === null ? $this->error('Set up the SACCO first.', 404, ['code' => 'not_set_up']) : $this->success($s->toArray() + ['regulated_line' => $s->regulatedLine()]);
    }

    /** PUT sacco/settings (sacco_settings) */
    public function updateSettings(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(app(SaccoSettingsService::class)->update($this->cid($request), $request->all(), (int) $request->user()->id)->toArray(), 'Settings saved.'));
    }

    /** GET sacco/overview */
    public function overview(Request $request): JsonResponse
    {
        return $this->mayRead($request) ? $this->success((new SaccoSummary())->for($this->cid($request))) : $this->refuseRead();
    }

    /** GET sacco/officers?all=1 */
    public function officers(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $q = SaccoOfficer::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderBy('position')->orderByDesc('starts_on');
        if (! $request->boolean('all')) {
            $q->activeOn(LocalDate::today($this->cid($request)));
        }

        return $this->success($q->get()->map(fn (SaccoOfficer $o) => $o->toArray() + ['label' => $o->label()])->all());
    }

    /** GET sacco/officers/{id} */
    public function officer(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $o = SaccoOfficer::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);

        return $o === null ? $this->notFound('That officer is not in this SACCO.') : $this->success($o->toArray() + ['label' => $o->label()]);
    }

    /** POST sacco/officers (sacco_officers) */
    public function appoint(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created((new OfficerService())->appoint($this->cid($request), $request->all(), (int) $request->user()->id)->toArray(), 'Officer appointed.'));
    }

    /** POST sacco/officers/{id}/end {reason?, ends_on?} (sacco_officers) */
    public function endTerm(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success((new OfficerService())->endTerm($this->cid($request), $id, (int) $request->user()->id, $request->input('reason'), $request->input('ends_on'))->toArray(), 'Term ended.'));
    }

    /** GET sacco/audit?action&model&from&to (sacco_audit_view): the SACCO's business actions and model changes. */
    public function audit(Request $request): JsonResponse
    {
        $q = DB::table('audit_logs')->where('company_id', $this->cid($request))
            ->where(fn ($w) => $w->where('action', 'like', 'sacco.%')->orWhere('model_type', 'like', 'App\\\\Models\\\\Sacco%'))
            ->when($request->query('action'), fn ($w, $a) => $w->where('action', 'like', $a.'%'))
            ->when($request->query('from'), fn ($w, $d) => $w->where('created_at', '>=', $d))
            ->when($request->query('to'), fn ($w, $d) => $w->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id');
        $p = $q->paginate($this->perPage($request));
        $items = collect($p->items())->map(fn ($r) => ['id' => (int) $r->id, 'action' => $r->action, 'model' => class_basename((string) $r->model_type), 'model_id' => (int) $r->model_id,
            'old' => json_decode((string) $r->old_values, true), 'new' => json_decode((string) $r->new_values, true), 'ip' => $r->ip_address, 'at' => $r->created_at])->all();

        return $this->page($p, $items);
    }

    /** GET sacco/ledger/verify (sacco_audit_view) */
    public function verify(Request $request): JsonResponse
    {
        return $this->success((new LedgerVerifier())->verify($this->cid($request)));
    }

    /** What this login may do here (for the app's buttons). */
    public static function abilities(\App\Models\User $u): array
    {
        $out = [];
        foreach (['sacco_view', 'sacco_members', 'sacco_kyc', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_accounts', 'sacco_reports', 'sacco_settings', 'sacco_officers', 'sacco_audit_view'] as $p) {
            $out[$p] = SaccoAccess::can($u, $p);
        }

        return $out;
    }
}
