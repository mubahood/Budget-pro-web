<?php

namespace App\Services\Billing;

use App\Models\SubscriptionInvoice;

/**
 * Hourly reconciliation (POWER_PLAN §4.1): a payment whose webhook was lost is never lost. Pending
 * invoices younger than saas.reconcile_hours (72) are re-verified with Flutterwave by reference and
 * fulfilled or failed; older ones are marked abandoned (nothing was paid for them).
 */
class Reconciler
{
    public function __construct(private readonly SubscriptionFulfillment $fulfillment)
    {
    }

    /** @return array{checked: int, paid: int, failed: int, abandoned: int, errors: int} */
    public function run(): array
    {
        $counts = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'abandoned' => 0, 'errors' => 0];
        $cutoff = now()->subHours((int) config('saas.reconcile_hours', 72));

        $counts['abandoned'] = SubscriptionInvoice::where('status', 'pending')->where('created_at', '<', $cutoff)
            ->update(['status' => 'abandoned', 'abandoned_at' => now(), 'updated_at' => now()]);

        SubscriptionInvoice::where('status', 'pending')->where('provider', 'flutterwave')->whereNotNull('provider_invoice_id')
            ->where('created_at', '>=', $cutoff)->where('created_at', '<=', now()->subMinutes(2)) // leave the page's own polling a moment
            ->orderBy('id')->chunkById(100, function ($invoices) use (&$counts) {
                foreach ($invoices as $invoice) {
                    $counts['checked']++;
                    $r = $this->fulfillment->verifyAndFulfill((string) $invoice->provider_invoice_id);
                    match ($r['status']) {
                        'paid' => $counts['paid']++,
                        'failed' => $counts['failed']++,
                        'unverified' => $r['transient'] ? $counts['errors']++ : null,
                        default => null,
                    };
                }
            });

        return $counts;
    }
}
