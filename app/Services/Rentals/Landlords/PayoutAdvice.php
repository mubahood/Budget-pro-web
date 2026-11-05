<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Models\User;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\Reports\RentalDocument;
use App\Support\LocalDate;
use App\Support\Rentals\AmountWords;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/**
 * A payout's advice (LC-10): one page a landlord can keep, from the payout's own data as it was recorded, so it prints the same whatever
 * changes later on the landlord or their balance. A reversed payout says so across the top, with why, every time. A payout older than these
 * figures prints without them. It carries the account's own name only. Who may see it is checked by the caller.
 */
final class PayoutAdvice
{
    /**
     * @return array{number: string, paid_on: string, landlord: string, amount: string, words: string, method: string, paid_to: ?string, reference: ?string, recorded_by: ?string, before: ?array{0: string, 1: string}, after: ?array{0: string, 1: string}, ahead: ?string, cash: bool, reversed: ?array{on: string, reason: string}}
     */
    public static function parts(RentalPayout $p): array
    {
        $cid = (int) $p->company_id;
        $money = fn (int $v) => Minor::format($v, $cid);
        $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($p->landlord_id);
        $who = $p->created_by_id !== null ? User::withoutGlobalScopes()->where('company_id', $cid)->find($p->created_by_id) : null;
        $position = fn (?int $v, string $when) => $v === null ? null : ($v < 0 ? ["Owed back to us {$when}", $money(-$v)] : ["Owed to you {$when}", $money($v)]);

        return ['number' => (string) $p->number, 'paid_on' => $p->paid_on->format('j M Y'), 'landlord' => (string) ($landlord->name ?? ''), 'amount' => $money((int) $p->amount_minor),
            'words' => AmountWords::of((int) $p->amount_minor, $cid), 'method' => CashGuard::LABELS[$p->method] ?? (string) $p->method, 'paid_to' => $p->paid_to_snapshot, 'reference' => $p->reference,
            'recorded_by' => $who !== null ? trim((string) ($who->name ?? '')) : null, 'before' => $position($p->balance_before_minor, 'before'), 'after' => $position($p->balance_after_minor, 'after'),
            'ahead' => (int) $p->advance_minor > 0 ? $money((int) $p->advance_minor) : null, 'cash' => $p->method === 'cash',
            'reversed' => $p->reversed_at !== null ? ['on' => Carbon::parse($p->reversed_at)->setTimezone(LocalDate::timezone($cid))->format('j M Y'), 'reason' => (string) $p->reverse_reason] : null];
    }

    public static function pdf(RentalPayout $p): string
    {
        $cid = (int) $p->company_id;
        $parts = self::parts($p);
        $head = RentalDocument::letterhead($cid);
        $html = view('rentals.payout-advice', ['doc' => ['title' => 'Payout advice', 'head' => $head, 'sub' => [$parts['number'], $parts['paid_on']]], 'a' => $parts])->render();

        return RentalDocument::output($html, 'a4', 'portrait', ['left' => $head['name'].' · Payout advice '.$parts['number'], 'generated' => '']);
    }

    public static function filename(RentalPayout $p): string
    {
        return 'payout-advice-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $p->number).'.pdf';
    }
}
