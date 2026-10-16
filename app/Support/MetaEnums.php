<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Payment;
use App\Services\Shop\StockService;
use App\Support\Rules\CompanyRules;

/**
 * The words and colours the web portal uses for statuses, so the phone draws them the same way (GET
 * /api/v1/meta → enums, payment_methods). Every list is [{value, label, tone}], tone being one of
 * ok | warn | bad | info | neutral (the web badges' success | warn | danger | info | neutral).
 */
final class MetaEnums
{
    public const TONES = ['ok', 'warn', 'bad', 'info', 'neutral'];

    /** @return array<string, list<array{value: string, label: string, tone: string}>> */
    public static function all(): array
    {
        $list = fn (array $rows) => array_map(fn ($r) => ['value' => $r[0], 'label' => $r[1], 'tone' => $r[2]] + ($r[3] ?? []), $rows);
        $methods = (array) config('onboarding.payment_methods', ['cash' => 'Cash']) + ['cheque' => 'Cheque', 'other' => 'Other'];

        return [
            // budget-pro-new Sales: the status filter's words (STATUSES) and the badge tones (statusBadge).
            'sale_status' => $list([['Completed', 'Completed', 'ok'], ['Partially Refunded', 'Partly returned', 'warn'], ['Refunded', 'Returned', 'bad'], ['Voided', 'Voided', 'neutral']]),
            'payment_status' => $list([['Paid', 'Paid', 'ok'], ['Partial', 'Part paid', 'warn'], ['Unpaid', 'Not paid', 'bad']]),
            'payment_method' => array_merge(
                $list(array_map(fn ($k, $v) => [$k, $v, $k === 'credit' ? 'warn' : 'neutral'], array_keys($methods), $methods)),
                $list(array_map(fn ($k, $v) => [$k, $v, 'info', ['tender' => true]], array_keys(Payment::TENDERS), Payment::TENDERS)),
            ),
            // budget-pro-new Stock movements: sales info, stock coming in ok, going out bad; `direction` in | out.
            'movement_type' => array_merge(
                $list(array_map(fn ($t) => [$t, $t, $t === 'Sale' ? 'info' : 'ok', ['direction' => 'in']], StockService::INBOUND)),
                $list(array_map(fn ($t) => [$t, $t, $t === 'Sale' ? 'info' : 'bad', ['direction' => 'out']], StockService::OUTBOUND)),
            ),
            'stock_state' => $list([['in_stock', 'In stock', 'ok'], ['low', 'Running low', 'warn'], ['out', 'Out of stock', 'bad'], ['oversold', 'Oversold', 'bad']]),
            'shift_status' => $list([['open', 'Open', 'info'], ['closed', 'Closed', 'neutral']]),
            'batch_state' => $list([['expired', 'Expired', 'bad'], ['soon', 'Expires soon', 'warn'], ['ok', 'Fine', 'ok']]),
            'role' => $list(array_map(fn ($k, $r) => [$k, (string) ($r['label'] ?? $k), 'neutral'], array_keys((array) config('permissions.roles')), (array) config('permissions.roles'))),
        ];
    }

    /**
     * The ways this shop takes money, in the configured order with their labels: the shop's own choice,
     * or every method when it chose none (the web till's rule). `pay_later` marks "credit" (a sale on account).
     *
     * @return list<array{value: string, label: string, pay_later: bool}>
     */
    public static function paymentMethods(?Company $company): array
    {
        $all = (array) config('onboarding.payment_methods', ['cash' => 'Cash']);
        $chosen = $company ? CompanyRules::paymentMethods($company) : [];
        $mine = array_intersect_key($all, array_flip($chosen));
        $use = array_diff_key($mine, ['credit' => 1]) !== [] ? $mine : $all;

        return array_values(array_map(fn ($k) => ['value' => (string) $k, 'label' => (string) $use[$k], 'pay_later' => $k === 'credit'], array_keys($use)));
    }
}
