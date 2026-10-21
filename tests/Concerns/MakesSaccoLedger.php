<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\SubLedger;
use App\Services\Sacco\Ledger\SubLedgers;
use App\Support\Sacco\Amount;
use Tests\Support\Sacco\PotLedger;

/**
 * A SACCO's books for the kernel's ledger tests: a company with its chart, its head and this month's periods,
 * and the stand-in PotLedger registered as the savings sub-ledger (put back as it was in tearDown).
 */
trait MakesSaccoLedger
{
    protected ?SubLedger $previousSavingsLedger = null;

    protected bool $potsRegistered = false;

    /** @return array{0: Company, 1: User} */
    protected function saccoBooks(string $mode = 'office', string $currency = 'UGX'): array
    {
        $company = Company::factory()->create(['name' => 'Ledger SACCO '.uniqid(), 'status' => 'Active', 'currency' => $currency]);
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'led_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $user->id;
        $company->saveQuietly();
        (new ChartOfAccounts())->seed((int) $company->id, $mode);
        (new PeriodService())->ensureAhead((int) $company->id, now(), 3);

        return [$company, $user];
    }

    protected function usePots(): void
    {
        PotLedger::createTable();
        if (! $this->potsRegistered) {
            $this->previousSavingsLedger = SubLedgers::all()['savings'] ?? null;
            SubLedgers::register(new PotLedger());
            $this->potsRegistered = true;
        }
    }

    protected function restorePots(): void
    {
        if ($this->potsRegistered) {
            SubLedgers::forget('savings');
            if ($this->previousSavingsLedger !== null) {
                SubLedgers::register($this->previousSavingsLedger);
            }
            $this->potsRegistered = false;
        }
    }

    protected function ugx(int $minor, string $currency = 'UGX'): Amount
    {
        return Amount::of($minor, $currency);
    }

    /** A plain two-line entry between two system accounts. */
    protected function simple(int $companyId, string $key, int $minor, string $debitKey = 'cash.office', string $creditKey = 'inc.entrance', string $kind = 'entrance_fee'): JournalDraft
    {
        $currency = PostingService::currency($companyId);

        return JournalDraft::make($companyId, $kind, $key)
            ->debit(ChartOfAccounts::id($companyId, $debitKey), Amount::of($minor, $currency))
            ->credit(ChartOfAccounts::id($companyId, $creditKey), Amount::of($minor, $currency));
    }
}
