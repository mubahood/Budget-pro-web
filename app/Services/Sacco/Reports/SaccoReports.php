<?php

namespace App\Services\Sacco\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoShareAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\AccountStatement;
use App\Services\Sacco\Ledger\TrialBalance;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The SACCO's reports (docs/akiba/04-architecture.md §12, P1.14), computed from the journal and the sub-ledgers,
 * never from stored totals, in the ReportService registry style. Each report is a table (columns, rows, totals,
 * optional sections) with money as `*_minor` integers; SaccoReportFormat renders it as JSON, PDF (the SACCO's name,
 * CS No. and licence; "Regulated by …" only when licensed), XLSX or CSV. No report carries a NIN.
 *
 * Later phases add their reports with register() (P2: portfolio, PAR, arrears…).
 */
final class SaccoReports
{
    /** key => [title, permission, method on this class] */
    public const REPORTS = [
        'member_statement' => ['Member statement', 'sacco_reports', 'memberStatement'],
        'trial_balance' => ['Trial balance', 'sacco_reports', 'trialBalance'],
        'cash_book' => ['Cash book', 'sacco_reports', 'cashBook'],
        'day_book' => ['Day book', 'sacco_reports', 'dayBook'],
        'member_register' => ['Register of members', 'sacco_reports', 'memberRegister'],
        'share_register' => ['Share register', 'sacco_reports', 'shareRegister'],
        'savings_balances' => ['Savings balances', 'sacco_reports', 'savingsBalances'],
        'approvals_log' => ['Approvals log', 'sacco_reports', 'approvalsLog'],
    ];

    public const FORMATS = ['json', 'pdf', 'xlsx', 'csv'];

    /** System keys of the accounts the cash book follows. */
    public const CASH_KEYS = ['cash.office', 'cash.box', 'cash.petty', 'bank.main', 'momo.float', 'momo.clearing'];

    /** @var array<string, array{0: string, 1: string, 2: callable(int, array<string, mixed>): array<string, mixed>}> */
    private static array $extra = [];

    /** @param  callable(int, array<string, mixed>): array<string, mixed>  $build  (company id, params) => columns, rows, totals?, sections?, from?, to? */
    public static function register(string $key, string $title, string $permission, callable $build): void
    {
        self::$extra[$key] = [$title, $permission, $build];
    }

    /** @return array<string, array{title: string, permission: string}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::REPORTS as $k => [$t, $p]) {
            $out[$k] = ['title' => $t, 'permission' => $p];
        }
        foreach (self::$extra as $k => [$t, $p]) {
            $out[$k] = ['title' => $t, 'permission' => $p];
        }

        return $out;
    }

    public static function permission(string $key): string
    {
        return self::catalog()[$key]['permission'] ?? throw BusinessRuleException::make('unknown_report', "There is no report called \"{$key}\".");
    }

    /**
     * Build a report for a viewer who may read it (sacco_reports, or the auditor's sacco_audit_view).
     *
     * @param  array<string, mixed>  $params  from, to, date, member_id…
     * @return array<string, mixed>
     */
    public function build(Company $company, string $key, array $params, ?User $viewer): array
    {
        $permission = self::permission($key);
        if ($viewer !== null && ! SaccoAccess::can($viewer, $permission) && ! SaccoAccess::can($viewer, 'sacco_audit_view')) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this report.', ['permission' => $permission]);
        }
        $cid = (int) $company->id;
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $today = LocalDate::today($cid);
        $params['to'] = Carbon::parse($params['to'] ?? $params['date'] ?? $today->toDateString())->toDateString();
        $params['from'] = Carbon::parse($params['from'] ?? $params['date'] ?? Carbon::parse($params['to'])->startOfMonth()->toDateString())->toDateString();
        if ($params['from'] > $params['to']) {
            throw BusinessRuleException::make('invalid_dates', 'The start date is after the end date.');
        }
        $body = isset(self::REPORTS[$key]) ? $this->{self::REPORTS[$key][2]}($cid, $params, $settings) : (self::$extra[$key][2])($cid, $params);
        if ($key === 'member_register' && $viewer !== null) {
            SaccoAudit::record('sacco.member.exported', $settings, $cid, ['report' => $key], (int) $viewer->id);
        }

        return $body + ['name' => $key, 'title' => self::catalog()[$key]['title'], 'from' => $params['from'], 'to' => $params['to'], 'currency' => $settings->currency,
            'sacco' => self::header($company, $settings), 'generated_at' => now()->toIso8601String(), 'totals' => [], 'sections' => [], 'meta' => []];
    }

    /** @return array{legal_name: string, short_name: string, registration_no: ?string, licence_no: ?string, regulated_line: ?string} */
    public static function header(Company $company, SaccoSetting $s): array
    {
        return ['legal_name' => (string) ($s->legal_name ?: $company->name), 'short_name' => (string) ($s->short_name ?: $company->name),
            'registration_no' => $s->registration_no, 'licence_no' => $s->licence_no, 'regulated_line' => $s->regulatedLine()];
    }

    // ---------------------------------------------------------------- the P1 reports

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function memberStatement(int $cid, array $p, SaccoSetting $s): array
    {
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($p['member_id'] ?? 0))
            ?? throw BusinessRuleException::make('member_not_found', 'Choose a member of this SACCO.');
        $cols = [self::c('date', 'Date', 'date'), self::c('value_date', 'Value date', 'date'), self::c('receipt_no', 'Receipt'), self::c('narration', 'Narration'),
            self::c('debit_minor', 'Out', 'money'), self::c('credit_minor', 'In', 'money'), self::c('balance_minor', 'Balance', 'money')];
        $sections = [];
        $statement = new AccountStatement();
        $accounts = [];
        foreach (SaccoSavingsAccount::withoutGlobalScopes()->with('product:id,name')->where('company_id', $cid)->where('member_id', $member->id)->orderBy('id')->get() as $a) {
            $accounts[] = ['savings', (int) $a->id, $a->account_no.' · '.($a->product->name ?? 'Savings')];
        }
        if ($share = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->first()) {
            $accounts[] = ['shares', (int) $share->id, $share->account_no.' · Shares ('.$share->shares_count.')'];
        }
        if (isset($p['account_type'], $p['account_id'])) {
            $accounts = array_values(array_filter($accounts, fn ($a) => $a[0] === $p['account_type'] && $a[1] === (int) $p['account_id']));
        }
        foreach ($accounts as [$type, $id, $label]) {
            $st = $statement->member($cid, $type, $id, $p['from'], $p['to']);
            $rows = array_merge([['date' => $p['from'], 'narration' => 'Opening balance', 'balance_minor' => $st['opening_minor']]], $st['lines']);
            $sections[] = ['title' => $label, 'columns' => $cols, 'rows' => $rows, 'totals' => ['debit_minor' => $st['debits_minor'], 'credit_minor' => $st['credits_minor'],
                'balance_minor' => $st['closing_minor']], 'type' => $type, 'account_id' => $id, 'opening_minor' => $st['opening_minor'], 'closing_minor' => $st['closing_minor']];
        }

        return ['columns' => [self::c('member_no', 'Member no.'), self::c('name', 'Name'), self::c('phone', 'Phone'), self::c('status', 'Status')],
            'rows' => [['member_no' => $member->member_no, 'name' => $member->full_name, 'phone' => $member->phone_e164, 'status' => $member->status]],
            'sections' => $sections, 'member' => ['id' => (int) $member->id, 'member_no' => $member->member_no, 'name' => $member->full_name]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function trialBalance(int $cid, array $p, SaccoSetting $s): array
    {
        $tb = (new TrialBalance())->between($cid, $p['from'], $p['to']);

        return ['columns' => [self::c('code', 'Code'), self::c('name', 'Account'), self::c('opening_minor', 'Opening', 'money'), self::c('debits_minor', 'Debits', 'money'),
            self::c('credits_minor', 'Credits', 'money'), self::c('debit_balance_minor', 'Debit balance', 'money'), self::c('credit_balance_minor', 'Credit balance', 'money')],
            'rows' => $tb['rows'], 'totals' => array_intersect_key($tb['totals'], array_flip(['debits_minor', 'credits_minor', 'debit_balance_minor', 'credit_balance_minor'])),
            'meta' => ['balanced' => $tb['balanced'], 'note' => $tb['balanced'] ? 'Debits equal credits: the books balance.' : 'The books do not balance: tell the platform.']];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function cashBook(int $cid, array $p, SaccoSetting $s): array
    {
        $accounts = DB::table('sacco_ledger_accounts')->where('company_id', $cid)->whereIn('system_key', self::CASH_KEYS)->orderBy('sort')->get(['id', 'code', 'name']);
        $statement = new AccountStatement();
        $sections = [];
        $officers = [];
        foreach ($accounts as $a) {
            $st = $statement->ledger($cid, (int) $a->id, $p['from'], $p['to']);
            if ($st['lines'] === [] && $st['opening_minor'] === 0) {
                continue;
            }
            $ids = array_column($st['lines'], 'entry_id');
            $officers += DB::table('sacco_journal_entries as e')->leftJoin('admin_users as u', 'u.id', '=', 'e.posted_by_id')->whereIn('e.id', $ids)->pluck('u.name', 'e.id')->all();
            $rows = [['date' => $p['from'], 'narration' => 'Opening balance', 'balance_minor' => $st['opening_minor']]];
            foreach ($st['lines'] as $l) {
                $rows[] = $l + ['officer' => $officers[$l['entry_id']] ?? 'System'];
            }
            $sections[] = ['title' => "{$a->code} {$a->name}", 'columns' => [self::c('date', 'Date', 'date'), self::c('entry_no', 'Entry'), self::c('receipt_no', 'Receipt'),
                self::c('narration', 'Narration'), self::c('officer', 'Officer'), self::c('debit_minor', 'In', 'money'), self::c('credit_minor', 'Out', 'money'), self::c('balance_minor', 'Balance', 'money')],
                'rows' => $rows, 'totals' => ['debit_minor' => $st['debits_minor'], 'credit_minor' => $st['credits_minor'], 'balance_minor' => $st['closing_minor']]];
        }
        $summary = array_map(fn ($sec) => ['account' => $sec['title'], 'in_minor' => $sec['totals']['debit_minor'], 'out_minor' => $sec['totals']['credit_minor'], 'closing_minor' => $sec['totals']['balance_minor']], $sections);

        return ['columns' => [self::c('account', 'Account'), self::c('in_minor', 'In', 'money'), self::c('out_minor', 'Out', 'money'), self::c('closing_minor', 'Closing', 'money')],
            'rows' => $summary, 'totals' => ['in_minor' => array_sum(array_column($summary, 'in_minor')), 'out_minor' => array_sum(array_column($summary, 'out_minor')),
                'closing_minor' => array_sum(array_column($summary, 'closing_minor'))], 'sections' => $sections];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function dayBook(int $cid, array $p, SaccoSetting $s): array
    {
        $rows = DB::table('sacco_journal_lines as l')->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('sacco_ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->leftJoin('admin_users as u', 'u.id', '=', 'e.posted_by_id')
            ->leftJoin('sacco_members as m', 'm.id', '=', 'l.member_id')
            ->where('l.company_id', $cid)->whereBetween('l.entry_date', [$p['from'], $p['to']])->orderBy('e.id')->orderBy('l.line_no')
            ->get(['e.entry_date', 'e.entry_no', 'e.kind', 'e.memo', 'a.code', 'a.name as account', 'm.member_no', 'l.debit_minor', 'l.credit_minor', 'u.name as officer'])
            ->map(fn ($r) => ['date' => substr((string) $r->entry_date, 0, 10), 'entry_no' => $r->entry_no, 'kind' => $r->kind, 'memo' => $r->memo, 'account' => "{$r->code} {$r->account}",
                'member_no' => $r->member_no, 'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'officer' => $r->officer ?? 'System'])->all();

        return ['columns' => [self::c('date', 'Date', 'date'), self::c('entry_no', 'Entry'), self::c('kind', 'Kind'), self::c('memo', 'Memo'), self::c('account', 'Account'),
            self::c('member_no', 'Member'), self::c('debit_minor', 'Debit', 'money'), self::c('credit_minor', 'Credit', 'money'), self::c('officer', 'Officer')],
            'rows' => $rows, 'totals' => ['debit_minor' => array_sum(array_column($rows, 'debit_minor')), 'credit_minor' => array_sum(array_column($rows, 'credit_minor'))]];
    }

    /** Regs 1992 reg. 12: number, name, address, occupation, date admitted, shareholding, date ceased, nominees. @param array<string, mixed> $p @return array<string, mixed> */
    private function memberRegister(int $cid, array $p, SaccoSetting $s): array
    {
        $shares = DB::table('sacco_share_accounts')->where('company_id', $cid)->pluck('shares_count', 'member_id');
        $nominees = DB::table('sacco_member_relations')->where('company_id', $cid)->where('role', 'nominee')->whereNull('ended_on')->get(['member_id', 'name', 'share_bp'])->groupBy('member_id');
        $rows = [];
        foreach (SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')->orderBy('member_no')->get() as $m) {
            $ceased = in_array($m->status, ['exited', 'deceased', 'rejected'], true) ? $m->status_changed_on?->toDateString() : null;
            $rows[] = ['member_no' => $m->member_no, 'name' => $m->full_name, 'gender' => $m->gender, 'address' => implode(', ', array_filter([$m->address, $m->village, $m->parish, $m->subcounty, $m->district])),
                'occupation' => $m->occupation, 'joined_on' => $m->joined_on->toDateString(), 'shares' => (int) ($shares[$m->id] ?? 0), 'status' => $m->status, 'ceased_on' => $ceased,
                'nominees' => implode('; ', ($nominees[$m->id] ?? collect())->map(fn ($n) => $n->name.' ('.rtrim(rtrim(number_format($n->share_bp / 100, 2), '0'), '.').' %)')->all())];
        }

        return ['columns' => [self::c('member_no', 'No.'), self::c('name', 'Name'), self::c('gender', 'Gender'), self::c('address', 'Address'), self::c('occupation', 'Occupation'),
            self::c('joined_on', 'Admitted', 'date'), self::c('shares', 'Shares', 'number'), self::c('status', 'Status'), self::c('ceased_on', 'Ceased', 'date'), self::c('nominees', 'Nominees')],
            'rows' => $rows, 'totals' => ['shares' => array_sum(array_column($rows, 'shares'))]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function shareRegister(int $cid, array $p, SaccoSetting $s): array
    {
        $list = DB::table('sacco_share_accounts as s')->join('sacco_members as m', 'm.id', '=', 's.member_id')->where('s.company_id', $cid)->where('s.shares_count', '>', 0)
            ->orderBy('m.member_no')->get(['s.account_no', 'm.member_no', 'm.first_name', 'm.other_names', 'm.last_name', 's.shares_count', 's.balance_minor']);
        $total = (int) $list->sum('shares_count');
        $cap = (int) $s->max_holding_bp;
        $rows = $list->map(function ($r) use ($total, $cap) {
            $bp = $total > 0 ? intdiv((int) $r->shares_count * 10000, $total) : 0;

            return ['account_no' => $r->account_no, 'member_no' => $r->member_no, 'name' => trim(implode(' ', array_filter([$r->first_name, $r->other_names, $r->last_name]))),
                'shares' => (int) $r->shares_count, 'value_minor' => (int) $r->balance_minor, 'holding_bp' => $bp, 'over_cap' => $bp > $cap ? 'Above the limit' : ''];
        })->all();

        return ['columns' => [self::c('account_no', 'Account'), self::c('member_no', 'Member'), self::c('name', 'Name'), self::c('shares', 'Shares', 'number'),
            self::c('value_minor', 'Value', 'money'), self::c('holding_bp', '% of capital', 'bp'), self::c('over_cap', 'Flag')],
            'rows' => $rows, 'totals' => ['shares' => $total, 'value_minor' => (int) $list->sum('balance_minor')], 'meta' => ['max_holding_bp' => $cap]];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function savingsBalances(int $cid, array $p, SaccoSetting $s): array
    {
        $sections = [];
        $summary = [];
        $accounts = SaccoSavingsAccount::withoutGlobalScopes()->with(['product:id,code,name', 'member:id,member_no,first_name,other_names,last_name'])->where('company_id', $cid)
            ->where('status', '!=', 'closed')->orderBy('product_id')->orderBy('account_no')->get()->groupBy('product_id');
        foreach ($accounts as $list) {
            $product = $list->first()->product;
            $rows = $list->map(fn (SaccoSavingsAccount $a) => ['account_no' => $a->account_no, 'member_no' => $a->member?->member_no, 'name' => $a->member?->full_name,
                'status' => $a->status, 'balance_minor' => (int) $a->balance_minor, 'held_minor' => (int) $a->held_minor,
                'available_minor' => SavingsAccounts::available($cid, (int) $a->id)])->values()->all();
            $totals = ['balance_minor' => array_sum(array_column($rows, 'balance_minor')), 'held_minor' => array_sum(array_column($rows, 'held_minor')),
                'available_minor' => array_sum(array_column($rows, 'available_minor'))];
            $sections[] = ['title' => ($product->code ?? '').' '.($product->name ?? ''), 'columns' => [self::c('account_no', 'Account'), self::c('member_no', 'Member'), self::c('name', 'Name'),
                self::c('status', 'Status'), self::c('balance_minor', 'Balance', 'money'), self::c('held_minor', 'Held', 'money'), self::c('available_minor', 'Available', 'money')],
                'rows' => $rows, 'totals' => $totals];
            $summary[] = ['product' => ($product->code ?? '').' '.($product->name ?? ''), 'accounts' => count($rows)] + $totals;
        }

        return ['columns' => [self::c('product', 'Product'), self::c('accounts', 'Accounts', 'number'), self::c('balance_minor', 'Balance', 'money'), self::c('held_minor', 'Held', 'money'),
            self::c('available_minor', 'Available', 'money')], 'rows' => $summary, 'totals' => ['accounts' => array_sum(array_column($summary, 'accounts')),
                'balance_minor' => array_sum(array_column($summary, 'balance_minor')), 'held_minor' => array_sum(array_column($summary, 'held_minor')),
                'available_minor' => array_sum(array_column($summary, 'available_minor'))], 'sections' => $sections];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function approvalsLog(int $cid, array $p, SaccoSetting $s): array
    {
        $names = fn (array $ids) => DB::table('admin_users')->whereIn('id', array_filter($ids))->pluck('name', 'id')->all();
        $list = SaccoApproval::withoutGlobalScopes()->with('votes')->where('company_id', $cid)
            ->whereBetween('requested_on', [$p['from'], $p['to']])->orderBy('id')->get();
        $people = $names(array_merge($list->pluck('requested_by_id')->all(), $list->pluck('votes')->flatten()->pluck('user_id')->all()));
        $rows = $list->map(fn (SaccoApproval $a) => ['date' => $a->requested_on->toDateString(), 'action' => $a->action, 'amount_minor' => $a->amount_minor,
            'maker' => $a->requested_by_id === null ? 'System' : ($people[$a->requested_by_id] ?? '#'.$a->requested_by_id),
            'checkers' => $a->votes->map(fn ($v) => ($people[$v->user_id] ?? '#'.$v->user_id).' ('.$v->decision.')')->implode(', '),
            'status' => $a->status, 'self_approved' => $a->self_approved ? 'Yes' : ''])->all();

        return ['columns' => [self::c('date', 'Date', 'date'), self::c('action', 'Request'), self::c('amount_minor', 'Amount', 'money'), self::c('maker', 'Maker'),
            self::c('checkers', 'Checkers'), self::c('status', 'Status'), self::c('self_approved', 'Self-approved')], 'rows' => $rows,
            'meta' => ['self_approvals' => count(array_filter($rows, fn ($r) => $r['self_approved'] !== ''))]];
    }

    /** @return array{key: string, label: string, type: string} */
    private static function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
