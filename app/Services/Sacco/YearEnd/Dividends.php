<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoDividendLine;
use App\Models\SaccoDividendRun;
use App\Models\SaccoMember;
use App\Models\SaccoPayout;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\MemberFunds;
use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\Reports\Figures;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The dividend on shares (P4.8, 02 §5, AK-18): the pool the AGM declared in the appropriation (2420 Dividends payable)
 * shared over the members exactly (largest remainder on each member's basis, so Σ lines = the pool) and paid:
 *
 * - into the member's savings (Dr 2420 / Cr the account's 21x0), the default;
 * - in cash (Dr 2420 / Cr 1010), for a member with no open savings account or when cash is chosen;
 * - by mobile money (Dr 2420 / Cr 2160 Payouts in transit, then 2160 → 1150 when the provider confirms; a failed payout
 *   is reversed back to 2420 and can be paid to savings or in cash instead).
 *
 * The basis is each member's shares at the year's end (`year_end_shares`) or the average of their twelve month-end
 * holdings (`weighted_monthly`); the pool is the rate on the total, rounded half-up once.
 *
 * The payment needs the Registrar's written consent (Coop s.46): for a licensed SACCO the consent document and its
 * date are required, and the rate cap and core capital after the dividend are checked again; for any other SACCO each
 * is a warning on the run (SaccoRules::strict, AK-69). Two officers in the chair's, vice chair's or treasurer's office
 * approve (`dividend_run`); the savings and cash lines post in one entry, key `div:{run_id}`.
 */
final class Dividends
{
    /**
     * The pool a rate gives on a year's shares, and each member's basis.
     *
     * @return array{rate_bp: int, basis: string, basis_total_minor: int, pool_minor: int, members: array<int, array{member_id: int, share_account_id: int, basis_minor: int}>}
     */
    public static function pool(int $companyId, int $year, int $rateBp, string $basis): array
    {
        [$from, $to] = FiscalCalendar::bounds($companyId, $year);
        $months = $basis === 'weighted_monthly' ? 12 : 1;
        $ends = [];
        if ($basis === 'weighted_monthly') {
            for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
                $ends[] = $m->copy()->endOfMonth()->toDateString();
            }
        } else {
            $ends[] = $to->toDateString();
        }
        $members = [];
        foreach ($ends as $end) {
            foreach (DB::table('sacco_journal_lines as l')->join('sacco_share_accounts as s', 's.id', '=', 'l.sub_id')->where('l.company_id', $companyId)->where('l.sub_type', 'shares')
                ->where('l.entry_date', '<=', $end)->groupBy('l.sub_id', 's.member_id')->selectRaw('l.sub_id, s.member_id, SUM(l.credit_minor) - SUM(l.debit_minor) AS b')->get() as $r) {
                if ((int) $r->b <= 0) {
                    continue;
                }
                $members[(int) $r->member_id] ??= ['member_id' => (int) $r->member_id, 'share_account_id' => (int) $r->sub_id, 'basis_minor' => 0];
                $members[(int) $r->member_id]['basis_minor'] += (int) $r->b;
            }
        }
        ksort($members);
        $total = array_sum(array_column($members, 'basis_minor'));
        $pool = (int) bcadd(bcdiv(bcmul((string) $total, (string) $rateBp, 0), (string) (10000 * $months), 6), '0.5', 0);

        return ['rate_bp' => $rateBp, 'basis' => $basis, 'basis_total_minor' => $total, 'pool_minor' => $rateBp > 0 ? $pool : 0, 'members' => $members];
    }

    /**
     * Each member's share of the declared pool and how it would be paid.
     *
     * @param  array<string, mixed>  $data  pay_to (savings | cash | momo), registrar_consent_file_id, registrar_consent_on, registrar_consent_ref, on
     * @return array<string, mixed>
     */
    public function preview(int $companyId, int $year, array $data): array
    {
        $approp = Appropriation::posted($companyId, $year) ?? throw BusinessRuleException::make('no_dividend_declared', 'The AGM\'s appropriation of '.$year.' is not posted yet.');
        if ((int) $approp->dividend_pool_minor <= 0) {
            throw BusinessRuleException::make('no_dividend_declared', 'The appropriation of '.$year.' declared no dividend.');
        }
        if (SaccoDividendRun::withoutGlobalScopes()->where('company_id', $companyId)->where('appropriation_id', $approp->id)->where('status', 'paid')->exists()) {
            throw BusinessRuleException::make('dividend_paid', 'The '.$year.' dividend is already paid.');
        }
        $payTo = (string) ($data['pay_to'] ?? 'savings');
        if (! in_array($payTo, SaccoDividendRun::PAY_TO, true)) {
            throw BusinessRuleException::make('invalid_dividend', 'Pay the dividend into savings, in cash or by mobile money.');
        }
        if ($payTo === 'momo' && ! PayoutService::enabled()) {
            PayoutService::gateway(); // payouts_off
        }
        $pool = self::pool($companyId, $year, (int) $approp->dividend_rate_bp, (string) $approp->dividend_basis);
        if ($pool['pool_minor'] !== (int) $approp->dividend_pool_minor || $pool['members'] === []) {
            throw BusinessRuleException::make('figures_changed', 'The shares behind the declared dividend changed: the appropriation and the books disagree.');
        }
        $weights = array_map(fn ($m) => $m['basis_minor'], $pool['members']);
        $parts = LoanBooks::money($companyId, $pool['pool_minor'])->allocate($weights);
        $lines = [];
        foreach ($pool['members'] as $mid => $m) {
            $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find($mid);
            $savings = in_array($payTo, ['savings', 'momo'], true) && $member !== null && ! in_array($member->status, ['exited', 'deceased'], true)
                ? MemberFunds::payoutAccount($companyId, $mid) : null;
            $to = match (true) {
                $payTo === 'momo' && $member !== null && filled($member->phone_e164) && ! in_array($member->status, ['exited', 'deceased'], true) => 'momo',
                $payTo !== 'cash' && $savings !== null => 'savings',
                default => 'cash',
            };
            $lines[] = ['member_id' => $mid, 'member_no' => (string) $member?->member_no, 'share_account_id' => $m['share_account_id'], 'basis_minor' => $m['basis_minor'],
                'amount_minor' => $parts[$mid]->minor, 'paid_to' => $to, 'savings_account_id' => $to === 'savings' ? (int) $savings->id : null,
                'phone_e164' => $to === 'momo' ? (string) $member->phone_e164 : null];
        }
        $issues = [];
        $consentFile = isset($data['registrar_consent_file_id']) && $data['registrar_consent_file_id'] !== '' ? (int) $data['registrar_consent_file_id'] : null;
        if ($consentFile !== null && ! DB::table('files')->where('company_id', $companyId)->where('id', $consentFile)->exists()) {
            throw BusinessRuleException::make('file_not_found', 'That file is not in this SACCO.');
        }
        $consentOn = isset($data['registrar_consent_on']) && $data['registrar_consent_on'] !== '' ? Carbon::parse((string) $data['registrar_consent_on'])->toDateString() : null;
        if ($consentFile === null || $consentOn === null) {
            $issues[] = ['registrar_consent_required', 'Upload the Registrar\'s written consent to the dividend, with its date, before it is paid.'];
        }
        if ((int) $approp->dividend_rate_bp > Appropriation::DIVIDEND_CAP_BP) {
            $issues[] = ['dividend_above_cap', 'A dividend may be at most 10 % of the shares a year; this one is '.Appropriation::pct((int) $approp->dividend_rate_bp).'.'];
        }
        $on = $this->day($companyId, $year, $data['on'] ?? null);
        $position = Figures::position($companyId, $on);
        $ratio = Figures::bp(Figures::capital($position)['core'], $position['totals']['assets']); // the dividend is already a liability (2420)
        if ($ratio === null || $ratio < Appropriation::CORE_CAPITAL_MIN_BP) {
            $issues[] = ['core_capital_after_dividend', 'With this dividend core capital is '.($ratio === null ? 'nothing' : Appropriation::pct($ratio)).' of total assets; it should stay at least 10 %.'];
        }
        $strict = SaccoRules::strict($companyId);
        $warnings = $strict ? [] : array_map(fn ($i) => ['code' => $i[0], 'message' => $i[1]], $issues);
        $refusals = $strict ? array_map(fn ($i) => ['code' => $i[0], 'message' => $i[1]], $issues) : [];

        return ['year' => $year, 'appropriation_id' => (int) $approp->id, 'fiscal_year_id' => (int) $approp->fiscal_year_id, 'rate_bp' => (int) $approp->dividend_rate_bp,
            'basis' => (string) $approp->dividend_basis, 'pool_minor' => $pool['pool_minor'], 'basis_total_minor' => $pool['basis_total_minor'], 'pay_to' => $payTo,
            'on' => $on, 'registrar_consent_file_id' => $consentFile, 'registrar_consent_on' => $consentOn,
            'registrar_consent_ref' => isset($data['registrar_consent_ref']) ? mb_substr((string) $data['registrar_consent_ref'], 0, 80) : null,
            'core_capital_ratio_bp_after' => $ratio, 'lines' => $lines, 'strict' => $strict, 'warnings' => $warnings, 'refusals' => $refusals];
    }

    /** Ask for the dividend to be paid. @param array<string, mixed> $data as preview() */
    public function request(Company $company, int $year, array $data, User $maker, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_accounts');
        $cid = (int) $company->id;
        $p = $this->preview($cid, $year, $data);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message'], ['refusals' => $p['refusals']]);
        }
        if (DB::table('sacco_dividend_runs as r')->join('sacco_approvals as ap', 'ap.id', '=', 'r.approval_id')->where('r.company_id', $cid)->where('r.appropriation_id', $p['appropriation_id'])
            ->where('r.status', 'requested')->whereIn('ap.status', ['pending', 'approved'])->where('ap.expires_at', '>=', now())->exists()) {
            throw BusinessRuleException::make('dividend_pending', 'The '.$year.' dividend already waits for approval.');
        }

        return DB::transaction(function () use ($company, $cid, $year, $p, $maker, $uuid) {
            $run = SaccoDividendRun::create(['company_id' => $cid, 'fiscal_year_id' => $p['fiscal_year_id'], 'appropriation_id' => $p['appropriation_id'], 'rate_bp' => $p['rate_bp'],
                'basis' => $p['basis'], 'pool_minor' => $p['pool_minor'], 'basis_total_minor' => $p['basis_total_minor'], 'members_count' => count($p['lines']), 'pay_to' => $p['pay_to'],
                'core_capital_ratio_bp_after' => $p['core_capital_ratio_bp_after'], 'registrar_consent_file_id' => $p['registrar_consent_file_id'],
                'registrar_consent_on' => $p['registrar_consent_on'], 'registrar_consent_ref' => $p['registrar_consent_ref'], 'status' => 'requested', 'warnings' => $p['warnings'],
                'created_by_id' => $maker->id]);
            foreach ($p['lines'] as $l) {
                SaccoDividendLine::create(['company_id' => $cid, 'run_id' => $run->id, 'member_id' => $l['member_id'], 'share_account_id' => $l['share_account_id'],
                    'basis_minor' => $l['basis_minor'], 'amount_minor' => $l['amount_minor'], 'paid_to' => $l['paid_to'], 'savings_account_id' => $l['savings_account_id'], 'status' => 'due']);
            }
            $approval = (new SaccoApprovals())->request($company, 'dividend_run', ['run_id' => (int) $run->id, 'year' => $year, 'rate_bp' => $p['rate_bp'], 'basis' => $p['basis'],
                'pool_minor' => $p['pool_minor'], 'members' => count($p['lines']), 'pay_to' => $p['pay_to'], 'on' => $p['on'], 'lines_hash' => self::linesHash($p['lines']),
                'registrar_consent_file_id' => $p['registrar_consent_file_id'], 'registrar_consent_on' => $p['registrar_consent_on'],
                'warnings' => array_column($p['warnings'], 'code')], $maker, $uuid ?? (string) Str::uuid());
            $run->update(['approval_id' => $approval->id]);

            return $approval;
        });
    }

    /** The approved run (inside the approval's transaction). @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        $pl = (array) $approval->payload;
        SaccoTransactions::lockHead($cid);
        $run = SaccoDividendRun::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $pl['run_id']);
        if ($run->status === 'paid') {
            return ['run_id' => (int) $run->id, 'entry_id' => $run->entry_id];
        }
        $p = $this->preview($cid, (int) $pl['year'], ['pay_to' => $run->pay_to, 'registrar_consent_file_id' => $run->registrar_consent_file_id,
            'registrar_consent_on' => $run->registrar_consent_on?->toDateString(), 'registrar_consent_ref' => $run->registrar_consent_ref, 'on' => $pl['on']]);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message'], ['refusals' => $p['refusals']]);
        }
        if (self::linesHash($p['lines']) !== $pl['lines_hash'] || $p['pool_minor'] !== (int) $run->pool_minor) {
            throw BusinessRuleException::make('figures_changed', 'A member\'s shares or savings accounts changed since the dividend was asked: ask again.');
        }
        $lines = SaccoDividendLine::withoutGlobalScopes()->where('run_id', $run->id)->orderBy('member_id')->lockForUpdate()->get();
        $payable = ChartOfAccounts::id($cid, 'dividends.payable');
        [$entryDate, $valueDate] = LoanBooks::dates($cid, (string) $pl['on']);
        $local = $lines->whereIn('paid_to', ['savings', 'cash']);
        $entryId = null;
        if ($local->isNotEmpty()) {
            $draft = JournalDraft::make($cid, 'dividend', 'div:'.$run->id)->source('sacco_dividend_runs', (int) $run->id)->approval((int) $approval->id)->by((int) $checker->id)
                ->memo('Dividend '.$pl['year'].' at '.Appropriation::pct((int) $run->rate_bp))->debit($payable, LoanBooks::money($cid, (int) $local->sum('amount_minor')));
            $cash = 0;
            foreach ($local as $l) {
                if ($l->paid_to === 'savings') {
                    $a = \App\Models\SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($l->savings_account_id);
                    $draft->credit((int) $a->ledger_account_id, LoanBooks::money($cid, (int) $l->amount_minor), 'savings', (int) $a->id, (int) $a->member_id, 'Dividend '.$pl['year']);
                } else {
                    $cash += (int) $l->amount_minor;
                }
            }
            if ($cash > 0) {
                $draft->credit(ChartOfAccounts::id($cid, 'cash.office'), LoanBooks::money($cid, $cash), memo: 'Dividends paid in cash');
            }
            $entryId = (int) (new PostingService())->post($draft->dated($entryDate, $valueDate))->id;
        }
        foreach ($lines as $l) {
            if ($l->paid_to === 'momo') {
                $member = SaccoMember::withoutGlobalScopes()->findOrFail($l->member_id);
                $payout = (new PayoutService())->create($cid, 'dividend', 'sacco_dividend_lines', (int) $l->id, (int) $l->member_id, (string) $member->phone_e164, (int) $l->amount_minor,
                    (int) $approval->id, 'dividend:'.$l->id.':'.$approval->uuid, fn (string $k) => JournalDraft::make($cid, 'payout', $k)
                        ->debit($payable, LoanBooks::money($cid, (int) $l->amount_minor), memberId: (int) $l->member_id)
                        ->credit(ChartOfAccounts::id($cid, 'payouts.in_transit'), LoanBooks::money($cid, (int) $l->amount_minor), memberId: (int) $l->member_id)
                        ->by((int) $checker->id)->approval((int) $approval->id)->memo('Dividend '.$pl['year'].' by mobile money'), $entryDate);
                $l->fill(['payout_id' => $payout->id, 'status' => 'sent'])->save();
            } else {
                $l->fill(['status' => 'paid', 'paid_on' => $valueDate])->save();
                YearEndNotices::toMember($cid, (int) $l->member_id, 'dividend_paid', ['amount' => LoanBooks::fmt($cid, (int) $l->amount_minor), 'year' => (string) $pl['year'],
                    'to' => $l->paid_to === 'savings' ? 'your savings' : 'collect in cash']);
            }
        }
        $run->update(['status' => 'paid', 'entry_id' => $entryId, 'paid_on' => $valueDate, 'warnings' => $p['warnings']]);
        SaccoAudit::record('sacco.dividend.paid', $run, $cid, ['year' => $pl['year'], 'pool_minor' => (int) $run->pool_minor, 'entry_id' => $entryId], (int) $checker->id);

        return ['run_id' => (int) $run->id, 'entry_id' => $entryId, 'pool_minor' => (int) $run->pool_minor];
    }

    /** A MoMo dividend's final answer (PayoutService handler): paid, or failed (the payout reversed back to 2420). */
    public static function payoutResult(SaccoPayout $payout, string $status): void
    {
        $line = SaccoDividendLine::withoutGlobalScopes()->where('company_id', $payout->company_id)->lockForUpdate()->find($payout->subject_id);
        if ($line === null || $line->status !== 'sent') {
            return;
        }
        $line->fill(['status' => $status === 'successful' ? 'paid' : 'failed', 'paid_on' => $status === 'successful' ? LoanBooks::today((int) $payout->company_id)->toDateString() : null])->save();
        if ($status === 'successful') {
            YearEndNotices::toMember((int) $payout->company_id, (int) $line->member_id, 'dividend_paid', ['amount' => LoanBooks::fmt((int) $payout->company_id, (int) $line->amount_minor),
                'year' => (string) DB::table('sacco_fiscal_years as y')->join('sacco_dividend_runs as r', 'r.fiscal_year_id', '=', 'y.id')->where('r.id', $line->run_id)->value('y.year'),
                'to' => 'your mobile money']);
        }
    }

    /**
     * Pay a dividend whose mobile-money payout failed into the member's savings, or in cash (an officer who may pay
     * out: the run itself was approved). Key `div:line:{id}`.
     */
    public function payFailed(Company $company, int $lineId, string $to, User $by): SaccoDividendLine
    {
        SaccoAccess::authorize($by, 'sacco_disburse');
        $cid = (int) $company->id;
        if (! in_array($to, ['savings', 'cash'], true)) {
            throw BusinessRuleException::make('invalid_dividend', 'Pay it into savings or in cash.');
        }

        return DB::transaction(function () use ($cid, $lineId, $to, $by) {
            SaccoTransactions::lockHead($cid);
            $l = SaccoDividendLine::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($lineId)
                ?? throw BusinessRuleException::make('not_found', 'That dividend is not in this SACCO.');
            if ($l->status !== 'failed') {
                throw BusinessRuleException::make('invalid_dividend', 'Only a dividend whose mobile-money payment failed is paid again.');
            }
            $money = LoanBooks::money($cid, (int) $l->amount_minor);
            $draft = JournalDraft::make($cid, 'dividend', 'div:line:'.$l->id)->source('sacco_dividend_lines', (int) $l->id)->by((int) $by->id)->memo('Dividend paid again after a failed payout')
                ->debit(ChartOfAccounts::id($cid, 'dividends.payable'), $money, memberId: (int) $l->member_id);
            $account = null;
            if ($to === 'savings') {
                $account = MemberFunds::payoutAccount($cid, (int) $l->member_id) ?? throw BusinessRuleException::make('account_not_found', 'The member has no open savings account.');
                $draft->credit((int) $account->ledger_account_id, $money, 'savings', (int) $account->id, (int) $account->member_id, 'Dividend');
            } else {
                $draft->credit(ChartOfAccounts::id($cid, 'cash.office'), $money, memberId: (int) $l->member_id);
            }
            (new PostingService())->post($draft);
            $l->fill(['status' => 'paid', 'paid_to' => $to, 'savings_account_id' => $account?->id, 'paid_on' => LoanBooks::today($cid)->toDateString()])->save();

            return $l->fresh();
        });
    }

    public static function closed(SaccoApproval $approval, string $status): void
    {
        SaccoDividendRun::withoutGlobalScopes()->where('company_id', $approval->company_id)->whereKey($approval->payload['run_id'] ?? 0)->where('status', 'requested')
            ->first()?->update(['status' => $status]);
    }

    /** @param list<array<string, mixed>> $lines */
    private static function linesHash(array $lines): string
    {
        return hash('sha256', (string) json_encode(array_map(fn ($l) => [$l['member_id'], $l['amount_minor'], $l['paid_to'], $l['savings_account_id']], $lines)));
    }

    private function day(int $cid, int $year, mixed $on): string
    {
        [, $end] = FiscalCalendar::bounds($cid, $year);
        $day = $on !== null && $on !== '' ? Carbon::parse((string) $on)->startOfDay() : LoanBooks::today($cid);
        if ($day->lte($end) || $day->gt(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'The dividend is paid after the year has ended, and not in the future.');
        }

        return $day->toDateString();
    }
}
