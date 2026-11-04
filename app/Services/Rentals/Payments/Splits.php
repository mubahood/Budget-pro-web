<?php

namespace App\Services\Rentals\Payments;

use App\Support\Rentals\Minor;

/**
 * How money paid on one bill splits (docs/estate-ledger/04-architecture.md §5.3). Pure.
 *
 * Commission is proportional on a part payment and exact once the bill is fully paid: the commission due after a
 * payment is round(total × paid ÷ amount), and each payment carries the difference, so the parts always sum to the
 * total. The rest of a landlord's bill is the landlord's; a bill that goes to the agency is the agency's; a deposit
 * is held for the tenant by the agency, or passed to the landlord when they hold deposits.
 */
final class Splits
{
    /** The whole commission on a bill of $amount. */
    public static function total(int $amount, string $type, int $bp, int $flat): int
    {
        return match ($type) {
            'percent' => min($amount, Minor::percent($amount, $bp)),
            'flat' => min($amount, max(0, $flat)),
            default => 0,
        };
    }

    /**
     * The split of $a paid on a bill that already had $paidBefore paid.
     *
     * @param  array{amount_minor: int, goes_to: string, commission_type: string, commission_bp: int, commission_minor: int}  $charge
     * $commissionSoFar and $waived (what the bill's allocations already hold): given them, the commission is what is
     * due now less what was taken, so a reversal and a new payment in between still end on the exact total. A forgiven
     * part carries no commission.
     *
     * @return array{commission: int, landlord: int, agency: int, deposit: int}
     */
    public static function split(array $charge, int $a, int $paidBefore, string $depositHeldBy, ?int $commissionSoFar = null, int $waived = 0): array
    {
        $amount = (int) $charge['amount_minor'];
        $out = ['commission' => 0, 'landlord' => 0, 'agency' => 0, 'deposit' => 0];
        switch ($charge['goes_to']) {
            case 'agency':
                $out['agency'] = $a;
                break;
            case 'deposit':
                $out[$depositHeldBy === 'landlord' ? 'landlord' : 'deposit'] = $a;
                break;
            case 'tenant':
                break; // a refund: the money goes back to the tenant, nobody earns it
            default:
                $total = self::total($amount, (string) $charge['commission_type'], (int) $charge['commission_bp'], (int) $charge['commission_minor']);
                $c = $total === 0 ? 0 : self::target($total, $paidBefore + $a, $amount) - self::target($total, $paidBefore, $amount);
                if ($total !== 0 && $commissionSoFar !== null) {
                    $due = self::target($total, $paidBefore + $a, $amount) - self::target($total, $waived, $amount) - $commissionSoFar;
                    $c = min($a, $paidBefore + $a >= $amount ? $due : max(0, $due));
                }
                $out['commission'] = $c;
                $out['landlord'] = $a - $c;
        }

        return $out;
    }

    /** The commission due once $paid of $amount is paid. */
    private static function target(int $total, int $paid, int $amount): int
    {
        return $paid >= $amount ? $total : Minor::share($total, $paid, $amount);
    }
}
