<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Budget\ContributionPayments;
use App\Support\Decimal;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ContributionRecord extends Model
{
    use AuditLogger, HasFactory, Syncable;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /**
     * The relationships that should always be loaded.
     */
    protected $with = ['budgetProgram', 'treasurer', 'changedBy'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'not_paid_amount' => 'decimal:2',
        'date' => 'date',
    ];

    /** A paid figure sent with this save, posted as a payment of the difference after it (prepare → finalizer). */
    public ?string $pendingPaid = null;

    //boot
    protected static function boot()
    {
        parent::boot();

        //disable deleting
        static::deleting(function ($model) {
            throw new BusinessRuleException('Deleting is not allowed');
        });

        static::creating(function ($model) {

            $model->name = trim($model->name);
            $withSameName = ContributionRecord::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
            ])->first();
            if ($withSameName) {
                throw new BusinessRuleException('Name already exists');
            }

            $model = self::prepare($model);

            return $model;
        });

        static::updating(function ($model) {
            $model->name = trim($model->name);
            $withSameName = ContributionRecord::where([
                'name' => $model->name,
                'budget_program_id' => $model->budget_program_id,
            ])->where('id', '!=', $model->id)->first();
            if ($withSameName) {
                throw new BusinessRuleException('Name already exists');
            }

            $model = self::prepare($model);

            return $model;
        });

        //updated
        static::updated(function ($model) {
            self::finalizer($model);
        });

        //created
        static::created(function ($model) {
            self::finalizer($model);
        });
    }

    //public static function prepare
    public static function prepare($data)
    {
        // Use auth user as primary source for company_id (most reliable)
        $authUser = auth('admin')->user();
        $loggedUser = null;

        if ($authUser !== null) {
            $loggedUser = $authUser;
        }

        // Validate treasurer exists
        $treasurer = User::find($data->treasurer_id);
        if ($treasurer === null) {
            // Fallback: if no treasurer specified, use auth user
            if ($loggedUser !== null) {
                $data->treasurer_id = $loggedUser->id;
                $treasurer = $loggedUser;
            } else {
                throw new BusinessRuleException('Treasurer/User not found for ID: '.$data->treasurer_id);
            }
        }

        // Set company_id from auth user first, fallback to treasurer
        if ($loggedUser !== null) {
            $data->company_id = $loggedUser->company_id;

            // Validate treasurer belongs to the same company
            if ($treasurer->company_id != $loggedUser->company_id) {
                throw new BusinessRuleException('Treasurer does not belong to your company.');
            }
        } else {
            $data->company_id = $treasurer->company_id;
        }

        // Money is exact decimals (bcmath), never (int)/(float): pledges can carry cents.
        $custom = $data->custom_amount;
        if (Decimal::valid($custom) && Decimal::cmp((string) $custom, '0') > 0) {
            $data->amount = Decimal::of((string) $custom);
        }
        $data->amount = Decimal::valid($data->amount) ? Decimal::max(Decimal::of((string) $data->amount), '0') : '0.00';

        // paid_amount is the sum of the pledge's payments (contribution_payments) and is never set by hand.
        // A paid figure sent with the save (older apps, the classic form, custom_paid_amount, or the
        // "mark as fully paid" shortcut) becomes a payment of the difference once the pledge is saved (finalizer).
        $paid = $data->exists ? (new ContributionPayments())->sum((int) $data->id) : '0.00';
        $wanted = null;
        $customPaid = $data->custom_paid_amount;
        if (Decimal::valid($customPaid) && Decimal::cmp((string) $customPaid, '0') > 0) {
            $wanted = Decimal::of((string) $customPaid);
        } elseif ($data->isDirty('paid_amount') && Decimal::valid($data->paid_amount)) {
            $wanted = Decimal::of((string) $data->paid_amount);
        }
        if ($wanted !== null) {
            $wanted = Decimal::max($wanted, '0');
            if (Decimal::cmp($data->amount, '0') > 0 && Decimal::cmp($wanted, $data->amount) > 0) {
                // As before, the paid figure of a pledge edit never goes past the pledge, but money already
                // recorded beyond it (a payment made offline) is never taken back by that limit.
                $wanted = Decimal::cmp($paid, $data->amount) > 0 ? null : $data->amount;
            }
        } elseif ($data->fully_paid === 'Yes' && $data->isDirty('fully_paid') && Decimal::cmp($data->amount, $paid) > 0) {
            $wanted = $data->amount; // a "mark as fully paid" shortcut that sends only the flag (it only ever adds)
        }
        $data->pendingPaid = $wanted;

        // Until then the stored figures are what the payments say.
        $data->paid_amount = $paid;
        $data->not_paid_amount = Decimal::max(Decimal::sub($data->amount, $paid), '0');
        $data->fully_paid = Decimal::cmp($data->amount, '0') > 0 && Decimal::cmp($paid, $data->amount) >= 0 ? 'Yes' : 'No';

        // The pledger's phone (optional), in E.164 for reminders.
        if ($data->isDirty('phone')) {
            $raw = trim((string) $data->phone);
            if ($raw === '') {
                $data->phone = null;
            } else {
                $country = \App\Support\Phone::countryFor(\App\Support\Money::symbol((int) $data->company_id));
                $e164 = \App\Support\Phone::e164($raw, (string) (DB::table('companies')->where('id', $data->company_id)->value('country') ?: $country));
                if ($e164 === null) {
                    throw BusinessRuleException::make('invalid_phone', 'Enter a valid phone number, or leave it empty.');
                }
                $data->phone = $e164;
            }
        }

        // Track who last modified this record
        if ($loggedUser !== null) {
            $data->chaned_by_id = $loggedUser->id;
        }

        return $data;
    }

    //public function finalizer
    public static function finalizer($data)
    {
        $table_name = (new self)->getTable();
        //sql set custom_paid_amount to null and custom_amount to null
        $sql = "UPDATE $table_name SET custom_paid_amount = NULL, custom_amount = NULL WHERE id = ?";
        DB::update($sql, [$data->id]);

        // A paid figure that came with the save is posted as a payment of the difference; then paid_amount is
        // the sum again, and the event's totals follow (ContributionPayments::recalculate).
        $wanted = $data->pendingPaid;
        $data->pendingPaid = null;
        $payments = new ContributionPayments();
        $by = $data->chaned_by_id ? (int) $data->chaned_by_id : ($data->treasurer_id ? (int) $data->treasurer_id : null);
        if ($wanted !== null) {
            $payments->settleTo((int) $data->company_id, (int) $data->id, $wanted, $by);
        } else {
            $payments->recalculateById((int) $data->company_id, (int) $data->id);
        }

        // The caller's model shows what was stored (paid figures, sync version).
        $stored = DB::table($table_name)->where('id', $data->id)->first(['paid_amount', 'not_paid_amount', 'fully_paid', 'version', 'server_seq', 'client_updated_at']);
        if ($stored !== null) {
            foreach ((array) $stored as $k => $v) {
                $data->setAttribute($k, $v);
            }
            $data->custom_paid_amount = null;
            $data->custom_amount = null;
        }

        return $data;
    }

    //get treasurer initials
    public function tr()
    {
        $treasurer = User::find($this->treasurer_id);
        if ($treasurer == null) {
            return '';
        }
        //first letter of first name
        $first = substr($treasurer->name, 0, 1);

        return strtoupper($first);
    }

    //appends for treasurer_text
    protected $appends = ['treasurer_text', 'chaned_by_text'];

    //getter for treasurer_text
    public function getTreasurerTextAttribute()
    {
        // `treasurer` is already eager-loaded via $with -- a fresh
        // User::find() here re-queried on every serialized record (N+1 on
        // every list/show call).
        if ($this->treasurer == null) {
            return 'N/A';
        }

        return $this->treasurer->name;
    }

    //getter for chaned_by_text
    public function getChanedByTextAttribute()
    {
        if ($this->changedBy == null) {
            return 'N/A';
        }

        return $this->changedBy->name;
    }

    /**
     * Relationships
     */
    public function budgetProgram()
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function treasurer()
    {
        return $this->belongsTo(User::class, 'treasurer_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'chaned_by_id');
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** The payment history, oldest first (reversals included). */
    public function payments()
    {
        return $this->hasMany(ContributionPayment::class, 'contribution_record_id')->orderBy('id');
    }

    /**
     * Query Scopes
     */
    public function scopeByProgram($query, $programId)
    {
        return $query->where('budget_program_id', $programId);
    }

    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    public function scopeFullyPaid($query)
    {
        return $query->where('fully_paid', 'Yes');
    }

    public function scopeNotFullyPaid($query)
    {
        return $query->where('fully_paid', 'No');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date', now()->month)
            ->whereYear('date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('date', now()->year);
    }

    public function scopeByTreasurer($query, $treasurerId)
    {
        return $query->where('treasurer_id', $treasurerId);
    }
}
