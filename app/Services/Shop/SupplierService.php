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

    /**
     * The supplier's account, oldest first: deliveries raise what we owe (credit), payments on
     * delivery, later payments and goods sent back lower it (debit), a cash refund from the supplier
     * settles a return. The closing balance equals balance().
     *
     * @return array{entries: array<int, array<string, mixed>>, closing_balance: float}
     */
    public function statement(Supplier $supplier): array
    {
        $entries = [];
        foreach (GoodsReceipt::withoutGlobalScopes()->where('supplier_id', $supplier->id)->get() as $g) {
            $entries[] = ['date' => (string) $g->received_on?->toDateString(), 'type' => 'receipt', 'ref' => $g->number, 'description' => 'Goods received '.$g->number, 'debit' => 0.0, 'credit' => round((float) $g->total_cost, 2)];
            if ((float) $g->amount_paid > 0) {
                $entries[] = ['date' => (string) $g->received_on?->toDateString(), 'type' => 'payment', 'ref' => $g->number, 'description' => 'Paid on delivery', 'debit' => round((float) $g->amount_paid, 2), 'credit' => 0.0];
            }
        }
        foreach (FinancialRecord::withoutGlobalScopes()->where('source_type', 'supplier_payment')->where('source_id', $supplier->id)->get() as $p) {
            $entries[] = ['date' => (string) $p->date?->toDateString(), 'type' => 'payment', 'ref' => $p->receipt, 'description' => 'Payment ('.\App\Models\Payment::label((string) $p->payment_method).')', 'debit' => round((float) $p->amount, 2), 'credit' => 0.0];
        }
        foreach (\App\Models\PurchaseReturn::withoutGlobalScopes()->where('supplier_id', $supplier->id)->get() as $r) {
            $entries[] = ['date' => (string) $r->returned_on->toDateString(), 'type' => 'return', 'ref' => $r->number, 'description' => 'Goods returned'.($r->reason ? ": {$r->reason}" : ''), 'debit' => round((float) $r->total_value, 2), 'credit' => 0.0];
            if ((float) $r->refund_amount > 0) {
                $entries[] = ['date' => (string) $r->returned_on->toDateString(), 'type' => 'refund', 'ref' => $r->number, 'description' => 'Refund received', 'debit' => 0.0, 'credit' => round((float) $r->refund_amount, 2)];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $running = 0.0;
        foreach ($entries as &$e) {
            $running = round($running + $e['credit'] - $e['debit'], 2);
            $e['balance'] = $running;
        }
        unset($e);

        return ['entries' => $entries, 'closing_balance' => $running];
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
            $row->date = \App\Support\LocalDate::today((int) $supplier->company_id);
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
