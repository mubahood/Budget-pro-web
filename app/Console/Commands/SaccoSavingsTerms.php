<?php

namespace App\Console\Commands;

use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Console\Command;

/** Daily (04 §7 `product_terms_change`): approved savings terms whose day has come are put in force. */
class SaccoSavingsTerms extends Command
{
    protected $signature = 'sacco:savings-terms {--company= : one SACCO only}';

    protected $description = 'Put approved savings product terms in force on their effective date';

    public function handle(SavingsProducts $products): int
    {
        $company = $this->option('company');
        $this->info($products->applyDue($company === null ? null : (int) $company).' product(s) now on new terms.');

        return self::SUCCESS;
    }
}
