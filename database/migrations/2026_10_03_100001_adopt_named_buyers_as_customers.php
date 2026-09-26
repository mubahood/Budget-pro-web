<?php

use App\Services\Shop\CustomerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buyers who only exist as a name typed on sales (the phone app and the classic sale form) become
 * customer accounts, with their sales and payments linked (CustomerService::adoptNamedBuyers).
 * Additive: only sales without a customer are touched; created customers carry ADOPTED_NOTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = new CustomerService;
        foreach (DB::table('sale_records')->whereNull('customer_id')->whereNotNull('customer_name')->distinct()->pluck('company_id') as $companyId) {
            $service->adoptNamedBuyers((int) $companyId);
        }
    }

    public function down(): void
    {
        // Undo: unlink the sales and payments of customers this made, then remove those customers.
        $ids = DB::table('customers')->where('notes', CustomerService::ADOPTED_NOTE)->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('sale_records')->whereIn('customer_id', $chunk)->update(['customer_id' => null]);
            DB::table('payments')->whereIn('customer_id', $chunk)->whereNotNull('sale_record_id')->update(['customer_id' => null]);
            DB::table('customers')->whereIn('id', $chunk)->delete();
        }
    }
};
