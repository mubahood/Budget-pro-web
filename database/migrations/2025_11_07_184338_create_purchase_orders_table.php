<?php

use App\Models\Company;
use App\Models\User;
use App\Models\FinancialPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignIdFor(Company::class);
            $table->foreignIdFor(User::class, 'created_by_id');
            $table->foreignIdFor(FinancialPeriod::class)->nullable();
            
            // PO Details
            $table->string('po_number')->unique();
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            
            // Supplier Information  
            $table->string('supplier_name');
            $table->string('supplier_email')->nullable();
            $table->string('supplier_phone')->nullable();
            $table->text('supplier_address')->nullable();
            
            // PO Items (stored as JSON for flexibility)
            $table->json('items'); // [{product_id, product_name, quantity, unit_price, total}]
            
            // Financial
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            
            // Status & Workflow
            $table->enum('status', [
                'draft', 
                'pending_approval', 
                'approved', 
                'rejected', 
                'sent_to_supplier', 
                'partially_received', 
                'fully_received', 
                'cancelled'
            ])->default('draft');
            
                        // Approval tracking
            $table->foreignIdFor(User::class, 'approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Receiving
            $table->integer('items_ordered')->default(0);
            $table->integer('items_received')->default(0);
            $table->decimal('received_percentage', 5, 2)->default(0);
            
            // Additional Info
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('payment_terms')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('company_id');
            $table->index('po_number');
            $table->index('status');
            $table->index('po_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
