<?php

namespace App\Services\Sacco\Approvals;

use App\Models\SaccoApproval;
use App\Models\User;
use App\Services\Sacco\Savings\SavingsProducts;

/**
 * `product_terms_change` (04 §7): new terms for a savings product, asked by an officer with sacco_settings and approved
 * by another with sacco_approve. The frozen payload shows the old and new terms and the day they take effect (a rate
 * cut: 30 days out at the earliest, so members are told first).
 */
final class ProductTermsChangeExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'product_terms_change';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_settings';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $p = (new SavingsProducts())->prepareTermsChange($companyId, $payload);

        return ['payload' => ['product_id' => (int) $p['product']->id, 'code' => $p['product']->code, 'name' => $p['product']->name, 'old' => $p['old'], 'terms' => $p['new'],
            'effective_from' => $p['effective_from']], 'subject_type' => 'sacco_savings_products', 'subject_id' => (int) $p['product']->id, 'amount_minor' => null];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $version = (new SavingsProducts())->applyTerms((int) $approval->company_id, (int) $p['product_id'], (array) $p['terms'], (string) $p['effective_from'],
            (int) $approval->id, $approval->requested_by_id === null ? null : (int) $approval->requested_by_id);

        return ['product_id' => (int) $p['product_id'], 'terms_version' => (int) $version->terms_version, 'effective_from' => (string) $p['effective_from']];
    }
}
