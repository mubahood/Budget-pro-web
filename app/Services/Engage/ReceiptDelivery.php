<?php

namespace App\Services\Engage;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaleRecord;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * WhatsApp-native receipts (plan Part E1): the customer gets the receipt on
 * WhatsApp (approved template, SMS fallback) with a link to the full receipt.
 * Sent automatically when the shop chose WhatsApp receipts and its plan has them.
 */
class ReceiptDelivery
{
    public function link(SaleRecord $sale): string
    {
        if (! $sale->receipt_token) {
            $sale->receipt_token = Str::random(32);
            $sale->saveQuietly();
        }

        return rtrim((string) config('app.url'), '/').'/r/'.$sale->receipt_token;
    }

    public function allowed(Company $company): bool
    {
        return (new Quotas())->featureOn($company, 'whatsapp_receipts');
    }

    /** @return int message_log id */
    public function send(SaleRecord $sale, ?string $phone = null): int
    {
        $company = Company::withoutGlobalScopes()->findOrFail($sale->company_id);
        if (! $this->allowed($company)) {
            throw BusinessRuleException::make('feature_not_in_plan', 'Sending receipts automatically is part of a paid plan. You can still share the receipt from the phone.', ['feature' => 'whatsapp_receipts']);
        }
        $to = Phone::e164((string) ($phone ?: $sale->customer_phone ?: $sale->customer?->phone), $company->country ?? 'UG');
        if ($to === null) {
            throw BusinessRuleException::make('invalid_phone', 'Enter the customer\'s phone number.');
        }
        $number = $sale->receipt_number ?: ('#'.$sale->id);
        $total = Money::format($sale->total_amount, 0, (int) $company->id);
        $link = $this->link($sale);
        $text = "Thank you for shopping at {$company->name}!\nReceipt {$number}: {$total}".((float) $sale->balance > 0 ? ' (balance '.Money::format($sale->balance, 0, (int) $company->id).')' : '')."\n{$link}";
        $id = app(Messenger::class)->send($to, $text, ['whatsapp', 'sms'], ['company_id' => $company->id, 'purpose' => 'receipt', 'options' => [
            'whatsapp' => ['template' => config('messaging.whatsapp.meta.receipt_template', 'sale_receipt'), 'params' => [$company->name, $number, $total, $link]],
        ]]);
        $sale->receipt_sent_at = now();
        if (! $sale->customer_phone) {
            $sale->customer_phone = $to;
        }
        $sale->saveQuietly();

        return $id;
    }

    /** After a sale: send when the shop chose WhatsApp receipts, the plan allows and we know the phone. Never fails the sale. */
    public function auto(SaleRecord $sale): void
    {
        try {
            $company = Company::withoutGlobalScopes()->find($sale->company_id);
            $phone = $sale->customer_phone ?: $sale->customer?->phone;
            if ($company && $phone && in_array('whatsapp', $company->receipt_channels ?? [], true) && $this->allowed($company) && $sale->receipt_sent_at === null) {
                $this->send($sale, $phone);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('[receipt] not sent automatically: '.$e->getMessage());
        }
    }
}
