<?php

namespace App\Services\Fiscal;

use App\Models\SaleRecord;
use App\Models\SaleReturn;

/**
 * One government e-invoicing system or fiscal device (supermarket plan F2). One class per country/system,
 * registered in FiscalRegistry. Adapters never throw for a business failure: they return FiscalResult::fail,
 * and FiscalService retries it. A sale is never blocked by fiscalisation.
 */
interface FiscalAdapter
{
    /** Registry key, stored in fiscal_settings.adapter ("efris", "manual"). */
    public function key(): string;

    /** "Uganda EFRIS (URA)". */
    public function label(): string;

    /** ISO country code, or null for any country. */
    public function country(): ?string;

    /**
     * The settings the shop fills in: key => [label, type (text|textarea|select|secret|secret_textarea), required, help, options?].
     * `secret` fields are encrypted at rest and never shown again after saving.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3?: string, 4?: array<string, string>}>
     */
    public function configFields(): array;

    /** Whether the adapter talks to a remote system (false = numbers are typed in by hand). */
    public function remote(): bool;

    /** Check the credentials without sending a sale. */
    public function test(array $config, string $environment): FiscalResult;

    /** Fiscalise one finished sale. */
    public function submit(SaleRecord $sale, array $config, string $environment): FiscalResult;

    /** Whether returns must be reported (credit notes) in this system. */
    public function supportsCreditNotes(): bool;

    /** Report a return against a fiscalised sale ($original = that sale's successful submission). */
    public function creditNote(SaleReturn $return, SaleRecord $sale, array $original, array $config, string $environment): FiscalResult;
}
