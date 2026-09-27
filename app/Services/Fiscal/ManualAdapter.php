<?php

namespace App\Services\Fiscal;

use App\Models\SaleRecord;
use App\Models\SaleReturn;

/**
 * For shops that print the fiscal receipt on a separate fiscal device / EFD: nothing is sent anywhere.
 * Each sale waits ("Fiscal receipt pending") until the cashier or owner types the device's receipt number
 * (FiscalService::recordManual).
 */
class ManualAdapter implements FiscalAdapter
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Separate fiscal device (numbers typed in)';
    }

    public function country(): ?string
    {
        return null;
    }

    public function configFields(): array
    {
        return [
            'device_name' => ['Fiscal device (optional)', 'text', false, 'For your records, e.g. the device serial number.'],
        ];
    }

    public function remote(): bool
    {
        return false;
    }

    public function test(array $config, string $environment): FiscalResult
    {
        return FiscalResult::ok('manual', raw: ['response' => 'Nothing to connect to: numbers are typed in.']);
    }

    public function submit(SaleRecord $sale, array $config, string $environment): FiscalResult
    {
        return FiscalResult::deferred('Waiting for the fiscal receipt number to be typed in.');
    }

    public function supportsCreditNotes(): bool
    {
        return false;
    }

    public function creditNote(SaleReturn $return, SaleRecord $sale, array $original, array $config, string $environment): FiscalResult
    {
        return FiscalResult::deferred('Returns are recorded on the fiscal device.');
    }
}
