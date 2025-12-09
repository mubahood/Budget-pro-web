<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\BudgetItem;
use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\FinancialCategory;
use App\Models\FinancialPeriod;
use App\Models\FinancialRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Policies\BudgetItemPolicy;
use App\Policies\BudgetProgramPolicy;
use App\Policies\ContributionRecordPolicy;
use App\Policies\FinancialCategoryPolicy;
use App\Policies\FinancialPeriodPolicy;
use App\Policies\FinancialRecordPolicy;
use App\Policies\StockCategoryPolicy;
use App\Policies\StockItemPolicy;
use App\Policies\StockRecordPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        StockCategory::class => StockCategoryPolicy::class,
        StockItem::class => StockItemPolicy::class,
        StockRecord::class => StockRecordPolicy::class,
        FinancialCategory::class => FinancialCategoryPolicy::class,
        FinancialRecord::class => FinancialRecordPolicy::class,
        FinancialPeriod::class => FinancialPeriodPolicy::class,
        BudgetItem::class => BudgetItemPolicy::class,
        BudgetProgram::class => BudgetProgramPolicy::class,
        ContributionRecord::class => ContributionRecordPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
