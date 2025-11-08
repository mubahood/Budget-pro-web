<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoReorderService;
use App\Models\Company;
use Illuminate\Support\Facades\Log;

class CheckAutoReorderRules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reorder:check 
                            {--company= : Check rules for a specific company ID}
                            {--force : Force check all rules regardless of schedule}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check auto reorder rules and trigger purchase orders when needed';

    protected $reorderService;

    /**
     * Create a new command instance.
     */
    public function __construct(AutoReorderService $reorderService)
    {
        parent::__construct();
        $this->reorderService = $reorderService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting auto reorder check...');
        
        $companyId = $this->option('company');
        $force = $this->option('force');

        try {
            if ($companyId) {
                // Check specific company
                $results = $this->checkCompany($companyId, $force);
                $this->displayResults($results);
            } else {
                // Check all companies
                $companies = Company::all();
                $totalResults = [
                    'checked' => 0,
                    'triggered' => 0,
                    'orders_created' => 0,
                    'errors' => [],
                ];

                foreach ($companies as $company) {
                    $this->info("Checking company: {$company->name}");
                    $results = $this->checkCompany($company->id, $force);
                    
                    $totalResults['checked'] += $results['checked'];
                    $totalResults['triggered'] += $results['triggered'];
                    $totalResults['orders_created'] += $results['orders_created'];
                    $totalResults['errors'] = array_merge($totalResults['errors'], $results['errors']);
                }

                $this->displayResults($totalResults);
            }

            $this->info('Auto reorder check completed successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error checking auto reorder rules: ' . $e->getMessage());
            Log::error('Auto reorder command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * Check rules for a specific company
     */
    protected function checkCompany($companyId, $force = false)
    {
        if ($force) {
            return $this->reorderService->checkAllRules($companyId);
        }

        // Only check rules that are due based on their schedule
        $rules = \App\Models\AutoReorderRule::where('company_id', $companyId)
            ->where('is_enabled', true)
            ->get();

        $results = [
            'checked' => 0,
            'triggered' => 0,
            'orders_created' => 0,
            'errors' => [],
        ];

        foreach ($rules as $rule) {
            if ($this->reorderService->shouldRunRule($rule)) {
                try {
                    $rule->updateLastChecked();
                    $results['checked']++;

                    $triggered = $this->reorderService->evaluateRule($rule);
                    
                    if ($triggered) {
                        $results['triggered']++;
                        $results['orders_created']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->rule_name,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Display results to console
     */
    protected function displayResults($results)
    {
        $this->line('');
        $this->line('═══════════════════════════════════');
        $this->info('       Auto Reorder Results');
        $this->line('═══════════════════════════════════');
        $this->line("Rules Checked:      {$results['checked']}");
        $this->line("Rules Triggered:    {$results['triggered']}");
        $this->line("Orders Created:     {$results['orders_created']}");
        
        if (count($results['errors']) > 0) {
            $this->line("Errors:             " . count($results['errors']));
            $this->line('');
            $this->error('Errors encountered:');
            foreach ($results['errors'] as $error) {
                $this->error("  - {$error['rule_name']}: {$error['error']}");
            }
        } else {
            $this->line("Errors:             0");
        }
        
        $this->line('═══════════════════════════════════');
        $this->line('');
    }
}
