<?php

namespace App\Console\Commands;

use App\Services\Shop\CustomerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Buyer names typed on sales become customer accounts (CustomerService::adoptNamedBuyers). Idempotent. */
class AdoptNamedBuyers extends Command
{
    protected $signature = 'customers:adopt-named {--company= : One shop only}';

    protected $description = 'Make customer accounts for buyers who only exist as a name on sales, and link those sales';

    public function handle(CustomerService $customers): int
    {
        $ids = $this->option('company')
            ? [(int) $this->option('company')]
            : DB::table('sale_records')->whereNull('customer_id')->whereNotNull('customer_name')->distinct()->pluck('company_id')->all();
        $total = ['created' => 0, 'linked' => 0];
        foreach ($ids as $id) {
            $r = $customers->adoptNamedBuyers((int) $id);
            $total['created'] += $r['created'];
            $total['linked'] += $r['linked'];
            if ($r['linked'] > 0) {
                $this->line("shop {$id}: {$r['created']} customers created, {$r['linked']} sales linked");
            }
        }
        $this->info("Done: {$total['created']} customers created, {$total['linked']} sales linked.");

        return self::SUCCESS;
    }
}
