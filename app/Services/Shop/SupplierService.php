<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payables (plan A5, P2-4 basic): balance = unpaid goods receipts −
 * supplier payments; payments post to the ledger as Purchase expenses.
 */
class SupplierService
{
    public function balance(Supplier $supplier): float
    {
        $received = GoodsReceipt::withoutGlobalScopes()->where('company_id', $supplier->company_id)->where('supplier_id', $supplier->id);
        $unpaid = (float) (clone $received)->sum('total_cost') - (float) (clone $received)->sum('amount_paid');
        $paid = (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $supplier->company_id)
            ->where('source_type', 'supplier_payment')->where('source_id', $supplier->id)->sum('amount');
        // Goods sent back lower what we owe; a cash refund from the supplier settles that credit.
        $returns = \App\Models\PurchaseReturn::withoutGlobalScopes()->where('company_id', $supplier->company_id)->where('supplier_id', $supplier->id);
        $returned = (float) (clone $returns)->sum('total_value') - (float) (clone $returns)->sum('refund_amount');

        return round($unpaid - $paid - $returned, 2);
    }

    public function recalc(int $supplierId): ?Supplier
    {
        $s = Supplier::withoutGlobalScopes()->find($supplierId);
        if ($s === null) {
            return null;
        }
        $b = $this->balance($s);
        if (round((float) $s->balance, 2) !== $b) {
            $s->balance = $b;
            $s->saveQuietlySynced();
        }

        return $s;
    }

    public function pay(Supplier $supplier, float $amount, string $method, int $userId, ?string $reference = null): FinancialRecord
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($supplier, $amount, $method, $userId, $reference) {
            $row = new FinancialRecord();
            $row->financial_category_id = self::purchaseCategory((int) $supplier->company_id)->id;
            $row->company_id = $supplier->company_id;
            $row->user_id = $userId;
            $row->created_by_id = $userId;
            $row->amount = $amount;
            $row->quantity = 1;
            $row->type = 'Expense';
            $row->payment_method = \App\Models\Payment::normalizeMethod($method);
            $row->recipient = $supplier->name;
            $row->receipt = $reference ?? '';
            $row->date = now();
            $row->description = 'Payment to supplier '.$supplier->name;
            $row->source_type = 'supplier_payment';
            $row->source_id = $supplier->id;
            $row->currency = Company::withoutGlobalScopes()->find($supplier->company_id)?->currency;
            $row->save();
            $this->recalc($supplier->id);

            return $row;
        });
    }

    public static function purchaseCategory(int $companyId): FinancialCategory
    {
        $c = FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Purchase')->first();
        if ($c === null) {
            try {
                Company::prepare_account_categories($companyId);
            } catch (\Throwable $e) {
                // created concurrently
            }
            $c = FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Purchase')->lockForUpdate()->first();
        }
        if ($c === null) {
            $c = new FinancialCategory();
            $c->company_id = $companyId;
            $c->name = 'Purchase';
            $c->type = 'Expense';
            $c->save();
        }

        return $c;
    }
}
