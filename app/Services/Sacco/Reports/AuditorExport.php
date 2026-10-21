<?php

namespace App\Services\Sacco\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Reports\XlsxWriter;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use ZipArchive;

/**
 * The auditor's export (P4.11, 04 §8): one ZIP of XLSX workbooks for a period, everything an external or internal
 * auditor asks for first: the trial balance, the journal (every line), the statements, the registers (members, shares,
 * savings, loans, insider loans, dormant accounts), the approvals log and the audit trail. Built from the ledger and the
 * sub-ledgers like every report; no NIN ever appears. For the auditor's role (sacco_audit_view) or sacco_reports;
 * every export is itself audited. Downloaded through a 30-minute signed link.
 */
final class AuditorExport
{
    /** file name => report key */
    public const REPORTS = [
        '01-trial-balance' => 'trial_balance',
        '02-journal' => 'journal_listing',
        '03-statement-of-financial-position' => 'balance_sheet',
        '04-statement-of-comprehensive-income' => 'income_statement',
        '05-register-of-members' => 'member_register',
        '06-share-register' => 'share_register',
        '07-savings-balances' => 'savings_balances',
        '08-loan-portfolio' => 'portfolio',
        '09-insider-loans' => 'insider_loans',
        '10-dormant-accounts' => 'dormant_register',
        '11-approvals-log' => 'approvals_log',
    ];

    public static function allowed(?User $user): bool
    {
        return SaccoAccess::can($user, 'sacco_audit_view') || SaccoAccess::can($user, 'sacco_reports');
    }

    /** @return array{0: string, 1: string, 2: string} [bytes, content type, file name] */
    public function build(Company $company, string $from, string $to, User $viewer): array
    {
        if (! self::allowed($viewer)) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow the auditor\'s export.', ['permission' => 'sacco_audit_view']);
        }
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($from > $to) {
            throw BusinessRuleException::make('invalid_dates', 'The start date is after the end date.');
        }
        $cid = (int) $company->id;
        $path = tempnam(sys_get_temp_dir(), 'sacco-audit');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $reports = new SaccoReports();
        foreach (self::REPORTS as $name => $key) {
            if (! array_key_exists($key, SaccoReports::catalog())) {
                continue;
            }
            $report = $reports->build($company, $key, ['from' => $from, 'to' => $to], null);
            $zip->addFromString($name.'.xlsx', XlsxWriter::build($report['title'], SaccoReportFormat::table($report, true)));
        }
        $zip->addFromString('12-audit-trail.xlsx', XlsxWriter::build('Audit trail', $this->auditTrail($cid, $from, $to)));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        SaccoAudit::record('sacco.audit.exported', \App\Models\SaccoSetting::for($cid) ?? $company, $cid, ['from' => $from, 'to' => $to], (int) $viewer->id);

        return [$bytes, 'application/zip', 'sacco-audit-'.$from.'-'.$to.'.zip'];
    }

    /** @return array{url: string, expires_in: int} */
    public static function link(Company $company, User $user, string $from, string $to): array
    {
        if (! self::allowed($user)) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow the auditor\'s export.', ['permission' => 'sacco_audit_view']);
        }

        return ['url' => url(URL::temporarySignedRoute('sacco.auditor.download', now()->addMinutes(SaccoReportLinks::MINUTES),
            ['company_id' => $company->id, 'user_id' => $user->id, 'from' => Carbon::parse($from)->toDateString(), 'to' => Carbon::parse($to)->toDateString()], false)),
            'expires_in' => SaccoReportLinks::MINUTES * 60];
    }

    /** The audit trail of the SACCO's records and actions in the period, without any NIN. @return list<list<string|int>> */
    private function auditTrail(int $cid, string $from, string $to): array
    {
        $rows = [['When', 'Action', 'Record', 'Record id', 'By', 'IP', 'Fields']];
        $names = DB::table('admin_users')->where('company_id', $cid)->pluck('name', 'id');
        DB::table('audit_logs')->where('company_id', $cid)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where(fn ($q) => $q->where('model_type', 'like', 'App\\\\Models\\\\Sacco%')->orWhere('action', 'like', 'sacco.%'))
            ->orderBy('id')->select(['id', 'created_at', 'action', 'model_type', 'model_id', 'user_id', 'ip_address', 'new_values'])
            ->chunkById(1000, function ($chunk) use (&$rows, $names) {
                foreach ($chunk as $r) {
                    $values = json_decode((string) $r->new_values, true);
                    $values = is_array($values) ? $values : [];
                    $actor = $values['actor_id'] ?? $r->user_id;
                    $rows[] = [(string) $r->created_at, (string) $r->action, class_basename((string) $r->model_type), (int) $r->model_id,
                        $actor === null ? 'System' : (string) ($names[$actor] ?? '#'.$actor), (string) $r->ip_address,
                        implode(', ', array_diff(array_keys($values), ['actor_id', 'nin_encrypted', 'nin_hash']))];
                }
            });

        return $rows;
    }
}
