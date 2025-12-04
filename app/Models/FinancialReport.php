<?php

namespace App\Models;

use App\Services\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class FinancialReport extends Model
{
    use HasFactory, AuditLogger;
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'period_type',
        'start_date',
        'end_date',
        'currency',
        'file_generated',
        'file',
        'total_income',
        'total_expense',
        'profit',
    ];
    
    //boot
    protected static function boot()
    {
        parent::boot();
        //creating
        static::creating(function ($model) {
            $model = self::prepare($model);
        });
        //updating
        static::updating(function ($model) {
            if ($model->do_generate == 'Yes') {
                $model = self::prepare($model);
                $model->do_generate = 'No';
            }
            return $model;
        });
    }



    //static prepare
    static public function prepare($model)
    {
        $user = User::find($model->user_id);
        if ($user == null) {
            throw new \Exception("Invalid User");
        }
        $company = Company::find($user->company_id);
        if ($company == null) {
            throw new \Exception("Invalid Company");
        }
        $start_date = null;
        $end_date = null;
        $now = Carbon::parse($model->created_at);

        switch ($model->period_type) {
            case 'Today':
                $start_date = $now->copy()->startOfDay();
                $end_date = $now->copy()->endOfDay();
                break;
            case 'Yesterday':
                $start_date = $now->copy()->subDay()->startOfDay();
                $end_date = $now->copy()->endOfDay();
                break;
            case 'Week':
                $start_date = $now->copy()->startOfWeek();
                $end_date = $now->copy()->endOfWeek();
                break;
            case 'Month':
                $start_date = $now->copy()->startOfMonth();
                $end_date = $now->copy()->endOfMonth();
                break;
            case 'Last Week':
                $start_date = $now->copy()->subWeek()->startOfWeek();
                $end_date = $now->copy()->subWeek()->endOfWeek();
                break;
            case 'Last Month':
                $start_date = $now->copy()->subMonth()->startOfMonth();
                $end_date = $now->copy()->subMonth()->endOfMonth();
                break;
            case 'Quarter':
                $start_date = $now->copy()->startOfQuarter();
                $end_date = $now->copy()->endOfQuarter();
                break;
            case 'Last Quarter':
                $start_date = $now->copy()->subQuarter()->startOfQuarter();
                $end_date = $now->copy()->subQuarter()->endOfQuarter();
                break;
            case 'Last 6 Months':
                $start_date = $now->copy()->subMonths(6)->startOfMonth();
                $end_date = $now->copy()->endOfMonth();
                break;
            case 'Last Year':
                $start_date = $now->copy()->subYear()->startOfYear();
                $end_date = $now->copy()->subYear()->endOfYear();
                break;
            case 'Cycle':
                $financial_period = Utils::getActiveFinancialPeriod($user->company_id);
                if ($financial_period == null) {
                    throw new \Exception("Financial Period is not active. Please activate the financial period.");
                }
                $start_date = Carbon::parse($financial_period->start_date);
                $end_date = Carbon::parse($financial_period->end_date);
                break;
            case 'Year':
                $start_date = $now->copy()->startOfYear();
                $end_date = $now->copy()->endOfYear();
                break;
            case 'Custom':
                $start_date = Carbon::parse($model->start_date);
                $end_date = Carbon::parse($model->end_date);
                break;
        }




        $model->start_date = $start_date;
        $model->end_date = $end_date;
        $model->company_id = $user->company_id;
        $model->currency = $company->currency;
        if ($model->type == 'Financial') {
            //total total_income from financial records using proper date field and service layer
            $service = new FinancialReportService();
            $financialData = $service->calculateFinancialData(
                $user->company_id,
                $start_date,
                $end_date
            );
            
            $model->total_income = $financialData['total_income'];
            $model->total_expense = $financialData['total_expense'];
            $model->profit = $financialData['profit'];
        } else if ($model->type == 'Inventory') {
            //Use service layer for accurate inventory calculations with proper joins
            $service = new FinancialReportService();
            $inventoryData = $service->calculateInventoryData(
                $user->company_id,
                $start_date,
                $end_date
            );
            
            $model->inventory_total_buying_price = $inventoryData['inventory_total_buying_price'];
            $model->inventory_total_selling_price = $inventoryData['inventory_total_selling_price'];
            $model->inventory_total_expected_profit = $inventoryData['inventory_total_expected_profit'];
            $model->inventory_total_earned_profit = $inventoryData['inventory_total_earned_profit'];
        }
        
        // Only update do_generate if model already exists
        if ($model->exists && $model->id) {
            $table_name = $model->getTable();
            DB::update("UPDATE $table_name SET do_generate = 'No' WHERE id = ?", [$model->id]);
        }

        $pdf = App::make('dompdf.wrapper');
        $company = Company::find($user->company_id);
        if ($company->logo != null) {
        } else {
            $company->logo = null;
        }
        $pdf->loadHTML(view('reports.financial-report', [
            'data' => $model,
            'company' => $company
        ]));

        $pdf->render();
        $output = $pdf->output();
        $store_file_path = public_path('storage/files/report-' . $model->id . '.pdf');
        file_put_contents($store_file_path, $output);
        $model->file = 'files/report-' . $model->id . '.pdf';
        $model->file_generated = 'Yes';
        return $model;
    }

    //belongs to company
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    //appends 
    protected $appends = ['title'];

    //getter for title
    public function getTitleAttribute()
    {
        $t = $this->type . ' Report';
        $start_date = Carbon::parse($this->start_date);
        $end_date = Carbon::parse($this->end_date);
        $t .= ' for the period ' . $start_date->format('d/m/Y') . ' - ' . $end_date->format('d/m/Y') . '';
        $t .= ' (' . $this->period_type . ')';
        return $t;
    }

    public function finance_accounts()
    {
        $service = new FinancialReportService();
        return $service->getFinanceAccounts(
            $this->company_id,
            $this->start_date,
            $this->end_date
        );
    }

    //finance_records - Fixed to use date field instead of created_at
    public function finance_records()
    {
        $service = new FinancialReportService();
        return $service->getFinanceRecords(
            $this->company_id,
            $this->start_date,
            $this->end_date
        );
    }

    //get_inventory_categories - NEW METHOD for accurate category summaries
    public function get_inventory_categories()
    {
        $service = new FinancialReportService();
        return $service->getInventoryCategories(
            $this->company_id,
            $this->start_date,
            $this->end_date
        );
    }

    //get_inventory_items - Updated to use service layer
    public function get_inventory_items()
    {
        $service = new FinancialReportService();
        return $service->getInventoryProducts(
            $this->company_id,
            $this->start_date,
            $this->end_date
        );
    }
    
    /**
     * Relationship to User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
