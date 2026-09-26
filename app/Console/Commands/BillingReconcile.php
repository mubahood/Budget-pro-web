<?php

namespace App\Console\Commands;

use App\Services\Billing\Reconciler;
use Illuminate\Console\Command;

/** Hourly: re-verify pending subscription payments with Flutterwave; abandon those older than 72 h. */
class BillingReconcile extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Confirm subscription payments whose webhook was lost, and abandon stale pending invoices';

    public function handle(Reconciler $reconciler): int
    {
        $this->info('reconcile '.json_encode($reconciler->run()));

        return self::SUCCESS;
    }
}
