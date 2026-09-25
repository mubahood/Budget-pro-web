<?php

namespace App\Services\Engage;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Messaging\Messenger;
use App\Services\Notifications\Notices;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Debt book reminders (plan Part E2, "Ebbanja / Deni"): customers with an
 * overdue balance get a friendly WhatsApp/SMS, at most once a week, when the
 * shop turned reminders on; the owner can also send one by hand.
 */
class DebtReminders
{
    public const EVERY_DAYS = 7;

    /** @return array{owed: float, overdue: float, oldest_due: ?string} */
    public function position(Customer $customer, Company $company): array
    {
        $terms = (int) ($customer->payment_terms_days ?? $company->credit_terms_days ?? 30);
        $open = DB::table('sale_records')->where('company_id', $company->id)->where('customer_id', $customer->id)->where('status', '!=', 'Voided')->where('balance', '>', 0)
            ->get(['balance', 'sale_date', 'due_date']);
        $overdue = 0.0;
        $oldest = null;
        foreach ($open as $s) {
            $due = $s->due_date ?: \Illuminate\Support\Carbon::parse($s->sale_date)->addDays($terms)->toDateString();
            if ($due < now()->toDateString()) {
                $overdue += (float) $s->balance;
                $oldest = $oldest === null || $due < $oldest ? $due : $oldest;
            }
        }

        return ['owed' => round((float) $open->sum('balance'), 2), 'overdue' => round($overdue, 2), 'oldest_due' => $oldest];
    }

    public function message(Customer $customer, Company $company, float $owed): string
    {
        $momo = $company->momo_payout_phone ? " Pay by mobile money to {$company->momo_payout_phone}." : '';

        return "Hello {$customer->name}, a friendly reminder from {$company->name}: your balance is ".Money::format($owed, 0, (int) $company->id).".{$momo} Thank you!";
    }

    public function remind(Customer $customer, bool $manual = true): int
    {
        $company = Company::withoutGlobalScopes()->findOrFail($customer->company_id);
        $pos = $this->position($customer, $company);
        if ($pos['owed'] <= 0) {
            throw BusinessRuleException::make('nothing_owed', "{$customer->name} owes nothing.");
        }
        $to = Phone::e164((string) $customer->phone, $company->country ?? 'UG');
        if ($to === null) {
            throw BusinessRuleException::make('invalid_phone', 'Add a phone number for this customer first.');
        }
        if ($manual && $customer->last_reminded_at && now()->diffInHours($customer->last_reminded_at) < 20) {
            throw BusinessRuleException::make('reminded_recently', 'A reminder was already sent today.');
        }
        $id = app(Messenger::class)->send($to, $this->message($customer, $company, $pos['owed']), ['whatsapp', 'sms'], ['company_id' => $company->id, 'purpose' => 'debt_reminder']);
        $customer->last_reminded_at = now();
        $customer->saveQuietlySynced();

        return $id;
    }

    /** Daily (in the shop's timezone, from saas:hourly): overdue customers of shops that turned reminders on. */
    public function run(): int
    {
        $sent = 0;
        foreach (Company::withoutGlobalScopes()->where('debt_reminders_enabled', true)->get() as $company) {
            $local = now()->setTimezone(\App\Support\LocalTime::timezone($company));
            if ($local->hour < 10) {
                continue;
            }
            $customers = Customer::withoutGlobalScopes()->where('company_id', $company->id)->where('is_deleted', false)->where('reminders_enabled', true)
                ->whereNotNull('phone')->where('phone', '!=', '')->where('balance', '>', 0)->get();
            foreach ($customers as $c) {
                if ($c->last_reminded_at && now()->diffInDays($c->last_reminded_at) < self::EVERY_DAYS) {
                    continue;
                }
                if ($this->position($c, $company)['overdue'] <= 0) {
                    continue;
                }
                $sent += (int) Notices::once((int) $company->id, 'debt_reminder', "c{$c->id}:".$local->format('o-W'), function () use ($c) {
                    try {
                        $this->remind($c, false);
                    } catch (BusinessRuleException) {
                        // phone missing / nothing owed any more
                    }
                });
            }
        }

        return $sent;
    }
}
