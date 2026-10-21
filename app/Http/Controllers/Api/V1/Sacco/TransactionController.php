<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoTransaction;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Rules\SaccoRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The money documents (04 §5, §13.2): deposits, fees, transfers, share purchases, entrance fees; withdrawals; reversals. */
class TransactionController extends SaccoController
{
    /** GET sacco/transactions?member_id&savings_account_id&kind&status&from&to */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoTransaction::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', (int) $v))
            ->when($request->query('savings_account_id'), fn ($q, $v) => $q->where('savings_account_id', (int) $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('value_date', '>=', $v))->when($request->query('to'), fn ($q, $v) => $q->where('value_date', '<=', $v))
            ->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($t) => Present::transaction($t))->all());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $t = SaccoTransaction::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        if ($t === null) {
            return $this->notFound('That receipt is not in this SACCO.');
        }
        $reversal = SaccoTransaction::withoutGlobalScopes()->where('reverses_id', $t->id)->first();

        return $this->success(Present::transaction($t) + ['reversed_by' => $reversal === null ? null : Present::transaction($reversal)]);
    }

    /**
     * GET sacco/transactions/{id}/receipt?format=json|text|pdf: the receipt as lines and texts (json: `text` for the
     * Bluetooth printer, `whatsapp` and `sms` for sharing), as plain text, or as a small PDF.
     */
    public function receipt(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $t = SaccoTransaction::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        if ($t === null) {
            return $this->notFound('That receipt is not in this SACCO.');
        }

        return match ((string) $request->query('format', 'json')) {
            'text' => response(\App\Services\Sacco\Messaging\SaccoReceipt::text($t), 200, ['Content-Type' => 'text/plain; charset=utf-8']),
            'pdf' => response(\App\Services\Sacco\Messaging\SaccoReceipt::pdf($t), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="receipt-'.$t->receipt_no.'.pdf"']),
            default => $this->success(\App\Services\Sacco\Messaging\SaccoReceipt::parts($t) + ['text' => \App\Services\Sacco\Messaging\SaccoReceipt::text($t)]
                + array_intersect_key(\App\Services\Sacco\Messaging\SaccoMessages::forReceipt($t) ?? [], array_flip(['whatsapp', 'sms']))),
        };
    }

    /** POST sacco/transactions {uuid, kind: deposit|fee|internal_transfer|share_purchase|entrance_fee, …} (sacco_teller) */
    public function store(Request $request): JsonResponse
    {
        if ($request->input('kind') === 'withdrawal') {
            return $this->error('Withdrawals go to POST sacco/withdrawals.', 422, ['code' => 'use_withdrawals']);
        }

        return $this->record($request, $request->all());
    }

    /** POST sacco/withdrawals {uuid, savings_account_id, amount_minor, channel: cash|bank} (sacco_withdraw; above the threshold → pending_approval, 202) */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate(['savings_account_id' => ['required', 'integer'], 'amount_minor' => SaccoRules::minor(true, 1)]);

        return $this->record($request, ['kind' => 'withdrawal'] + $request->all());
    }

    /** POST sacco/transactions/{id}/reverse {reason, uuid?} (sacco_teller): a reversal request for a second officer (202). */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200'], 'uuid' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->success(Present::approval(app(SaccoTransactions::class)->reverse($this->company($request), $id, $d['reason'], $this->user($request), $d['uuid'] ?? null),
            $this->user($request)), 'The reversal waits for a second officer.', 202));
    }

    /** @param array<string, mixed> $data */
    private function record(Request $request, array $data): JsonResponse
    {
        $request->validate(['amount_minor' => SaccoRules::minor(true, 1), 'uuid' => ['nullable', 'uuid']]);

        return $this->attempt(function () use ($request, $data) {
            $r = app(SaccoTransactions::class)->record($this->company($request), $data, $this->user($request));
            $tx = $r['transaction'];
            if ($r['replayed']) {
                return $this->success(Present::transaction($tx), 'Already recorded.');
            }

            return $tx->status === 'pending_approval'
                ? $this->success(Present::transaction($tx), 'The withdrawal waits for a second officer.', 202)
                : $this->created(Present::transaction($tx), 'Recorded.');
        });
    }
}
