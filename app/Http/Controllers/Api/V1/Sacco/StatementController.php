<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoStatementImport;
use App\Models\SaccoStatementLine;
use App\Services\Sacco\Channels\StatementImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statement imports and matching (04 §13.2 P3, P3.4): upload a bank, MTN, Airtel or Flutterwave statement for 1100,
 * 1150 or 1160, see the proposed matches, confirm, link, ignore, or ask for the missing entry as a manual journal.
 */
class StatementController extends SaccoController
{
    /** GET sacco/statement-imports?ledger_account_id&source */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoStatementImport::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('ledger_account_id'), fn ($q, $v) => $q->where('ledger_account_id', (int) $v))->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($i) => StatementImport::present($i))->all());
    }

    /** POST sacco/statement-imports (multipart: file, source: bank|mtn|airtel|flutterwave, ledger_account_id?) (sacco_accounts) */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:5120'], 'source' => ['required', 'in:bank,mtn,airtel,flutterwave'], 'ledger_account_id' => ['nullable', 'integer']]);

        return $this->attempt(function () use ($request, $d) {
            $file = $request->file('file');
            $import = app(StatementImport::class)->import($this->company($request), $this->user($request), (string) file_get_contents($file->getRealPath()),
                (string) $file->getClientOriginalName(), $d['source'], isset($d['ledger_account_id']) ? (int) $d['ledger_account_id'] : null);

            return $this->created(app(StatementImport::class)->summary($this->company($request), (int) $import->id), 'Imported; check the proposed matches.');
        });
    }

    /** GET sacco/statement-imports/{id}?status&page: the summary and its lines. */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id) {
            $summary = app(StatementImport::class)->summary($this->company($request), $id);
            $lines = SaccoStatementLine::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('import_id', $id)
                ->when($request->query('status'), fn ($q, $v) => $q->where('match_status', $v))->orderBy('line_no')->paginate($this->perPage($request));

            return $this->success($summary + ['lines' => collect($lines->items())->map(fn ($l) => StatementImport::presentLine($l))->all(),
                'lines_meta' => ['current_page' => $lines->currentPage(), 'last_page' => $lines->lastPage(), 'total' => $lines->total()]]);
        });
    }

    /** POST sacco/statement-imports/{id}/match: propose matches for the lines still unmatched (sacco_accounts). */
    public function match(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(app(StatementImport::class)->match($this->company($request), $id, $this->user($request))));
    }

    /** POST sacco/statement-imports/{id}/lines/{line}/{confirm|reject|link|ignore|unignore|journal} (sacco_accounts) */
    public function review(Request $request, int $id, int $line, string $action): JsonResponse
    {
        $d = $request->validate(['journal_line_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:255'], 'counter_account_id' => ['nullable', 'integer'],
            'memo' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->success(StatementImport::presentLine(app(StatementImport::class)->review($this->company($request), $id, $line, $action, $this->user($request), $d))));
    }
}
