<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\Payment;
use App\Models\SaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Records payments and posts the matching Income ledger row (P0-7 / G5).
 * A credit sale posts nothing until money arrives; a void/refund posts a
 * contra row that references the same source.
 */
class PaymentService
{
    /**
     * @param  array{method?:string, amount:float|string, provider?:string|null, reference?:string|null, received_at?:mixed,
     *               received_by_id?:int|null, client_uuid?:string|null, notes?:string|null, currency?:string|null}  $attrs
     */
    public function record(SaleRecord $sale, array $attrs): Payment
    {
        $amount = round((float) $attrs['amount'], 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Payment amount must be greater than zero.');
        }

        if (! empty($attrs['client_uuid'])) {
            $existing = Payment::withoutGlobalScopes()->where('company_id', $sale->company_id)->where('client_uuid', $attrs['client_uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($sale, $attrs, $amount) {
            $method = Payment::normalizeMethod($attrs['method'] ?? $sale->payment_method);
            $receivedAt = isset($attrs['received_at']) ? \Illuminate\Support\Carbon::parse($attrs['received_at']) : now();

            $payment = new Payment();
            $payment->client_uuid = $attrs['client_uuid'] ?? null;
            $payment->company_id = $sale->company_id;
            $payment->sale_record_id = $sale->id;
            $payment->method = $method;
            $payment->provider = $attrs['provider'] ?? null;
            $payment->reference = $attrs['reference'] ?? null;
            $payment->amount = $amount;
            $payment->currency = $attrs['currency'] ?? $sale->currency ?? Company::withoutGlobalScopes()->find($sale->company_id)?->currency;
            $payment->received_at = $receivedAt;
            $payment->received_by_id = $attrs['received_by_id'] ?? $sale->created_by_id;
            $payment->notes = $attrs['notes'] ?? null;
            $payment->save();

            $ledger = $this->postIncome($sale, $payment);
            $payment->financial_record_id = $ledger->id;
            $payment->saveQuietlySynced();

            $this->syncSaleTotals($sale);

            return $payment;
        });
    }

    /** Contra payment + contra ledger row. */
    public function reverse(Payment $payment, ?string $reason = null, ?int $userId = null): Payment
    {
        if ($payment->is_reversal) {
            throw BusinessRuleException::make('already_reversal', 'A reversal cannot itself be reversed.');
        }
        $already = Payment::withoutGlobalScopes()->where('reverses_id', $payment->id)->first();
        if ($already) {
            return $already;
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            $contra = new Payment();
            $contra->company_id = $payment->company_id;
            $contra->sale_record_id = $payment->sale_record_id;
            $contra->method = $payment->method;
            $contra->provider = $payment->provider;
            $contra->reference = $payment->reference;
            $contra->amount = -1 * (float) $payment->amount;
            $contra->currency = $payment->currency;
            $contra->received_at = now();
            $contra->received_by_id = $userId ?? $payment->received_by_id;
            $contra->is_reversal = true;
            $contra->reverses_id = $payment->id;
            $contra->notes = 'Reversal of payment #'.$payment->id.($reason ? ': '.$reason : '');
            $contra->save();

            if ($payment->financial_record_id) {
                $original = FinancialRecord::withoutGlobalScopes()->find($payment->financial_record_id);
                if ($original) {
                    $ledger = $this->postContra($original, $contra, $reason);
                    $contra->financial_record_id = $ledger->id;
                    $contra->saveQuietlySynced();
                }
            }

            if ($payment->sale_record_id) {
                $sale = SaleRecord::withoutGlobalScopes()->find($payment->sale_record_id);
                if ($sale) {
                    $this->syncSaleTotals($sale);
                }
            }

            return $contra;
        });
    }

    /** amount_paid / balance / payment_status from the payment rows (single source of truth). */
    public function syncSaleTotals(SaleRecord $sale): void
    {
        $paid = round((float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'), 2);
        $total = round((float) $sale->total_amount, 2);

        $sale->amount_paid = min($paid, $total);
        $sale->change_given = max(0, round($paid - $total, 2));
        $sale->balance = max(0, round($total - $paid, 2));
        $sale->payment_status = $sale->balance <= 0 ? 'Paid' : ($sale->amount_paid > 0 ? 'Partial' : 'Unpaid');
        $sale->saveQuietlySynced();
    }

    private function postIncome(SaleRecord $sale, Payment $payment): FinancialRecord
    {
        $category = $this->salesCategory((int) $sale->company_id);

        $row = new FinancialRecord();
        $row->financial_category_id = $category->id;
        $row->company_id = $sale->company_id;
        $row->user_id = $payment->received_by_id;
        $row->created_by_id = $payment->received_by_id;
        $row->amount = $payment->amount;
        $row->quantity = 1;
        $row->type = 'Income';
        $row->payment_method = $payment->method;
        $row->recipient = $sale->customer_name ?? '';
        $row->receipt = $sale->receipt_number ?? '';
        $row->date = $payment->received_at ?? now();
        $row->description = 'Payment for sale '.($sale->receipt_number ?: '#'.$sale->id);
        $row->source_type = 'payment';
        $row->source_id = $payment->id;
        $row->currency = $payment->currency;
        $row->financial_period_id = $sale->financial_period_id;
        $row->save();

        return $row;
    }

    private function postContra(FinancialRecord $original, Payment $contraPayment, ?string $reason): FinancialRecord
    {
        $row = new FinancialRecord();
        $row->financial_category_id = $original->financial_category_id;
        $row->company_id = $original->company_id;
        $row->user_id = $contraPayment->received_by_id;
        $row->created_by_id = $contraPayment->received_by_id;
        $row->amount = -1 * (float) $original->amount;
        $row->quantity = 1;
        $row->type = $original->type;
        $row->payment_method = $original->payment_method;
        $row->recipient = $original->recipient;
        $row->receipt = $original->receipt;
        $row->date = now();
        $row->description = 'Reversal of ledger #'.$original->id.($reason ? ': '.$reason : '');
        $row->source_type = 'payment';
        $row->source_id = $contraPayment->id;
        $row->is_reversal = true;
        $row->reverses_id = $original->id;
        $row->currency = $original->currency;
        $row->financial_period_id = $original->financial_period_id;
        $row->save();

        return $row;
    }

    /**
     * The tenant's "Sales" income category, created on first use. Under parallel checkouts
     * the loser of the insert race re-reads with a locking read: inside REPEATABLE READ a
     * plain SELECT keeps the transaction's snapshot and would not see the winner's row.
     */
    public function salesCategory(int $companyId): FinancialCategory
    {
        $find = fn (bool $locking) => FinancialCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('name', 'Sales')
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->first();

        $category = $find(false);
        if ($category === null) {
            try {
                Company::prepare_account_categories($companyId);
            } catch (\Illuminate\Database\QueryException|BusinessRuleException $e) {
                // A parallel checkout created the default categories a moment ago (unique index) — reuse them.
            }
            $category = $find(true);
        }
        if ($category === null) {
            try {
                $category = new FinancialCategory();
                $category->company_id = $companyId;
                $category->name = 'Sales';
                $category->type = 'Income';
                $category->save();
            } catch (\Illuminate\Database\QueryException|BusinessRuleException $e) {
                $category = $find(true);
                if ($category === null) {
                    throw $e;
                }
            }
        }

        return $category;
    }
}
