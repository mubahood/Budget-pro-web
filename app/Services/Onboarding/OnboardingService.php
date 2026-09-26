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
    /** Keys this service owns in companies.onboarding_state; other keys already there (e.g. `legacy`) are kept on every write. */
    public const STATE_VERSION = 2;

    public function state(Company $company): array
    {
        $s = $this->raw($company);

        return [
            'step' => $s['step'] ?? 'business',
            'completed_steps' => array_values($s['completed_steps'] ?? ['account']),
            'skipped_steps' => array_values($s['skipped_steps'] ?? []),
            'template_pack' => $s['template_pack'] ?? null,
            'started_at' => $s['started_at'] ?? null,
            'completed_at' => $s['completed_at'] ?? null,
            'dismissed_checklist' => (bool) ($s['dismissed_checklist'] ?? false),
            'step_seconds' => $s['step_seconds'] ?? (object) [],
            'version' => (int) ($s['version'] ?? 1),
            'channel' => $s['channel'] ?? null,
            'tour_seen' => (bool) ($s['tour_seen'] ?? false),
            'app_prompted_at' => $s['app_prompted_at'] ?? null,
            'skipped_at' => $s['skipped_at'] ?? null,
            'legacy' => (bool) ($s['legacy'] ?? false),
        ];
    }

    /** The stored JSON as it is, unknown keys included. */
    private function raw(Company $company): array
    {
        $s = $company->getAttributes()['onboarding_state'] ?? null; // raw JSON, whatever keys it holds
        $s = is_string($s) ? json_decode($s, true) : $company->onboarding_state;

        return is_array($s) ? $s : [];
    }

    /** Merge $patch onto the stored JSON (never dropping keys this code does not know) and save quietly. */
    public function remember(Company $company, array $patch): array
    {
        $company->onboarding_state = array_merge($this->raw($company), $patch, ['version' => self::STATE_VERSION]);
        $company->saveQuietly();
        \Illuminate\Support\Facades\Cache::forget(self::v2Key($company));

        return $this->state($company);
    }

    /** Record a finished or skipped step and move to the next one (Appendix F metrics included). */
    public function markStep(Company $company, string $step, bool $skipped = false, ?int $seconds = null, array $extra = [], ?string $channel = null): array
    {
        $steps = config('onboarding.steps');
        if (! in_array($step, $steps, true) && $step !== 'done') {
            throw BusinessRuleException::make('invalid_step', 'Unknown setup step.');
        }
        $s = $this->state($company);
        $s['started_at'] ??= now()->toIso8601String();
        $s['channel'] ??= $channel ?? OnboardingEvents::channel();
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
        unset($s['legacy'], $s['version']); // stored as they are
        $state = $this->remember($company, array_merge($s, $extra));
        OnboardingEvents::record((int) $company->id, $skipped ? 'step_skipped' : 'step_completed', array_filter(['step' => $step, 'seconds' => $seconds], fn ($v) => $v !== null), $channel);

        return $state;
    }

    /** The owner chose "skip setup" on the web: the wizard stops opening by itself; the checklist stays. */
    public function skipWizard(Company $company, ?string $channel = null): array
    {
        OnboardingEvents::record((int) $company->id, 'wizard_skipped', ['step' => $this->state($company)['step']], $channel);

        return $this->remember($company, ['skipped_at' => now()->toIso8601String()]);
    }

    public function dismissChecklist(Company $company): void
    {
        $this->remember($company, ['dismissed_checklist' => true]);
        OnboardingEvents::record((int) $company->id, 'checklist_dismissed');
    }

    /** Step 2: business name, type, country → currency, timezone, locale, tax; modules from the type. */
    public function saveBusiness(Company $company, array $data): Company
    {
        $country = strtoupper((string) ($data['country'] ?? $company->country ?? 'UG'));
        $preset = config("onboarding.countries.{$country}");
        if ($preset === null) {
            throw BusinessRuleException::make('invalid_country', 'Choose your country from the list.');
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
        $this->seedUnits($company);

        return $company;
    }

    /** Common pack sizes for the business type, once, when the shop has no units yet. */
    public function seedUnits(Company $company): void
    {
        if (DB::table('units')->where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (config('onboarding.default_units.'.($company->business_type ?: 'retail'), []) as [$name, $abbr, $factor]) {
            $unit = new \App\Models\Unit();
            $unit->company_id = $company->id;
            $unit->name = $name;
            $unit->abbreviation = $abbr;
            $unit->factor = $factor;
            $unit->save();
        }
    }

    /** Step 4: how the shop takes money and shares receipts. */
    public function saveMoney(Company $company, array $data): Company
    {
        $methods = array_values(array_intersect($data['payment_methods'] ?? ['cash'], array_keys(config('onboarding.payment_methods'))));
        $momo = array_values(array_intersect($data['momo_providers'] ?? [], array_keys(config('onboarding.countries.'.($company->country ?: 'UG').'.momo', []))));
        $company->payment_methods = ['methods' => $methods ?: ['cash'], 'momo' => $momo, 'opening_float' => (float) ($data['opening_float'] ?? 0)];
        $company->receipt_channels = array_values(array_intersect($data['receipt_channels'] ?? [], ['whatsapp', 'print', 'sms'])); // nothing pre-ticked
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

    /** Older sign-up forms send these names; their pack lives under the current one. */
    public const PACK_ALIASES = ['restaurant' => 'restaurant_bar'];

    /** Template pack for a business type, priced in the company's currency when we have prices for it. */
    public function templates(Company $company, ?string $type = null): array
    {
        $type ??= $company->business_type ?: 'retail';
        $type = self::PACK_ALIASES[$type] ?? $type;
        $currency = $company->currency ?: 'UGX';
        // Products with a country are local ones (East-African brands, dishes, boda parts): only shops in that region see them.
        $country = $company->country ?: 'UG';
        $local = config("onboarding.countries.{$country}.region") === 'ea' ? ['UG', 'KE', 'TZ', 'RW', $country] : [$country];
        $rows = DB::table('product_templates')->where('business_type', $type)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('country')->orWhereIn('country', $local))
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
     * Every ticked item needs a selling price (the pack's price in the shop's currency, or one typed in);
     * items without one are refused together, by name, and nothing is created.
     *
     * @param  array<int, array{key: int, selling_price?: numeric, buying_price?: numeric, opening_stock?: numeric}>  $picks
     * @return array{created: int, skipped: array<int, string>}
     */
    public function applyTemplates(Company $company, User $user, array $picks): array
    {
        $templates = DB::table('product_templates')->whereIn('id', array_column($picks, 'key'))->get()->keyBy('id');
        $rows = [];
        $unpriced = [];
        foreach ($picks as $pick) {
            $t = $templates[$pick['key']] ?? null;
            if ($t === null) {
                continue;
            }
            $price = json_decode((string) $t->prices, true)[$company->currency ?: 'UGX'] ?? [];
            $sell = self::amount($pick['selling_price'] ?? null) ?? self::amount($price['sell'] ?? null);
            if ($sell === null || $sell <= 0) {
                $unpriced[] = $t->name;

                continue;
            }
            $rows[] = ['name' => $t->name, 'category' => $t->category, 'sub_category' => $t->sub_category, 'unit' => $t->unit,
                'selling_price' => $sell, 'buying_price' => self::amount($pick['buying_price'] ?? null) ?? self::amount($price['cost'] ?? null) ?? 0,
                'opening_stock' => self::amount($pick['opening_stock'] ?? null) ?? 0, 'barcode' => null, 'sku' => null];
        }
        if ($unpriced !== []) {
            $list = implode(', ', array_slice($unpriced, 0, 8)).(count($unpriced) > 8 ? ' and '.(count($unpriced) - 8).' more' : '');
            throw BusinessRuleException::make('prices_required', 'Add a selling price for: '.$list.'.', ['items' => $unpriced]);
        }
        $r = $rows === [] ? ['created' => 0, 'skipped' => []] : $this->createProducts($company, $user, $rows);
        if ($r['created'] > 0) {
            // Every pack the picks came from (a shop can mix, e.g. groceries plus airtime), main one first.
            $packs = $templates->only(array_column($picks, 'key'))->pluck('business_type')->unique()->values()->all();
            $pack = implode(',', $packs ?: [$company->business_type ?: 'retail']);
            $this->markStep($company, 'products', false, null, ['template_pack' => $pack]);
            OnboardingEvents::record((int) $company->id, 'template_applied', ['pack' => $pack, 'created' => $r['created'], 'skipped' => count($r['skipped'])]);
        }

        return $r;
    }

    private static function amount(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_string($v) ? str_replace([',', ' '], '', $v) : $v;

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * A product file as CSV text: an Excel .xlsx (first sheet) is converted, a CSV is returned as it is.
     * The API, the classic setup and the new app all import through parseCsv after this.
     */
    public static function fileToCsv(string $contents): string
    {
        return \App\Support\XlsxReader::isXlsx($contents) ? \App\Support\XlsxReader::toCsv($contents) : $contents;
    }

    /** CSV columns (header row, any order): name, category, sub_category, unit, selling_price, buying_price, opening_stock, barcode, sku */
    public function parseCsv(string $contents): array
    {
        $contents = self::fileToCsv($contents);
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
            $rows[] = ['name' => mb_substr($row['name'], 0, 150), 'category' => ($row['category'] ?? null) ?: 'General', 'sub_category' => ($row['sub_category'] ?? null) ?: (($row['category'] ?? null) ?: 'General'),
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
            if ($created > 0) {
                OnboardingEvents::once((int) $company->id, 'first_product', ['count' => $created]);
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

    /**
     * Getting-started checklist (plan C4): five steps with progress. The phone app reads this shape;
     * every item is done only when it really happened (an invite accepted, a MoMo number registered,
     * a receipt sent), not because a box was ticked.
     */
    public function checklist(Company $company): array
    {
        $sig = $this->signals($company);
        $items = [
            ['key' => 'add_products', 'label' => 'Add your products', 'done' => $sig['products']],
            ['key' => 'first_sale', 'label' => 'Make your first sale', 'done' => $sig['first_sale']],
            ['key' => 'invite_staff', 'label' => 'Invite a team member', 'done' => $sig['staff']],
            ['key' => 'set_up_momo', 'label' => 'Set up mobile money', 'done' => $sig['momo']],
            ['key' => 'whatsapp_receipts', 'label' => 'Send receipts on WhatsApp', 'done' => $sig['receipts']],
        ];
        // Mobile money is set up only where the country has it.
        if (! config('onboarding.countries.'.($company->country ?: 'UG').'.momo')) {
            $items = array_values(array_filter($items, fn ($i) => $i['key'] !== 'set_up_momo'));
        }
        $done = count(array_filter($items, fn ($i) => $i['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'percent' => (int) round($done * 100 / count($items)),
            'dismissed' => $this->state($company)['dismissed_checklist']];
    }

    /**
     * Getting-started checklist v2 (POWER_PLAN §3.1): eight items, each done on a real signal.
     * `action` names where the item is done (the web maps it to a screen). Also notes, once, the
     * milestones only visible from the data (first sale, invite accepted, MoMo ready, phone app, paid plan).
     *
     * @return array{items: list<array{key: string, label: string, hint: string, action: string, done: bool}>, done: int, total: int, percent: int, dismissed: bool}
     */
    public function checklistV2(Company $company, bool $noteMilestones = true): array
    {
        $sig = $this->signals($company);
        if ($noteMilestones) {
            $this->noteMilestones($company, $sig);
        }
        $items = [
            ['key' => 'products', 'label' => 'Add your products', 'hint' => 'Load a template pack, import a file or add them one by one.', 'action' => 'products'],
            ['key' => 'prices', 'label' => 'Give every product a price', 'hint' => 'Products without a selling price can\'t be sold.', 'action' => 'prices'],
            ['key' => 'first_sale', 'label' => 'Make your first sale', 'hint' => 'Sell something on the till. It takes a minute.', 'action' => 'pos'],
            ['key' => 'staff', 'label' => 'Get a team member signed in', 'hint' => 'Invite a cashier. It counts once they accept.', 'action' => 'team'],
            ['key' => 'momo', 'label' => 'Receive mobile money', 'hint' => 'Register the number your MoMo payments should reach.', 'action' => 'momo'],
            ['key' => 'receipts', 'label' => 'Send a customer a receipt', 'hint' => 'Send one on WhatsApp or SMS after a sale.', 'action' => 'receipts'],
            ['key' => 'phone_app', 'label' => 'Sign in on the phone app', 'hint' => 'Sell even when the network is down.', 'action' => 'app'],
            ['key' => 'plan', 'label' => 'Choose your plan', 'hint' => 'Pick the plan that fits before the trial ends.', 'action' => 'plan'],
        ];
        $items = array_map(fn (array $i) => $i + ['done' => (bool) $sig[$i['key']]], $items);
        $done = count(array_filter($items, fn ($i) => $i['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'percent' => (int) round($done * 100 / count($items)),
            'dismissed' => $this->state($company)['dismissed_checklist']];
    }

    /** Note the milestones visible in the data (first sale, invite accepted…) — the web calls it after the page has painted. */
    public function syncMilestones(Company $company): void
    {
        $this->noteMilestones($company, $this->signals($company));
    }

    /** checklistV2() (without noting milestones) kept for 60 seconds per shop (dashboard and topbar); any onboarding write clears it. */
    public function checklistV2Cached(Company $company): array
    {
        return \Illuminate\Support\Facades\Cache::remember(self::v2Key($company), 60, fn () => $this->checklistV2($company, false));
    }

    private static function v2Key(Company $company): string
    {
        return 'onboarding:checklist-v2:'.$company->id;
    }

    /** @return array{products: bool, prices: bool, first_sale: bool, staff: bool, momo: bool, receipts: bool, phone_app: bool, plan: bool, paid: bool} */
    public function signals(Company $company): array
    {
        $cid = (int) $company->id;
        // One round trip: every signal is an EXISTS on an indexed company_id.
        $r = DB::selectOne(<<<'SQL'
            SELECT
              EXISTS(SELECT 1 FROM stock_items WHERE company_id = :c1 AND is_deleted = 0) AS products,
              EXISTS(SELECT 1 FROM stock_items WHERE company_id = :c2 AND is_deleted = 0 AND (selling_price IS NULL OR selling_price <= 0)) AS unpriced,
              EXISTS(SELECT 1 FROM sale_records WHERE company_id = :c3) AS first_sale,
              (EXISTS(SELECT 1 FROM invites WHERE company_id = :c4 AND status = 'accepted')
                OR EXISTS(SELECT 1 FROM company_members WHERE company_id = :c5 AND status = 'active' AND role <> 'owner')) AS staff,
              EXISTS(SELECT 1 FROM message_log WHERE company_id = :c6 AND purpose = 'receipt' AND status = 'sent') AS receipts,
              (EXISTS(SELECT 1 FROM devices WHERE company_id = :c7)
                OR EXISTS(SELECT 1 FROM personal_access_tokens t JOIN admin_users u ON u.id = t.tokenable_id
                          WHERE t.tokenable_type = :type AND u.company_id = :c8)) AS phone_app,
              (SELECT CONCAT_WS('|', s.status, COALESCE(s.provider, ''), COALESCE(p.price_ugx, 0))
                 FROM subscriptions s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.company_id = :c9 ORDER BY s.id DESC LIMIT 1) AS sub
            SQL, ['c1' => $cid, 'c2' => $cid, 'c3' => $cid, 'c4' => $cid, 'c5' => $cid, 'c6' => $cid, 'c7' => $cid, 'c8' => $cid, 'c9' => $cid, 'type' => User::class]);
        [$status, $provider, $price] = $r->sub !== null ? array_pad(explode('|', (string) $r->sub), 3, '') : [null, '', 0];

        return [
            'products' => (bool) $r->products,
            'prices' => (bool) $r->products && ! $r->unpriced,
            'first_sale' => (bool) $r->first_sale,
            'staff' => (bool) $r->staff,
            'momo' => ! empty($company->momo_subaccount_id),
            'receipts' => (bool) $r->receipts,
            'phone_app' => (bool) $r->phone_app,
            'plan' => $status !== null && $status !== 'trialing',
            'paid' => in_array($status, ['active', 'past_due'], true) && ! in_array($provider, ['trial', 'free'], true) && (float) $price > 0,
        ];
    }

    /** Milestones only visible in the data, written once each (and the first sale closes the wizard). */
    private function noteMilestones(Company $company, array $sig): void
    {
        if ($company->is_demo) {
            return;
        }
        $cid = (int) $company->id;
        $seen = OnboardingEvents::seen($cid);
        if ($sig['first_sale'] && ! in_array('first_sale', $seen, true)) {
            $this->noteFirstSale($company);
        }
        if (! in_array('invite_sent', $seen, true) && DB::table('invites')->where('company_id', $cid)->exists()) {
            OnboardingEvents::record($cid, 'invite_sent', [], 'system'); // sent from the phone or the team screen
        }
        foreach (['staff' => 'invite_accepted', 'momo' => 'momo_ready', 'phone_app' => 'app_login', 'paid' => 'converted_to_paid'] as $signal => $event) {
            if ($sig[$signal] && ! in_array($event, $seen, true)) {
                OnboardingEvents::record($cid, $event, [], 'system');
            }
        }
    }

    /**
     * The shop has sold: record `first_sale` once (with the time it took from sign-up) and tick the
     * wizard's first-sale step. When the wizard is still at an earlier step the step is only ticked,
     * so the owner can finish the rest. Returns true when this call noted it.
     */
    public function noteFirstSale(Company $company): bool
    {
        $cid = (int) $company->id;
        if (! DB::table('sale_records')->where('company_id', $cid)->exists()) {
            return false;
        }
        $noted = OnboardingEvents::once($cid, 'first_sale', ['seconds_to_first_sale' => OnboardingEvents::timeToFirstSale($cid)]);
        $s = $this->state($company);
        if (! in_array('first_sale', $s['completed_steps'], true)) {
            if (in_array($s['step'], ['first_sale', 'done'], true)) {
                $this->markStep($company, 'first_sale');
            } else {
                $this->remember($company, ['completed_steps' => array_values(array_unique([...$s['completed_steps'], 'first_sale']))]);
            }
        }

        return $noted;
    }

    /**
     * Web shows the setup wizard until the checklist reaches the threshold (plan C2), unless setup was
     * finished, the checklist dismissed or the wizard skipped. Demo shops never need setup.
     */
    public function needsSetup(Company $company): bool
    {
        $s = $this->state($company);
        if ($s['completed_at'] || $s['dismissed_checklist'] || $s['skipped_at'] || $company->is_demo) {
            return false;
        }

        return $this->checklist($company)['percent'] < (int) config('onboarding.setup_until_percent', 60);
    }
}
