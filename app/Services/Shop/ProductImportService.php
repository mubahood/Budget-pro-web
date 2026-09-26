<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Support\Rules\StockItemRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A product CSV, previewed and then imported (plan §5.3 "Import"). It is the setup import
 * (OnboardingService::parseCsv + createProducts, the same columns and plan limit) with one addition:
 * a row naming a product the shop already has updates its prices and barcode instead of being skipped.
 *
 * Every row is checked with StockItemRules, the rules the product form and the mobile API use.
 * Stock is never changed by an update row: stock moves only through stock movements.
 */
class ProductImportService
{
    /** Columns the template offers, in order (OnboardingService::parseCsv reads them in any order). */
    public const TEMPLATE_COLUMNS = ['name', 'category', 'sub_category', 'unit', 'selling_price', 'buying_price', 'opening_stock', 'barcode', 'sku'];

    public function __construct(private readonly OnboardingService $onboarding = new OnboardingService())
    {
    }

    /** A ready-to-fill CSV with two example rows. */
    public static function template(): string
    {
        return implode(',', self::TEMPLATE_COLUMNS)."\n"
            ."Sugar 1kg,Groceries,Sugar,kg,5500,4800,20,,\n"
            ."Soda 500ml,Drinks,Soft drinks,pcs,2000,1500,48,6001234567890,\n";
    }

    /**
     * What importing this file would do, row by row.
     *
     * @return array{rows: list<array{row: int, status: string, name: string, reasons: list<string>, changes: array<string, array{0: mixed, 1: mixed}>, product_id: ?int, data: ?array}>, counts: array{new: int, update: int, same: int, invalid: int}}
     */
    public function preview(Company $company, string $contents, bool $canSetCost = true): array
    {
        $columns = $this->columns($contents);
        if ($columns !== [''] && ! in_array('category', $columns, true)) {
            // parseCsv reads $row['category'] unguarded; an empty column gives every row "General".
            $contents = preg_replace('/^(\xEF\xBB\xBF)?([^\r\n]*)/', '$2'.$this->separator($contents).'category', $contents, 1);
            $columns[] = 'category';
        }
        $parsed = $this->onboarding->parseCsv($contents);
        $cid = (int) $company->id;
        $errors = collect($parsed['errors'])->keyBy('row');
        $valid = $parsed['rows'];
        $total = count($valid) + count($parsed['errors']);

        $out = [];
        $seen = ['name' => [], 'barcode' => [], 'sku' => []];
        $k = 0;
        for ($n = 2; $n <= $total + 1; $n++) {
            if ($errors->has($n)) {
                $out[] = ['row' => $n, 'status' => 'invalid', 'name' => '', 'reasons' => [$errors[$n]['message']], 'changes' => [], 'product_id' => null, 'data' => null];

                continue;
            }
            $row = $valid[$k++];
            $out[] = $this->classify($cid, $n, $row, $columns, $canSetCost, $seen);
        }

        $counts = ['new' => 0, 'update' => 0, 'same' => 0, 'invalid' => 0];
        foreach ($out as $r) {
            $counts[$r['status']]++;
        }

        return ['rows' => $out, 'counts' => $counts];
    }

    /**
     * Create the new rows (through the setup import, so the plan limit applies) and update the
     * matching ones through the StockItem model. Rows with problems are left out.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(Company $company, User $user, string $contents, bool $canSetCost = true): array
    {
        $preview = $this->preview($company, $contents, $canSetCost);
        $new = array_values(array_map(fn ($r) => $r['data'], array_filter($preview['rows'], fn ($r) => $r['status'] === 'new')));
        $updates = array_values(array_filter($preview['rows'], fn ($r) => $r['status'] === 'update'));
        if ($new === [] && $updates === []) {
            throw BusinessRuleException::make('nothing_to_import', 'Nothing to import: every row is either unchanged or has a problem.');
        }

        return DB::transaction(function () use ($company, $user, $new, $updates, $preview) {
            $created = $new === [] ? 0 : $this->onboarding->createProducts($company, $user, $new)['created'];
            $updated = 0;
            foreach ($updates as $r) {
                $item = StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('is_deleted', 0)->findOrFail($r['product_id']);
                foreach ($r['changes'] as $field => [, $to]) {
                    $item->{$field} = $to;
                }
                $item->save();
                $updated++;
            }
            if ($created > 0) {
                $this->onboarding->markStep($company, 'products');
            }

            return ['created' => $created, 'updated' => $updated, 'skipped' => $preview['counts']['invalid'] + $preview['counts']['same']];
        });
    }

    /** @param array<string, array<string, bool>> $seen */
    private function classify(int $cid, int $n, array $row, array $columns, bool $canSetCost, array &$seen): array
    {
        $base = ['row' => $n, 'name' => $row['name'], 'reasons' => [], 'changes' => [], 'product_id' => null, 'data' => null];
        $lower = mb_strtolower($row['name']);

        // The same product twice in one file: the first row wins.
        $dup = [];
        if (isset($seen['name'][$lower])) {
            $dup[] = 'The same product name appears on an earlier row.';
        }
        foreach (['barcode', 'sku'] as $f) {
            if ($row[$f] !== null && isset($seen[$f][$row[$f]])) {
                $dup[] = ($f === 'sku' ? 'SKU' : 'Barcode')." {$row[$f]} appears on an earlier row.";
            }
        }
        if ($dup !== []) {
            return ['status' => 'invalid', 'reasons' => $dup] + $base;
        }
        $seen['name'][$lower] = true;
        foreach (['barcode', 'sku'] as $f) {
            if ($row[$f] !== null) {
                $seen[$f][$row[$f]] = true;
            }
        }

        $existing = null;
        if ($row['sku'] !== null) {
            $existing = StockItem::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->where('sku', $row['sku'])->first();
        }
        $existing ??= StockItem::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereRaw('LOWER(name) = ?', [$lower])->first();

        // The same rules as the product form and the API.
        $input = ['name' => $row['name'], 'selling_price' => $row['selling_price'], 'buying_price' => $row['buying_price'], 'original_quantity' => $row['opening_stock'],
            'barcode' => $row['barcode'], 'sku' => $row['sku']];
        $rules = array_intersect_key(StockItemRules::rules($cid, $existing?->id, true), $input);
        $v = Validator::make($input, $rules, [], ['original_quantity' => 'opening stock']);
        if ($v->fails()) {
            return ['status' => 'invalid', 'reasons' => $v->errors()->all(), 'product_id' => $existing?->id] + $base;
        }

        if ($existing === null) {
            if (! $canSetCost) {
                $row['buying_price'] = 0;
            }

            return ['status' => 'new', 'data' => $row] + $base;
        }

        $changes = [];
        if ((float) $existing->selling_price !== (float) $row['selling_price']) {
            $changes['selling_price'] = [(float) $existing->selling_price, (float) $row['selling_price']];
        }
        if ($canSetCost && in_array('buying_price', $columns, true) && (float) $existing->buying_price !== (float) $row['buying_price']) {
            $changes['buying_price'] = [(float) $existing->buying_price, (float) $row['buying_price']];
        }
        if ($row['barcode'] !== null && (string) $existing->barcode !== $row['barcode']) {
            $changes['barcode'] = [$existing->barcode, $row['barcode']];
        }
        $notes = in_array('opening_stock', $columns, true) && (float) $row['opening_stock'] > 0
            ? ['Stock is not changed for a product you already have; receive stock to add to it.'] : [];

        return ['status' => $changes === [] ? 'same' : 'update', 'product_id' => (int) $existing->id, 'changes' => $changes, 'reasons' => $notes] + $base;
    }

    /**
     * Which columns the file names, after parseCsv's own aliases ("cost" is buying_price…), so an
     * update never overwrites a buying price the file did not mention.
     *
     * @return list<string>
     */
    private function columns(string $contents): array
    {
        $first = $this->firstLine($contents);
        $sep = $this->separator($contents);
        $aliases = ['cost' => 'buying_price', 'buying' => 'buying_price', 'cost_price' => 'buying_price', 'quantity' => 'opening_stock', 'qty' => 'opening_stock', 'stock' => 'opening_stock'];

        return array_map(function ($h) use ($aliases) {
            $h = strtolower(str_replace([' ', '-'], '_', trim($h)));

            return $aliases[$h] ?? $h;
        }, str_getcsv($first, $sep));
    }

    private function firstLine(string $contents): string
    {
        return strtok(preg_replace('/^\xEF\xBB\xBF/', '', $contents), "\r\n") ?: '';
    }

    /** The same guess parseCsv makes: semicolons when the header has more of them than commas. */
    private function separator(string $contents): string
    {
        $first = $this->firstLine($contents);

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
