<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Models\SaccoProvisionRun;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan-loss provisioning (04 §5.3, AK-40; SI 51): at each month end the allowance (1390) is moved to the required level,
 * Σ over open loans of outstanding principal × the class's rate (1 / 5 / 25 / 50 / 100 %), each loan's figure rounded
 * half-up once. The delta posts as Dr 5200 / Cr 1390 (or back), key prov:{yyyymm}, dated the month's last day. Office
 * mode only (a group's chart has no allowance). Runs once per month (sacco_provision_runs).
 */
final class Provisioning
{
    /** @return ?SaccoProvisionRun null in group mode */
    public function run(int $companyId, string $month): ?SaccoProvisionRun
    {
        if (LoanBooks::settings($companyId)->isGroup()) {
            return null;
        }
        if (($done = SaccoProvisionRun::withoutGlobalScopes()->where('company_id', $companyId)->where('month', $month)->first()) !== null) {
            return $done;
        }
        $end = Carbon::parse($month.'-01')->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($companyId, $month, $end) {
            \App\Services\Sacco\SaccoTransactions::lockHead($companyId);
            if (($done = SaccoProvisionRun::withoutGlobalScopes()->where('company_id', $companyId)->where('month', $month)->lockForUpdate()->first()) !== null) {
                return $done;
            }
            $currency = PostingService::currency($companyId);
            $byClass = [];
            $required = 0;
            foreach (SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['active', 'disbursing'])->where('disbursed_on', '<=', $end->toDateString())->get() as $loan) {
                $class = (string) $loan->classification;
                $bp = Arrears::provisionBp($class);
                $p = max(0, (int) $loan->principal_outstanding_minor);
                $need = Amount::of($p, $currency)->times(bcdiv((string) $bp, '10000', 4))->minor;
                $byClass[$class] ??= ['loans' => 0, 'principal_minor' => 0, 'rate_bp' => $bp, 'required_minor' => 0];
                $byClass[$class]['loans']++;
                $byClass[$class]['principal_minor'] += $p;
                $byClass[$class]['required_minor'] += $need;
                $required += $need;
            }
            $previous = (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($companyId, 'loans.allowance'))->value('balance_minor');
            $delta = $required - $previous;
            $entryId = null;
            if ($delta !== 0) {
                [$entryDate, $valueDate] = LoanBooks::dates($companyId, $end);
                $entryId = (int) (new PostingService())->post((new LoanPostingRules($companyId, 'office'))->provision('prov:'.str_replace('-', '', $month), Amount::of($delta, $currency))
                    ->dated($entryDate, $valueDate)->memo('Loan-loss provision for '.$month))->id;
            }

            return SaccoProvisionRun::create(['company_id' => $companyId, 'month' => $month, 'by_class' => $byClass, 'required_minor' => $required,
                'previous_minor' => $previous, 'delta_minor' => $delta, 'entry_id' => $entryId]);
        });
    }
}
