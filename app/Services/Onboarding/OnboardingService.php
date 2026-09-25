<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Billing\Quotas;
use Illuminate\Support\Facades\DB;

/**
 * Setup wizard (plan C2 / Appendix F, P3-2) and first-run checklist (C4, P3-8).
 * State lives in companies.onboarding_state so the phone and web resume the same place.
 */
class OnboardingService
{
    public function state(Company $company): array
    {
        $s = is_array($company->onboarding_state) ? $company->onboarding_state : [];

        return [
            'step' => $s['step'] ?? 'business',
            'completed_steps' => array_values($s['completed_steps'] ?? ['account']),
            'skipped_steps' => array_values($s['skipped_steps'] ?? []),
            'template_pack' => $s['template_pack'] ?? null,
            'started_at' => $s['started_at'] ?? null,
            'completed_at' => $s['completed_at'] ?? null,
            'dismissed_checklist' => (bool) ($s['dismissed_checklist'] ?? false),
            'step_seconds' => $s['step_seconds'] ?? (object) [],
        ];
    }

    /** Record a finished or skipped step and move to the next one (Appendix F metrics included). */
    public function markStep(Company $company, string $step, bool $skipped = false, ?int $seconds = null, array $extra = []): array
    {
        $steps = config('onboarding.steps');
        if (! in_array($step, $steps, true) && $step !== 'done') {
            throw BusinessRuleException::make('invalid_step', 'Unknown setup step.');
        }
        $s = $this->state($company);
        $s['started_at'] ??= now()->toIso8601String();
        $key = $skipped ? 'skipped_steps' : 'completed_steps';
        $other = $skipped ? 'completed_steps' : 'skipped_steps';
        if ($step !== 'done') {
            $s[$key] = array_values(array_unique([...$s[$key], $step]));
            $s[$other] = array_values(array_diff($s[$other], [$step]));
            $next = $steps[array_search($step, $steps, true) + 1] ?? 'done';
            $s['step'] = $next;
        } else {
            $s['step'] = 'done';
        }
        if ($seconds !== null) {
            $times = (array) $s['step_seconds'];
            $times[$step] = $seconds;
            $s['step_seconds'] = $times;
        }
        if ($s['step'] === 'done' && ! $s['completed_at']) {
            $s['completed_at'] = now()->toIso8601String();
        }
        $company->onboarding_state = array_merge($s, $extra);
        $company->saveQuietly();

        return $this->state($company);
    }

    public function dismissChecklist(Company $company): void
    {
        $company->onboarding_state = array_merge($this->state($company), ['dismissed_checklist' => true]);
        $company->saveQuietly();
    }

    /** Step 2: business name, type, country → currency, timezone, locale, tax; modules from the type. */
    public function saveBusiness(Company $company, array $data): Company
    {
        $country = strtoupper((string) ($data['country'] ?? $company->country ?? 'UG'));
        $preset = config("onboarding.countries.{$country}");
        if ($preset === null) {
            throw BusinessRuleException::make('invalid_country', 'Choose Uganda, Kenya, Tanzania or Rwanda.');
        }
        $type = $data['business_type'] ?? $company->business_type ?? 'other';
        if (! array_key_exists($type, config('onboarding.business_types'))) {
            throw BusinessRuleException::make('invalid_business_type', 'Choose a business type.');
        }
        $company->name = $data['name'] ?? $company->name;
        $company->business_type = $type;
        $company->country = $country;
        $company->currency = $data['currency'] ?? $preset['currency'];
        $company->timezone = $data['timezone'] ?? $preset['timezone'];
        $company->locale = $data['locale'] ?? ($company->locale ?: $preset['locales'][0]);
        $company->tax_rate = array_key_exists('tax_rate', $data) ? $data['tax_rate'] : $company->tax_rate;
        foreach (['logo', 'address', 'phone_number', 'email'] as $f) {
            if (array_key_exists($f, $data)) {
                $company->{$f} = $data[$f];
            }
        }
        if (! empty($data['modules'])) {
            $company->enabled_modules = array_values(array_intersect($data['modules'], array_keys(config('onboarding.modules'))));
        } elseif ($company->enabled_modules === null) {
            $company->enabled_modules = config("onboarding.business_types.{$type}.modules");
        }
        $company->save();

        return $company;
    }

    /** Step 4: how the shop takes money and shares receipts. */
    public function saveMoney(Company $company, array $data): Company
    {
        $methods = array_values(array_intersect($data['payment_methods'] ?? ['cash'], array_keys(config('onboarding.payment_methods'))));
        $momo = array_values(array_intersect($data['momo_providers'] ?? [], array_keys(config('onboarding.countries.'.($company->country ?: 'UG').'.momo', []))));
        $company->payment_methods = ['methods' => $methods ?: ['cash'], 'momo' => $momo, 'opening_float' => (float) ($data['opening_float'] ?? 0)];
        $company->receipt_channels = array_values(array_intersect($data['receipt_channels'] ?? ['whatsapp'], ['whatsapp', 'print', 'sms']));
        if (isset($data['negative_stock_policy'])) {
            $company->negative_stock_policy = $data['negative_stock_policy'];
        }
        if (array_key_exists('tax_rate', $data)) {
            $company->tax_rate = $data['tax_rate'];
        }
        if (array_key_exists('require_shift', $data)) {
            $company->require_shift = (bool) $data['require_shift'];
        }
        $company->save();

        return $company;
    }

    /** Template pack for a business type, priced in the company's currency when we have prices for it. */
    public function templates(Company $company, ?string $type = null): array
    {
        $type ??= $company->business_type ?: 'retail';
        $currency = $company->currency ?: 'UGX';
        $rows = DB::table('product_templates')->where('business_type', $type)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('country')->orWhere('country', $company->country ?: 'UG'))
            ->orderBy('sort_order')->get();
        if ($rows->isEmpty()) {
            $rows = DB::table('product_templates')->where('business_type', $type)->where('is_active', true)->orderBy('sort_order')->get();
        }

        return [
            'business_type' => $type,
            'version' => (int) ($rows->max('pack_version') ?? 1),
            'currency' => $currency,
            'items' => $rows->map(function ($r) use ($currency) {
                $p = json_decode((string) $r->prices, true)[$currency] ?? null;

                return ['key' => (int) $r->id, 'name' => $r->name, 'category' => $r->category, 'sub_category' => $r->sub_category, 'unit' => $r->unit,
                    'selling_price' => $p['sell'] ?? null, 'buying_price' => $p['cost'] ?? null];
            })->values()->all(),
        ];
    }

    /**
     * Step 3: create the ticked template items (with any edited price and opening stock).
     *
     * @param  array<int, array{key: int, selling_price?: numeric, buying_price?: numeric, opening_stock?: numeric}>  $picks
     * @return array{created: int, skipped: array<int, string>}
     */
    public function applyTemplates(Company $company, User $user, array $picks): array
    {
        $templates = DB::table('product_templates')->whereIn('id', array_column($picks, 'key'))->get()->keyBy('id');
        $rows = [];
        foreach ($picks as $pick) {
            $t = $templates[$pick['key']] ?? null;
            if ($t === null) {
                continue;
            }
            $price = json_decode((string) $t->prices, true)[$company->currency ?: 'UGX'] ?? [];
            $rows[] = ['name' => $t->name, 'category' => $t->category, 'sub_category' => $t->sub_category, 'unit' => $t->unit,
                'selling_price' => $pick['selling_price'] ?? $price['sell'] ?? null, 'buying_price' => $pick['buying_price'] ?? $price['cost'] ?? 0,
                'opening_stock' => $pick['opening_stock'] ?? 0, 'barcode' => null, 'sku' => null];
        }
        $r = $this->createProducts($company, $user, $rows);
        $this->markStep($company, 'products', false, null, ['template_pack' => $company->business_type ?: 'retail']);

        return $r;
    }

    /** CSV columns (header row, any order): name, category, sub_category, unit, selling_price, buying_price, opening_stock, barcode, sku */
    public function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $contents), fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            throw BusinessRuleException::make('empty_file', 'The file is empty.');
        }
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(str_replace([' ', '-'], '_', trim($h))), str_getcsv($lines[0], $sep));
        $aliases = ['product' => 'name', 'item' => 'name', 'price' => 'selling_price', 'selling' => 'selling_price', 'cost' => 'buying_price', 'buying' => 'buying_price',
            'cost_price' => 'buying_price', 'quantity' => 'opening_stock', 'qty' => 'opening_stock', 'stock' => 'opening_stock', 'subcategory' => 'sub_category'];
        $header = array_map(fn ($h) => $aliases[$h] ?? $h, $header);
        if (! in_array('name', $header, true) || ! in_array('selling_price', $header, true)) {
            throw BusinessRuleException::make('missing_columns', 'The first row must name the columns; "name" and "selling_price" are required.');
        }
        $rows = [];
        $errors = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = str_getcsv($line, $sep);
            $row = [];
            foreach ($header as $j => $h) {
                $row[$h] = isset($cells[$j]) ? trim($cells[$j]) : null;
            }
            $n = $i + 2;
            $num = fn ($v) => $v === null || $v === '' ? null : (is_numeric(str_replace([',', ' '], '', $v)) ? (float) str_replace([',', ' '], '', $v) : false);
            $sell = $num($row['selling_price'] ?? null);
            $cost = $num($row['buying_price'] ?? null);
            $qty = $num($row['opening_stock'] ?? null);
            if (($row['name'] ?? '') === '') {
                $errors[] = ['row' => $n, 'message' => 'Name is missing.'];

                continue;
            }
            if ($sell === null || $sell === false || $sell < 0 || $cost === false || $qty === false || ($qty ?? 0) < 0) {
                $errors[] = ['row' => $n, 'message' => "Check the numbers for \"{$row['name']}\"."];

                continue;
            }
            $rows[] = ['name' => mb_substr($row['name'], 0, 150), 'category' => $row['category'] ?: 'General', 'sub_category' => ($row['sub_category'] ?? null) ?: ($row['category'] ?: 'General'),
                'unit' => ($row['unit'] ?? null) ?: 'pcs', 'selling_price' => $sell, 'buying_price' => $cost ?? 0, 'opening_stock' => $qty ?? 0,
                'barcode' => ($row['barcode'] ?? null) ?: null, 'sku' => ($row['sku'] ?? null) ?: null];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array{created: int, skipped: array<int, string>} */
    public function createProducts(Company $company, User $user, array $rows): array
    {
        (new Quotas())->assertCanAdd($company, 'products', count($rows));
        app(RegistrationService::class)->ensureDefaultPeriod((int) $company->id);

        return DB::transaction(function () use ($company, $user, $rows) {
            $created = 0;
            $skipped = [];
            $cats = [];
            $subs = [];
            foreach ($rows as $row) {
                if (StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('is_deleted', false)->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->exists()) {
                    $skipped[] = $row['name'];

                    continue;
                }
                $catKey = mb_strtolower($row['category']);
                $cats[$catKey] ??= StockCategory::withoutGlobalScopes()->where('company_id', $company->id)->whereRaw('LOWER(name) = ?', [$catKey])->first()
                    ?? $this->make(new StockCategory(), ['name' => $row['category'], 'company_id' => $company->id, 'status' => 'Active']);
                $subKey = $catKey.'|'.mb_strtolower($row['sub_category']);
                $subs[$subKey] ??= StockSubCategory::withoutGlobalScopes()->where('company_id', $company->id)->where('stock_category_id', $cats[$catKey]->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($row['sub_category'])])->first()
                    ?? $this->make(new StockSubCategory(), ['name' => $row['sub_category'], 'company_id' => $company->id, 'stock_category_id' => $cats[$catKey]->id, 'measurement_unit' => $row['unit'], 'status' => 'Active']);
                $item = new StockItem();
                $item->company_id = $company->id;
                $item->created_by_id = $user->id;
                $item->stock_sub_category_id = $subs[$subKey]->id;
                $item->name = $row['name'];
                $item->selling_price = (float) $row['selling_price'];
                $item->buying_price = (float) $row['buying_price'];
                $item->original_quantity = (float) $row['opening_stock'];
                $item->barcode = $row['barcode'];
                $item->sku = $row['sku'];
                $item->save();
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private function make($model, array $attrs)
    {
        foreach ($attrs as $k => $v) {
            $model->{$k} = $v;
        }
        $model->save();

        return $model;
    }

    /** Getting-started checklist (plan C4): five steps with progress. */
    public function checklist(Company $company): array
    {
        $cid = $company->id;
        $methods = $company->payment_methods['methods'] ?? [];
        $items = [
            ['key' => 'add_products', 'label' => 'Add your products', 'done' => DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', false)->exists()],
            ['key' => 'first_sale', 'label' => 'Make your first sale', 'done' => DB::table('sale_records')->where('company_id', $cid)->exists()],
            ['key' => 'invite_staff', 'label' => 'Invite a team member', 'done' => DB::table('admin_users')->where('company_id', $cid)->count() > 1 || DB::table('invites')->where('company_id', $cid)->exists()],
            ['key' => 'set_up_momo', 'label' => 'Set up mobile money', 'done' => in_array('mobile_money', $methods, true)],
            ['key' => 'whatsapp_receipts', 'label' => 'Send receipts on WhatsApp', 'done' => in_array('whatsapp', $company->receipt_channels ?? [], true)],
        ];
        $done = count(array_filter($items, fn ($i) => $i['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'percent' => (int) round($done * 100 / count($items)),
            'dismissed' => $this->state($company)['dismissed_checklist']];
    }

    /** Web shows /setup until the checklist reaches the threshold (plan C2), unless finished or dismissed. */
    public function needsSetup(Company $company): bool
    {
        $s = $this->state($company);
        if ($s['completed_at'] || $s['dismissed_checklist']) {
            return false;
        }

        return $this->checklist($company)['percent'] < (int) config('onboarding.setup_until_percent', 60);
    }
}
