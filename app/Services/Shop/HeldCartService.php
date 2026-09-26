<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\HeldCart;
use Illuminate\Support\Facades\DB;

/**
 * Held carts kept on the server (SUPERMARKET_PLAN.md A6): a cashier parks a sale, and any lane of
 * the shop can resume it. Resuming takes the cart (it is removed), so two lanes can never both sell
 * it. A cart held for more than a day is flagged as stale.
 */
class HeldCartService
{
    /** How many carts a shop may keep held at once. */
    public const MAX = 50;

    public const STALE_HOURS = 24;

    /**
     * @param  array<string, mixed>  $state  the till's cart (lines, customer, discount, notes)
     */
    public function hold(int $companyId, int $userId, array $state, string $label, ?int $locationId = null, ?int $customerId = null, int $lineCount = 0, float $total = 0): HeldCart
    {
        if (($state['lines'] ?? []) === []) {
            throw BusinessRuleException::make('empty_cart', 'The cart is empty: there is nothing to hold.');
        }
        if (HeldCart::query()->where('company_id', $companyId)->count() >= self::MAX) {
            throw BusinessRuleException::make('too_many_held', 'This shop already has '.self::MAX.' held sales. Resume or discard some first.');
        }

        return HeldCart::create([
            'company_id' => $companyId, 'user_id' => $userId, 'location_id' => $locationId, 'customer_id' => $customerId,
            'label' => mb_substr(trim($label) !== '' ? trim($label) : 'Walk-in', 0, 191), 'lines' => $state,
            'line_count' => $lineCount, 'total' => round($total, 2),
        ]);
    }

    /**
     * The shop's held carts, newest first, with who held them and whether they are stale.
     *
     * @return list<array{id: int, label: string, count: int, total: float, at: string, who: ?string, stale: bool}>
     */
    public function list(int $companyId): array
    {
        $staleBefore = now()->subHours(self::STALE_HOURS);

        return DB::table('held_carts as h')->leftJoin('admin_users as u', 'u.id', '=', 'h.user_id')
            ->where('h.company_id', $companyId)->orderByDesc('h.id')->limit(self::MAX)
            ->get(['h.id', 'h.label', 'h.line_count', 'h.total', 'h.created_at', 'u.name as who'])
            ->map(fn ($h) => ['id' => (int) $h->id, 'label' => (string) $h->label, 'count' => (int) $h->line_count, 'total' => (float) $h->total,
                'at' => \Illuminate\Support\Carbon::parse($h->created_at)->toIso8601String(), 'who' => $h->who, 'stale' => $h->created_at < $staleBefore])
            ->all();
    }

    /** Take a held cart back (it leaves the list). @return array<string, mixed> its state */
    public function take(int $companyId, int $id): array
    {
        return DB::transaction(function () use ($companyId, $id) {
            $cart = HeldCart::query()->where('company_id', $companyId)->lockForUpdate()->find($id);
            if ($cart === null) {
                throw BusinessRuleException::make('held_cart_gone', 'That held sale was already resumed or discarded at another till.');
            }
            $state = (array) $cart->lines;
            $cart->delete();

            return $state;
        });
    }

    public function discard(int $companyId, int $id): void
    {
        HeldCart::query()->where('company_id', $companyId)->whereKey($id)->delete();
    }
}
