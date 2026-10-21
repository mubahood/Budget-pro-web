<?php

namespace App\Http\Controllers\Api\V1\Sacco\YearEnd;

use App\Http\Controllers\Api\V1\Sacco\SaccoController;
use App\Models\SaccoReturnFiling;
use App\Models\StoredFile;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Reports\AuditorExport;
use App\Services\Sacco\Reports\Ratios;
use App\Services\Sacco\Reports\ReturnsCalendar;
use App\Services\Sacco\SaccoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The returns calendar, the ratios and the auditor's export (P4.2–P4.5, P4.11, 04 §13.2): GET sacco/returns lists what
 * the SACCO owes and when; generate builds a return from the books and keeps its files; mark-filed records it sent.
 * Reads need sacco_reports (or the auditor's sacco_audit_view); marking filed needs sacco_accounts.
 */
class ReturnsController extends SaccoController
{
    /** GET sacco/returns?status=due|generated|filed */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayReport($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success((new ReturnsCalendar())->list($this->cid($request), $request->query('status') ? (string) $request->query('status') : null),
            'Listed successfully.', 200, ['owed' => ReturnsCalendar::owed($this->cid($request))]));
    }

    /** GET sacco/returns/{id}: the return and its figures as last generated. */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->mayReport($request)) {
            return $this->refuseRead();
        }
        $f = SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);

        return $f === null ? $this->notFound('That return is not in this SACCO.')
            : $this->success(ReturnsCalendar::present($f, LoanBooks::today($this->cid($request))) + ['figures' => $f->figures]);
    }

    /** POST sacco/returns/{id}/generate */
    public function generate(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(ReturnsCalendar::present((new ReturnsCalendar())->generate($this->company($request), $id, $this->user($request)),
            LoanBooks::today($this->cid($request))), 'Return generated.'));
    }

    /** POST sacco/returns/{id}/mark-filed {filed_on, reference, authorised_by_id, countersigned_by_id} */
    public function markFiled(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['filed_on' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:120'], 'authorised_by_id' => ['nullable', 'integer'],
            'countersigned_by_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => $this->success(ReturnsCalendar::present((new ReturnsCalendar())->markFiled($this->company($request), $id, $d, $this->user($request)),
            LoanBooks::today($this->cid($request))), 'Return marked filed.'));
    }

    /** GET sacco/returns/{id}/files/{format}: the file kept when the return was generated. */
    public function file(Request $request, int $id, string $format): Response
    {
        if (! $this->mayReport($request)) {
            return $this->refuseRead();
        }
        $f = SaccoReturnFiling::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        $fileId = $f?->file_ids[$format] ?? null;
        $file = $fileId === null ? null : StoredFile::withoutGlobalScopes()->where('company_id', $this->cid($request))->find((int) $fileId);
        if ($file === null || ! Storage::disk('local')->exists($file->path)) {
            return $this->notFound('That return has no '.$format.' file yet: generate it first.');
        }

        $types = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8'];

        return response(Storage::disk('local')->get($file->path), 200, ['Content-Type' => $types[$format],
            'Content-Disposition' => 'attachment; filename="'.$f->form.'-'.$f->period_key.'.'.$format.'"', 'Cache-Control' => 'private, no-store']);
    }

    /** GET sacco/ratios?date&weeks: the prudential ratios, the weekly liquidity and the central bank threshold. */
    public function ratios(Request $request): JsonResponse
    {
        if (! $this->mayReport($request)) {
            return $this->refuseRead();
        }
        $date = (string) $request->query('date', LoanBooks::today($this->cid($request))->toDateString());

        return $this->attempt(fn () => $this->success((new Ratios())->compute($this->cid($request), $date)
            + ['weekly' => (new Ratios())->weekly($this->cid($request), $date, max(1, min(26, (int) $request->query('weeks', '8'))))]));
    }

    /** GET sacco/auditor-export?from&to: a 30-minute link to the ZIP of the auditor's workbooks. */
    public function auditorExport(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->attempt(fn () => $this->success(AuditorExport::link($this->company($request), $this->user($request), $d['from'], $d['to']), 'Download ready.'));
    }

    private function mayReport(Request $request): bool
    {
        return SaccoAccess::can($request->user(), 'sacco_reports') || SaccoAccess::can($request->user(), 'sacco_audit_view');
    }
}
