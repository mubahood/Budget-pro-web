<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Services\Billing\Quotas;
use Illuminate\Support\Facades\DB;

/**
 * Locations and transfers (plan P4-4, decision H6: a Business-plan feature).
 * A transfer is a Transfer Out at one location and a Transfer In at the other
 * (the product total never changes); batches travel with their expiry dates.
 */
class TransferService
{
    public function createLocation(Company $company, string $name, ?string $address = null): int
    {
        $existing = DB::table('locations')->where('company_id', $company->id)->count();
        if ($existing >= 1 && ! (new Quotas())->featureOn($company, 'multi_location')) {
            throw BusinessRuleException::make('feature_not_in_plan', 'More than one location is part of the Business plan. Upgrade under Plan & billing.', ['feature' => 'multi_location']);
        }
        $max = (new Quotas())->limits($company)['max_locations'] ?? null;
        if ($max !== null && $existing >= (int) $max && (new Quotas())->featureOn($company, 'multi_location') && (int) $max > 1) {
            throw BusinessRuleException::make('plan_limit_reached', "Your plan allows {$max} locations.", ['limit' => 'max_locations', 'max' => (int) $max, 'used' => $existing]);
        }
        if (DB::table('locations')->where('company_id', $company->id)->where('name', trim($name))->exists()) {
            throw BusinessRuleException::make('duplicate_location', 'There is already a location with that name.');
        }
        LocationStock::defaultLocation((int) $company->id);

        return (int) DB::table('locations')->insertGetId(['company_id' => $company->id, 'name' => trim($name), 'address' => $address, 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<int, array{stock_item_id: int, quantity: float|string}>  $lines
     */
    public function transfer(int $companyId, int $userId, int $fromId, int $toId, array $lines, ?string $notes = null): int
    {
        if ($fromId === $toId) {
            throw BusinessRuleException::make('same_location', 'Choose two different locations.');
        }
        LocationStock::assertLocation($companyId, $fromId);
        LocationStock::assertLocation($companyId, $toId);
        if ($lines === []) {
            throw BusinessRuleException::make('empty_transfer', 'Add at least one product.');
        }

        return DB::transaction(function () use ($companyId, $userId, $fromId, $toId, $lines, $notes) {
            $number = NumberSequencer::next($companyId, 'transfer');
            $id = (int) DB::table('stock_transfers')->insertGetId(['company_id' => $companyId, 'number' => $number, 'from_location_id' => $fromId, 'to_location_id' => $toId,
                'notes' => $notes, 'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $names = DB::table('locations')->whereIn('id', [$fromId, $toId])->pluck('name', 'id');
            $stock = new StockService();
            foreach ($lines as $l) {
                $qty = round((float) $l['quantity'], 3);
                $item = StockService::lock((int) $l['stock_item_id']);
                if ((int) $item->company_id !== $companyId || $qty <= 0) {
                    throw BusinessRuleException::make('invalid_line', 'Choose a product and a quantity greater than zero.');
                }
                $have = LocationStock::level($fromId, (int) $item->id);
                if ($have < $qty && ! $item->allow_negative_stock) {
                    throw BusinessRuleException::make('insufficient_stock', "{$names[$fromId]} has only ".rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.')." {$item->name}.",
                        ['stock_item_id' => $item->id, 'available' => $have, 'requested' => $qty]);
                }
                $out = $stock->record(['stock_item_id' => $item->id, 'type' => 'Transfer Out', 'quantity' => $qty, 'location_id' => $fromId, 'created_by_id' => $userId,
                    'description' => "{$number} to {$names[$toId]}", 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'allow_negative' => true]);
                $stock->record(['stock_item_id' => $item->id, 'type' => 'Transfer In', 'quantity' => $qty, 'location_id' => $toId, 'created_by_id' => $userId,
                    'description' => "{$number} from {$names[$fromId]}", 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'batch_in' => LocationStock::taken($out->id)]);
                DB::table('stock_transfer_items')->insert(['company_id' => $companyId, 'stock_transfer_id' => $id, 'stock_item_id' => $item->id, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
    }

    /** On-hand per location for one product (or every product when null). */
    public function levels(int $companyId, ?int $stockItemId = null): array
    {
        return DB::table('stock_levels as l')->join('locations as loc', 'loc.id', '=', 'l.location_id')->join('stock_items as p', 'p.id', '=', 'l.stock_item_id')
            ->where('l.company_id', $companyId)->when($stockItemId, fn ($q) => $q->where('l.stock_item_id', $stockItemId))
            ->orderBy('p.name')->orderBy('loc.name')->get(['l.stock_item_id', 'p.name as product', 'l.location_id', 'loc.name as location', 'l.quantity'])
            ->map(fn ($r) => (array) $r + ['quantity' => (float) $r->quantity])->all();
    }
}
