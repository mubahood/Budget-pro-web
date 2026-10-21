<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoLoan;
use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use App\Support\LocalDate;
use App\Support\Sacco\Amount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Small things every loan service needs: the SACCO's settings and currency, money, and the dates a posting takes. */
final class LoanBooks
{
    public static function settings(int $companyId): SaccoSetting
    {
        return SaccoSetting::for($companyId) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
    }

    /**
     * Whether the SACCO is licensed (an UMRA licence number on its settings): only then do the checks that enforce a
     * law block a loan action (AK-90; the same test as SaccoRules::strict). An unlicensed SACCO keeps its own rules.
     */
    public static function strict(int $companyId): bool
    {
        return filled(SaccoSetting::for($companyId)?->licence_no);
    }

    public static function money(int $companyId, int $minor): Amount
    {
        return Amount::of($minor, PostingService::currency($companyId));
    }

    /** For messages: the company's money format. */
    public static function fmt(int $companyId, int $minor): string
    {
        return self::money($companyId, $minor)->format($companyId);
    }

    public static function today(int $companyId): Carbon
    {
        return LocalDate::today($companyId)->copy()->startOfDay();
    }

    /**
     * The entry and value dates of a posting about $day (AK-45): on that day while its month is open (the demo's
     * history, a due date posted on time), otherwise today with the old value date, never into a closed month.
     *
     * @return array{0: string, 1: string} [entry date, value date]
     */
    public static function dates(int $companyId, CarbonInterface|string $day): array
    {
        $d = Carbon::parse($day)->startOfDay();
        $today = self::today($companyId);
        if ($d->gt($today)) {
            throw BusinessRuleException::make('invalid_date', 'Nothing is posted in the future.');
        }
        $period = (new PeriodService())->find($companyId, $d);
        $later = \App\Models\SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'open')
            ->where('ends_on', '>=', $d->toDateString())->exists();
        if (($period !== null && ! $period->isOpen()) || ($period === null && $later)) {
            return [$today->toDateString(), $d->toDateString()];
        }

        return [$d->toDateString(), $d->toDateString()];
    }

    /** The loan, locked (inside the caller's transaction), in this SACCO. */
    public static function lock(int $companyId, int $loanId): SaccoLoan
    {
        return SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->lockForUpdate()->find($loanId)
            ?? throw BusinessRuleException::make('loan_not_found', 'That loan is not in this SACCO.');
    }

    public static function find(int $companyId, int $loanId): SaccoLoan
    {
        return SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($loanId)
            ?? throw BusinessRuleException::make('loan_not_found', 'That loan is not in this SACCO.');
    }

    /** @param  list<string>  $statuses */
    public static function assertStatus(SaccoLoan $loan, array $statuses, string $doing): void
    {
        if (! in_array($loan->status, $statuses, true)) {
            throw BusinessRuleException::make('invalid_loan_status', "A loan that is {$loan->status} cannot be {$doing}.", ['status' => $loan->status]);
        }
    }
}
