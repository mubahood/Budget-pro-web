<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaleRecord;
use App\Models\TaxClass;
use App\Support\StoreFeatures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Tax classes per product (supermarket plan F1). Off unless the shop has the `tax_classes` feature
 * (StoreFeatures): then nothing here is used and sales are exactly as before.
 *
 * With it on, SaleService works out each line's tax from its product's class, else the shop's default
 * class, else `companies.tax_rate`, and stores the class, rate and amount on the line. Prices include
 * the tax unless the shop's `tax_inclusive` setting is off: then the tax is added on top and is part of
 * the line total (so payments, balance, rounding and returns carry it without special cases).
 */
class TaxClassService
{
    public static function enabled(Company|int|null $company): bool
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;

        return StoreFeatures::enabled($company, 'tax_classes');
    }

    /** Prices include the tax (default), or it is added on top (US/Canada style). */
    public static function inclusive(?Company $company): bool
    {
        return (bool) (StoreFeatures::setting($company, 'tax_inclusive') ?? true);
    }

    /** The tax in (inclusive) or on top of (exclusive) a net line amount. */
    public static function tax(float $amount, float $rate, bool $inclusive): float
    {
        if ($rate <= 0 || $amount <= 0) {
            return 0.0;
        }

        return $inclusive ? round($amount * $rate / (100 + $rate), 2) : round($amount * $rate / 100, 2);
    }

    /** "18%", "7.5%". */
    public static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }

    // ── Classes ──────────────────────────────────────────────

    /** @return Collection<int, TaxClass> the shop's classes, default first */
    public function list(int $companyId): Collection
    {
        return TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->orderByDesc('is_default')->orderBy('rate', 'desc')->orderBy('name')->get();
    }

    /**
     * The usual three, the first time: "Standard" at the shop's VAT rate (the default), "Zero-rated" 0%
     * and "Exempt". Does nothing when the shop already has classes.
     *
     * @return Collection<int, TaxClass>
     */
    public function ensureDefaults(int $companyId): Collection
    {
        if (! TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            $rate = (float) (Company::withoutGlobalScopes()->whereKey($companyId)->value('tax_rate') ?? 0);
            $now = now();
            foreach ([['Standard', 'standard', $rate, true], ['Zero-rated', 'zero', 0, false], ['Exempt', 'exempt', 0, false]] as [$name, $code, $r, $default]) {
                TaxClass::withoutGlobalScopes()->insert(['company_id' => $companyId, 'name' => $name, 'code' => $code, 'rate' => $r, 'is_default' => $default,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }

        return $this->list($companyId);
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('tax_classes', 'name')->where('company_id', $companyId)->ignore($ignoreId)],
            'code' => ['required', Rule::in(array_keys(TaxClass::CODES))],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** Add a class, or change one ($id). Zero-rated and exempt classes are 0%. */
    public function save(int $companyId, array $attrs, ?int $id = null): TaxClass
    {
        $data = Validator::make($attrs, self::rules($companyId, $id), [], ['rate' => 'rate (%)'])->validate();
        if (in_array($data['code'], ['zero', 'exempt'], true)) {
            $data['rate'] = 0;
        }
        $class = $id !== null ? $this->find($companyId, $id) : new TaxClass(['company_id' => $companyId]);
        $class->forceFill(['company_id' => $companyId, 'name' => trim((string) $data['name']), 'code' => $data['code'], 'rate' => round((float) $data['rate'], 3)]);
        if ($id === null && ! TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->where('is_default', 1)->exists()) {
            $class->is_default = true;
        }
        $class->save();

        return $class;
    }

    /** Products without a class of their own are taxed at the default. */
    public function setDefault(int $companyId, int $id): TaxClass
    {
        $class = $this->find($companyId, $id);
        DB::transaction(function () use ($companyId, $class) {
            TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->where('id', '<>', $class->id)->update(['is_default' => false]);
            $class->forceFill(['is_default' => true])->save();
        });

        return $class;
    }

    public function inUse(int $companyId, int $id): bool
    {
        return DB::table('stock_items')->where('company_id', $companyId)->where('tax_class_id', $id)->where('is_deleted', 0)->exists()
            || DB::table('sale_record_items')->where('company_id', $companyId)->where('tax_class_id', $id)->exists();
    }

    /** Only a class no product and no sale uses, and not the default. */
    public function delete(int $companyId, int $id): void
    {
        $class = $this->find($companyId, $id);
        if ($class->is_default) {
            throw BusinessRuleException::make('tax_class_default', 'This is the default class. Make another class the default first.');
        }
        if ($this->inUse($companyId, $id)) {
            throw BusinessRuleException::make('tax_class_in_use', 'Products or sales use this class, so it cannot be deleted. Move those products to another class first.');
        }
        $class->delete();
    }

    private function find(int $companyId, int $id): TaxClass
    {
        $class = TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->find($id);
        if ($class === null) {
            throw BusinessRuleException::make('tax_class_not_found', 'That tax class was not found.');
        }

        return $class;
    }

    // ── Rates ────────────────────────────────────────────────

    /**
     * Everything needed to tax a sale, read once: the classes, the default and the shop's own rate.
     *
     * @return array{classes: array<int, array{id: int, rate: float}>, default: array{id: ?int, rate: float}}
     */
    public function rates(int $companyId): array
    {
        $classes = [];
        $default = null;
        foreach (TaxClass::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'rate', 'is_default']) as $c) {
            $classes[(int) $c->id] = ['id' => (int) $c->id, 'rate' => (float) $c->rate];
            if ($c->is_default && $default === null) {
                $default = $classes[(int) $c->id];
            }
        }
        $default ??= ['id' => null, 'rate' => (float) (Company::withoutGlobalScopes()->whereKey($companyId)->value('tax_rate') ?? 0)];

        return ['classes' => $classes, 'default' => $default];
    }

    /** The product's class, else the default class, else the shop's rate. @return array{id: ?int, rate: float} */
    public static function classFor(array $rates, mixed $taxClassId): array
    {
        return $taxClassId && isset($rates['classes'][(int) $taxClassId]) ? $rates['classes'][(int) $taxClassId] : $rates['default'];
    }

    /**
     * A cart's tax, worked out exactly as SaleService::finalize does (the header discount shared pro-rata,
     * the last line taking the rounding), so the till shows the total the sale will have.
     *
     * @param  list<array{net: float, rate: float}>  $lines  net = the line after its own discount
     * @return array{tax: float, lines: list<float>}
     */
    public static function cart(array $lines, float $headerDiscount, bool $inclusive): array
    {
        $net = round(array_sum(array_map(fn ($l) => (float) $l['net'], $lines)), 2);
        $header = min(round($headerDiscount, 2), $net);
        $allocated = 0.0;
        $count = count($lines);
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $amount = (float) $l['net'];
            if ($header > 0 && $net > 0) {
                $share = $i === $count - 1 ? round($header - $allocated, 2) : round($header * ($amount / $net), 2);
                $allocated += $share;
                $amount = round($amount - $share, 2);
            }
            $out[] = self::tax($amount, (float) $l['rate'], $inclusive);
        }

        return ['tax' => round(array_sum($out), 2), 'lines' => $out];
    }

    // ── A recorded sale ─────────────────────────────────────

    /** Was the tax added on top of the prices on this sale? (Its total is then subtotal − discounts + tax.) */
    public static function addedOnTop(SaleRecord $sale): bool
    {
        $tax = round((float) $sale->saleRecordItems->sum(fn ($l) => (float) $l->tax_amount), 2);
        if ($tax <= 0) {
            return false;
        }
        $withoutTax = round((float) $sale->subtotal - (float) $sale->discount_amount + (float) ($sale->rounding_amount ?? 0), 2);

        return abs($withoutTax + $tax - (float) $sale->total_amount) < 0.015;
    }

    /**
     * Tax per class on a sale, for receipts: empty for a sale taxed before (or without) tax classes.
     *
     * @return array{on_top: bool, total: float, rows: list<array{label: string, rate: float, net: float, tax: float}>}
     */
    public static function breakdown(SaleRecord $sale): array
    {
        $lines = $sale->saleRecordItems->filter(fn ($l) => $l->tax_rate !== null);
        if ($lines->isEmpty()) {
            return ['on_top' => false, 'total' => 0.0, 'rows' => []];
        }
        $onTop = self::addedOnTop($sale);
        $names = TaxClass::withoutGlobalScopes()->where('company_id', $sale->company_id)->whereIn('id', $lines->pluck('tax_class_id')->filter()->unique()->all())
            ->get()->keyBy('id');
        $rows = [];
        foreach ($lines as $l) {
            $rate = (float) $l->tax_rate;
            $key = ($l->tax_class_id ?: 0).'@'.$rate;
            $class = $l->tax_class_id ? $names->get($l->tax_class_id) : null;
            $label = $class ? ($class->code === 'exempt' ? $class->name : $class->name.' '.self::pct($rate)) : 'VAT '.self::pct($rate);
            $tax = (float) $l->tax_amount;
            $gross = (float) $l->line_total;
            $rows[$key] ??= ['label' => $label, 'rate' => $rate, 'net' => 0.0, 'tax' => 0.0];
            $rows[$key]['net'] = round($rows[$key]['net'] + $gross - $tax, 2);
            $rows[$key]['tax'] = round($rows[$key]['tax'] + $tax, 2);
        }
        usort($rows, fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return ['on_top' => $onTop, 'total' => round(array_sum(array_column($rows, 'tax')), 2), 'rows' => array_values($rows)];
    }
}
