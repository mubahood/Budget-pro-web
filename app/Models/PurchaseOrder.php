<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes, AuditLogger;
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'company_id',
        'created_by_id',
        'financial_period_id',
        'po_number',
        'po_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'supplier_name',
        'supplier_email',
        'supplier_phone',
        'supplier_address',
        'items',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'discount_amount',
        'total_amount',
        'status',
        'approved_by_id',
        'approved_at',
        'approval_notes',
        'items_ordered',
        'items_received',
        'received_percentage',
        'notes',
        'terms_and_conditions',
        'reference_number',
        'payment_terms',
    ];

    protected $casts = [
        'items' => 'array',
        'po_date' => 'date',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'received_percentage' => 'decimal:2',
    ];

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function financialPeriod()
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    // Accessors
    public function getStatusBadgeAttribute()
    {
        $badges = [
            'draft' => '<span class="badge badge-secondary">Draft</span>',
            'pending_approval' => '<span class="badge badge-warning">Pending Approval</span>',
            'approved' => '<span class="badge badge-success">Approved</span>',
            'rejected' => '<span class="badge badge-danger">Rejected</span>',
            'sent_to_supplier' => '<span class="badge badge-info">Sent to Supplier</span>',
            'partially_received' => '<span class="badge badge-primary">Partially Received</span>',
            'fully_received' => '<span class="badge badge-success">Fully Received</span>',
            'cancelled' => '<span class="badge badge-dark">Cancelled</span>',
        ];
        
        return $badges[$this->status] ?? '<span class="badge badge-secondary">' . ucfirst($this->status) . '</span>';
    }

    public function getItemsCountAttribute()
    {
        return is_array($this->items) ? count($this->items) : 0;
    }

    public function getTotalQuantityAttribute()
    {
        if (!is_array($this->items)) return 0;
        return array_sum(array_column($this->items, 'quantity'));
    }

    // Static method to generate PO number
    public static function generatePONumber($companyId)
    {
        $lastPO = self::where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->first();
        
        if ($lastPO && $lastPO->po_number) {
            // Extract number from PO-YYYY-0001 format
            preg_match('/PO-\d{4}-(\d+)/', $lastPO->po_number, $matches);
            $lastNumber = isset($matches[1]) ? intval($matches[1]) : 0;
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return 'PO-' . date('Y') . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}

