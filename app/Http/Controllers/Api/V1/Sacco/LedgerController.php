<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Services\Sacco\Ledger\OpeningBalances;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The books (04 §13.2): the chart, the journal, manual journals and entry reversals (always through a second officer),
 * months and their close and reopen, and the opening-balances import. Writes need sacco_accounts (ApiPermissionMap).
 */
class LedgerController extends SaccoController
{
    /** GET sacco/ledger/accounts: the chart with each account's balance. */
    public function accounts(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $balances = \Illuminate\Support\Facades\DB::table('sacco_account_balances')->where('company_id', $this->cid($request))->pluck('balance_minor', 'ledger_account_id');

        return $this->success(SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderBy('sort')->get()
            ->map(fn ($a) => $a->toArray() + ['balance_minor' => (int) ($balances[$a->id] ?? 0)])->all());
    }

    /** POST sacco/ledger/accounts {code, name, type, normal_side?, parent_id?, umra_line?}: a request for a second officer (202). */
    public function storeAccount(Request $request): JsonResponse
    {
        return $this->approval($request, 'ledger_account_create', $request->only(['code', 'name', 'type', 'normal_side', 'parent_id', 'umra_line']));
    }

    /** PUT sacco/ledger/accounts/{id} {name?, is_active?, umra_line?} */
    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean'], 'umra_line' => ['sometimes', 'nullable', 'string', 'max:16']]);
        $a = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        if ($a === null) {
            return $this->notFound('That account is not in this SACCO\'s chart.');
        }

        return $this->attempt(function () use ($a, $d) {
            $a->fill($d)->save();

            return $this->success($a->fresh()->toArray(), 'Account saved.');
        });
    }

    /** GET sacco/ledger/journal?from&to&kind&account_id */
    public function journal(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('from'), fn ($q, $v) => $q->where('entry_date', '>=', $v))->when($request->query('to'), fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('account_id'), fn ($q, $v) => $q->whereIn('id', \Illuminate\Support\Facades\DB::table('sacco_journal_lines')->where('ledger_account_id', (int) $v)->select('journal_entry_id')))
            ->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($e) => Present::entry($e))->all());
    }

    public function entry(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $e = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);

        return $e === null ? $this->notFound('That entry is not in this SACCO\'s books.') : $this->success(Present::entry($e, true));
    }

    /** POST sacco/ledger/journal {memo, value_date?, lines: [{account_id, debit_minor | credit_minor, sub_type?, sub_id?, member_id?, memo?}], uuid?} → approval (202) */
    public function storeJournal(Request $request): JsonResponse
    {
        return $this->approval($request, 'manual_journal', $request->only(['memo', 'value_date', 'lines']));
    }

    /** POST sacco/ledger/journal/{id}/reverse {reason, uuid?} → approval (202); a member's receipt is reversed from its receipt. */
    public function reverseEntry(Request $request, int $id): JsonResponse
    {
        return $this->approval($request, 'reversal', ['entry_id' => $id, 'reason' => (string) $request->input('reason', '')]);
    }

    /** GET sacco/periods */
    public function periods(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->success(SaccoPeriod::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderByDesc('starts_on')->get()->toArray());
    }

    /** POST sacco/periods/{id}/close → approval (202) */
    public function close(Request $request, int $id): JsonResponse
    {
        return $this->approval($request, 'period_close', ['period_id' => $id]);
    }

    /** POST sacco/periods/{id}/reopen {reason} → approval (202; the checker also holds the chair's or the auditor's office) */
    public function reopen(Request $request, int $id): JsonResponse
    {
        return $this->approval($request, 'period_reopen', ['period_id' => $id, 'reason' => (string) $request->input('reason', '')]);
    }

    /**
     * POST sacco/opening-balances/import {kind: members|ledger, csv, as_of?, dry_run=true, uuid?}: a dry run shows each
     * row and the totals; a real run is an `opening_balances` request for a second officer (202).
     */
    public function importOpening(Request $request): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', 'in:members,ledger'], 'csv' => ['required', 'string', 'max:5000000'], 'as_of' => ['nullable', 'date'],
            'dry_run' => ['nullable', 'boolean'], 'uuid' => ['nullable', 'uuid']]);
        $svc = new OpeningBalances();

        return $this->attempt(fn () => ($d['dry_run'] ?? true)
            ? $this->success($svc->preview($this->company($request), $d['kind'], $d['csv'], $d['as_of'] ?? null) + ['status' => OpeningBalances::status($this->cid($request))], 'Checked: nothing posted.')
            : $this->success(Present::approval($svc->request($this->company($request), $d['kind'], $d['csv'], $this->user($request), $d['as_of'] ?? null, $d['uuid'] ?? null), $this->user($request)),
                'The import waits for a second officer.', 202));
    }

    /** GET sacco/opening-balances: how far the opening is (3900's balance, each file's part). */
    public function openingStatus(Request $request): JsonResponse
    {
        return $this->mayRead($request) ? $this->success(OpeningBalances::status($this->cid($request))) : $this->refuseRead();
    }

    /** @param array<string, mixed> $payload */
    private function approval(Request $request, string $action, array $payload): JsonResponse
    {
        if (! SaccoAccess::can($request->user(), 'sacco_accounts') && $action !== 'reversal') {
            return $this->error('Your role does not allow this.', 403, ['code' => 'forbidden', 'permission' => 'sacco_accounts']);
        }

        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoApprovals::class)->request($this->company($request), $action, $payload, $this->user($request), $request->input('uuid')),
            $this->user($request)), 'This waits for a second officer.', 202));
    }
}
