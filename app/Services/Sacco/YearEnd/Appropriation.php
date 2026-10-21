<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoAppropriation;
use App\Models\SaccoApproval;
use App\Models\SaccoFiscalYear;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
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
 * The appropriation of a closed year's surplus after the AGM (P4.7, 02 §5, AK-18), as a waterfall in this order:
 *
 *   1. the statutory reserve (3200), at least 10 % of the surplus (Tier 4 s.52; Coop s.47, reg. 34);
 *   2. the National Cooperative Education Fund (2440 payable), 1 % (Coop s.49);
 *   3. the member education budget (3610 reserve), 5 % (Coop s.49A(6));
 *   4. the Share Transfer Fund (3300), at most 5 % of the share capital (Tier 4 s.52; Regs 1992 reg. 43);
 *   5. other reserves the AGM approves (3600);
 *   6. the dividend on shares (2420 payable), at most 10 % a year, only while core capital stays at least 10 % of total
 *      assets after it (Regs 1992 reg. 40; SI 51 reg. 32);
 *   7. what is left stays in retained earnings (3400).
 *
 * Posted once, key `approp:{year}`: Dr 3410 the surplus, Cr each line (a deficit moves to 3400 instead). Two officers
 * in the chair's, vice chair's or treasurer's office approve (`appropriation`).
 *
 * The law's limits (the reserve, the two education lines, the transfer fund, the dividend's cap and the core capital
 * after it) bind a licensed SACCO only (SaccoRules::strict, AK-69): for any other SACCO each is a warning kept on the
 * appropriation and shown on its report, never a refusal. The arithmetic binds everyone: the lines never take more
 * than the surplus, and a SACCO's own settings (a dividend cap below 10 %, a reserve above 10 %) are its own rules.
 */
final class Appropriation
{
    public const BASES = ['year_end_shares', 'weighted_monthly'];

    /** The law's floors and caps, in basis points (AK-18). */
    public const RESERVE_MIN_BP = 1000;

    public const NCEF_BP = 100;

    public const EDUCATION_BP = 500;

    public const STF_MAX_BP = 500;

    public const DIVIDEND_CAP_BP = 1000;

    public const CORE_CAPITAL_MIN_BP = 1000;

    /** key => [label, system key of the account credited]. */
    public const LINES = [
        'reserve' => ['Statutory reserve', 'reserve.statutory'],
        'ncef' => ['National Cooperative Education Fund', 'ncef.payable'],
        'education' => ['Member education budget', 'reserve.education'],
        'stf' => ['Share Transfer Fund', 'stf'],
        'other_reserves' => ['Other reserves', 'reserve.general'],
        'dividend' => ['Dividend on shares', 'dividends.payable'],
        'retained' => ['Retained earnings', 're.prior'],
    ];

    /**
     * The waterfall for a closed year, with what would be refused and what is only a warning.
     *
     * @param  array<string, mixed>  $data  reserve_bp, ncef_bp, education_bp, stf_minor, other_reserves_minor, dividend_rate_bp, dividend_basis, on
     * @return array<string, mixed>
     */
    public function preview(int $companyId, int $year, array $data): array
    {
        $fy = $this->closedYear($companyId, $year);
        $s = LoanBooks::settings($companyId);
        $surplus = (int) $fy->net_surplus_minor;
        $in = $this->inputs($data, $s);
        $on = $this->day($companyId, $fy, $data['on'] ?? null);
        $issues = [];
        $pct = fn (int $bp) => $surplus > 0 ? LoanBooks::money($companyId, $surplus)->times(bcdiv((string) $bp, '10000', 4))->minor : 0;
        $lines = ['reserve' => $pct($in['reserve_bp']), 'ncef' => $pct($in['ncef_bp']), 'education' => $pct($in['education_bp']),
            'stf' => $in['stf_minor'], 'other_reserves' => $in['other_reserves_minor']];
        $dividend = Dividends::pool($companyId, $year, $in['dividend_rate_bp'], $in['dividend_basis']);
        $lines['dividend'] = $dividend['pool_minor'];
        $given = array_sum($lines);
        if ($surplus <= 0 && $given > 0) {
            $issues[] = ['no_surplus', 'There is no surplus to share: the year closed with '.($surplus < 0 ? 'a deficit' : 'nothing').'.', true];
        } elseif ($given > $surplus) {
            $issues[] = ['appropriation_exceeds_surplus', 'The lines take '.LoanBooks::fmt($companyId, $given).', more than the surplus of '.LoanBooks::fmt($companyId, $surplus).'.', true];
        }
        $lines['retained'] = $surplus - $given;

        // the SACCO's own rules
        if ($in['dividend_rate_bp'] > (int) $s->dividend_cap_bp && (int) $s->dividend_cap_bp < self::DIVIDEND_CAP_BP) {
            $issues[] = ['dividend_above_cap', 'The dividend rate is above this SACCO\'s own cap of '.self::pct((int) $s->dividend_cap_bp).'.', true];
        }
        if ($in['reserve_bp'] < (int) $s->reserve_min_bp && (int) $s->reserve_min_bp > self::RESERVE_MIN_BP) {
            $issues[] = ['reserve_below_minimum', 'The reserve is below this SACCO\'s own minimum of '.self::pct((int) $s->reserve_min_bp).'.', true];
        }
        // the law's limits: refusals for a licensed SACCO, warnings for any other
        $shareCapital = Figures::position($companyId, $fy->ends_on)['lines']['share_capital'];
        $position = Figures::position($companyId, $on);
        $capital = Figures::capital($position);
        $coreAfter = $capital['core'] - $lines['ncef'] - $lines['dividend'];
        $ratioAfter = Figures::bp($coreAfter, $position['totals']['assets']);
        if ($surplus > 0) {
            if ($in['reserve_bp'] < self::RESERVE_MIN_BP) {
                $issues[] = ['reserve_below_minimum', 'At least 10 % of the surplus should go to the reserve; this sends '.self::pct($in['reserve_bp']).'.', false];
            }
            if ($in['ncef_bp'] < self::NCEF_BP) {
                $issues[] = ['education_fund_below_minimum', '1 % of the surplus should go to the National Cooperative Education Fund; this sends '.self::pct($in['ncef_bp']).'.', false];
            }
            if ($in['education_bp'] < self::EDUCATION_BP) {
                $issues[] = ['education_budget_below_minimum', '5 % of the surplus should be budgeted for member education; this budgets '.self::pct($in['education_bp']).'.', false];
            }
        }
        $stfCap = LoanBooks::money($companyId, max(0, $shareCapital))->times(bcdiv((string) self::STF_MAX_BP, '10000', 4))->minor;
        if ($lines['stf'] > $stfCap) {
            $issues[] = ['stf_above_limit', 'The Share Transfer Fund may take at most 5 % of the share capital ('.LoanBooks::fmt($companyId, $stfCap).') a year.', false];
        }
        if ($in['dividend_rate_bp'] > self::DIVIDEND_CAP_BP) {
            $issues[] = ['dividend_above_cap', 'A dividend may be at most 10 % of the shares a year; this asks '.self::pct($in['dividend_rate_bp']).'.', false];
        }
        if ($lines['dividend'] > 0 && ($ratioAfter === null || $ratioAfter < self::CORE_CAPITAL_MIN_BP)) {
            $issues[] = ['core_capital_after_dividend', 'After this dividend core capital would be '.($ratioAfter === null ? 'nothing' : self::pct($ratioAfter))
                .' of total assets; it should stay at least 10 %.', false];
        }
        $strict = SaccoRules::strict($companyId);
        $refusals = [];
        $warnings = [];
        foreach ($issues as [$code, $message, $always]) {
            ($always || $strict) ? $refusals[] = ['code' => $code, 'message' => $message] : $warnings[] = ['code' => $code, 'message' => $message];
        }
        $out = [];
        foreach (self::LINES as $key => [$label, $account]) {
            $out[] = ['key' => $key, 'label' => $label, 'account' => $account, 'bp' => $in[$key.'_bp'] ?? null, 'amount_minor' => $lines[$key]];
        }

        return ['year' => $year, 'fiscal_year_id' => (int) $fy->id, 'surplus_minor' => $surplus, 'on' => $on, 'lines' => $out, 'inputs' => $in,
            'dividend' => $dividend, 'share_capital_minor' => $shareCapital, 'core_capital_after_minor' => $coreAfter, 'total_assets_minor' => $position['totals']['assets'],
            'core_capital_ratio_bp_after' => $ratioAfter, 'strict' => $strict, 'warnings' => $warnings, 'refusals' => $refusals];
    }

    /**
     * Ask for the appropriation the AGM adopted. A licensed SACCO's request that breaks a limit is refused with the
     * first reason; any other SACCO's goes ahead with the warnings attached.
     *
     * @param  array<string, mixed>  $data  as preview(), plus agm_date and agm_minutes_file_id
     */
    public function request(Company $company, int $year, array $data, User $maker, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_accounts');
        $cid = (int) $company->id;
        $p = $this->preview($cid, $year, $data);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message'], ['refusals' => $p['refusals']]);
        }
        if (DB::table('sacco_appropriations as a')->join('sacco_approvals as ap', 'ap.id', '=', 'a.approval_id')->where('a.company_id', $cid)
            ->where('a.fiscal_year_id', $p['fiscal_year_id'])->where('a.status', 'requested')->whereIn('ap.status', ['pending', 'approved'])->where('ap.expires_at', '>=', now())->exists()) {
            throw BusinessRuleException::make('appropriation_pending', 'An appropriation of this year already waits for approval.');
        }
        $agm = isset($data['agm_date']) && $data['agm_date'] !== '' ? Carbon::parse((string) $data['agm_date'])->toDateString() : null;
        $minutes = isset($data['agm_minutes_file_id']) ? $this->file($cid, (int) $data['agm_minutes_file_id']) : null;

        return DB::transaction(function () use ($company, $cid, $year, $p, $agm, $minutes, $maker, $uuid) {
            $row = SaccoAppropriation::create(['company_id' => $cid, 'fiscal_year_id' => $p['fiscal_year_id'], 'surplus_minor' => $p['surplus_minor'], 'lines' => $p['lines'],
                'dividend_rate_bp' => $p['inputs']['dividend_rate_bp'], 'dividend_basis' => $p['inputs']['dividend_basis'], 'dividend_pool_minor' => $p['dividend']['pool_minor'],
                'agm_date' => $agm, 'agm_minutes_file_id' => $minutes, 'status' => 'requested', 'warnings' => $p['warnings'], 'created_by_id' => $maker->id]);
            $approval = (new SaccoApprovals())->request($company, 'appropriation', ['appropriation_id' => (int) $row->id, 'year' => $year, 'fiscal_year_id' => $p['fiscal_year_id'],
                'surplus_minor' => $p['surplus_minor'], 'lines' => array_map(fn ($l) => ['key' => $l['key'], 'amount_minor' => $l['amount_minor']], $p['lines']),
                'dividend' => $p['dividend'], 'agm_date' => $agm, 'on' => $p['on'], 'warnings' => array_column($p['warnings'], 'code')], $maker, $uuid ?? (string) Str::uuid());
            $row->update(['approval_id' => $approval->id]);

            return $approval;
        });
    }

    /** The approved appropriation (inside the approval's transaction). @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        $pl = (array) $approval->payload;
        SaccoTransactions::lockHead($cid);
        $row = SaccoAppropriation::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $pl['appropriation_id']);
        if ($row->status === 'posted') {
            return ['appropriation_id' => (int) $row->id, 'entry_id' => $row->entry_id];
        }
        $fy = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $pl['fiscal_year_id']);
        if ($fy->appropriated_on !== null) {
            throw BusinessRuleException::make('already_appropriated', 'This year\'s surplus is already appropriated.');
        }
        $inputs = ['on' => $pl['on']] + $this->inputsOf($row);
        $p = $this->preview($cid, (int) $pl['year'], $inputs);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message'], ['refusals' => $p['refusals']]);
        }
        $now = array_map(fn ($l) => ['key' => $l['key'], 'amount_minor' => $l['amount_minor']], $p['lines']);
        if ($now !== $pl['lines'] || $p['dividend']['pool_minor'] !== (int) $pl['dividend']['pool_minor']) {
            throw BusinessRuleException::make('figures_changed', 'The year\'s figures changed since the appropriation was asked: ask again.');
        }
        (new ChartOfAccounts())->seed($cid, 'office'); // a chart from before P4 gains 3610 (the education reserve)
        $surplus = (int) $p['surplus_minor'];
        $entryId = null;
        if ($surplus !== 0) {
            $current = ChartOfAccounts::id($cid, 're.current');
            $draft = JournalDraft::make($cid, 'appropriation', 'approp:'.$pl['year'])->source('sacco_appropriations', (int) $row->id)->approval((int) $approval->id)
                ->by((int) $checker->id)->memo('Appropriation of the '.$pl['year'].' surplus'.($row->agm_date ? ' (AGM '.$row->agm_date->format('j M Y').')' : ''));
            if ($surplus > 0) {
                $draft->debit($current, LoanBooks::money($cid, $surplus));
                foreach ($p['lines'] as $l) {
                    if ($l['amount_minor'] > 0) {
                        $draft->credit(ChartOfAccounts::id($cid, self::LINES[$l['key']][1]), LoanBooks::money($cid, $l['amount_minor']), memo: $l['label']);
                    }
                }
            } else {
                $draft->debit(ChartOfAccounts::id($cid, 're.prior'), LoanBooks::money($cid, -$surplus), memo: 'Deficit to retained earnings')
                    ->credit($current, LoanBooks::money($cid, -$surplus));
            }
            [$entry, $value] = LoanBooks::dates($cid, (string) $pl['on']);
            $entryId = (int) (new PostingService())->post($draft->dated($entry, $value))->id;
        }
        $row->update(['status' => 'posted', 'entry_id' => $entryId, 'posted_on' => LoanBooks::today($cid)->toDateString(), 'warnings' => $p['warnings']]);
        $fy->fill(['appropriated_on' => (string) $pl['on']])->save();
        SaccoAudit::record('sacco.year.appropriated', $row, $cid, ['year' => $pl['year'], 'entry_id' => $entryId, 'warnings' => array_column($p['warnings'], 'code')], (int) $checker->id);

        return ['appropriation_id' => (int) $row->id, 'entry_id' => $entryId, 'dividend_pool_minor' => (int) $p['dividend']['pool_minor']];
    }

    public static function closed(SaccoApproval $approval, string $status): void
    {
        SaccoAppropriation::withoutGlobalScopes()->where('company_id', $approval->company_id)->whereKey($approval->payload['appropriation_id'] ?? 0)->where('status', 'requested')
            ->first()?->update(['status' => $status]);
    }

    /** The posted appropriation of a year, or null. */
    public static function posted(int $companyId, int $year): ?SaccoAppropriation
    {
        return SaccoAppropriation::withoutGlobalScopes()->from('sacco_appropriations as a')->join('sacco_fiscal_years as y', 'y.id', '=', 'a.fiscal_year_id')
            ->where('a.company_id', $companyId)->where('y.year', $year)->where('a.status', 'posted')->select('a.*')->first();
    }

    private function closedYear(int $cid, int $year): SaccoFiscalYear
    {
        if (LoanBooks::settings($cid)->isGroup()) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group shares out its cycle instead.');
        }
        $fy = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $cid)->where('year', $year)->first();
        if ($fy === null || $fy->status !== 'closed') {
            throw BusinessRuleException::make('year_not_closed', 'Close the financial year '.$year.' first.');
        }
        if ($fy->appropriated_on !== null) {
            throw BusinessRuleException::make('already_appropriated', 'The '.$year.' surplus is already appropriated.');
        }

        return $fy;
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function inputs(array $d, \App\Models\SaccoSetting $s): array
    {
        $bp = function (string $k, int $default) use ($d): int {
            $v = $d[$k] ?? $default;
            if (! is_int($v) && ! (is_string($v) && ctype_digit($v))) {
                throw BusinessRuleException::make('invalid_appropriation', "{$k} is a whole number of basis points.");
            }
            if ((int) $v > 10000) {
                throw BusinessRuleException::make('invalid_appropriation', "{$k} is at most 10000 (100 %).");
            }

            return (int) $v;
        };
        $minor = function (string $k) use ($d): int {
            $v = $d[$k] ?? 0;
            if (! is_int($v) && ! (is_string($v) && ctype_digit($v))) {
                throw BusinessRuleException::make('invalid_appropriation', "{$k} is whole minor units, zero or more.");
            }

            return (int) $v;
        };
        $basis = (string) ($d['dividend_basis'] ?? 'year_end_shares');
        if (! in_array($basis, self::BASES, true)) {
            throw BusinessRuleException::make('invalid_appropriation', 'The dividend is worked out on the shares held at the year\'s end (year_end_shares) or their monthly average (weighted_monthly).');
        }

        return ['reserve_bp' => $bp('reserve_bp', max(self::RESERVE_MIN_BP, (int) $s->reserve_min_bp)), 'ncef_bp' => $bp('ncef_bp', (int) ($s->education_fund_bp ?: self::NCEF_BP)),
            'education_bp' => $bp('education_bp', self::EDUCATION_BP), 'stf_minor' => $minor('stf_minor'), 'other_reserves_minor' => $minor('other_reserves_minor'),
            'dividend_rate_bp' => $bp('dividend_rate_bp', 0), 'dividend_basis' => $basis];
    }

    /** @return array<string, mixed> the inputs an appropriation was asked with */
    private function inputsOf(SaccoAppropriation $row): array
    {
        $by = collect((array) $row->lines)->keyBy('key');

        return ['reserve_bp' => (int) ($by['reserve']['bp'] ?? 0), 'ncef_bp' => (int) ($by['ncef']['bp'] ?? 0), 'education_bp' => (int) ($by['education']['bp'] ?? 0),
            'stf_minor' => (int) ($by['stf']['amount_minor'] ?? 0), 'other_reserves_minor' => (int) ($by['other_reserves']['amount_minor'] ?? 0),
            'dividend_rate_bp' => (int) $row->dividend_rate_bp, 'dividend_basis' => (string) $row->dividend_basis];
    }

    private function day(int $cid, SaccoFiscalYear $fy, mixed $on): string
    {
        $day = $on !== null && $on !== '' ? Carbon::parse((string) $on)->startOfDay() : LoanBooks::today($cid);
        if ($day->lte($fy->ends_on) || $day->gt(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'The appropriation is posted after the year has ended, and not in the future.');
        }

        return $day->toDateString();
    }

    private function file(int $cid, int $id): int
    {
        return (int) (DB::table('files')->where('company_id', $cid)->where('id', $id)->value('id')
            ?? throw BusinessRuleException::make('file_not_found', 'That file is not in this SACCO.'));
    }

    /** 1050 → "10.5 %", 1000 → "10 %" (integer arithmetic only). */
    public static function pct(int $bp): string
    {
        $sign = $bp < 0 ? '-' : '';
        $bp = abs($bp);
        $dec = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($bp, 100).($dec === '' ? '' : '.'.$dec).' %';
    }
}
