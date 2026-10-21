<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;

/**
 * The budget templates for events (weddings, introductions, give-aways, send-offs, fundraisers…): one versioned
 * data file, resources/data/budget_templates.json, read here for the API (GET /api/v1/budget-templates), the web
 * portal and the demo, and bundled by the phone app (budget-pro-mobo assets/pledges/budget_templates.json, a
 * byte-for-byte copy; docs/BUDGET_TEMPLATES.md says how to refresh it). The same file carries the default pledge
 * groups and the default message texts (MessageTemplates), so the phone and the server never disagree.
 *
 * A template: key, name, description, event_type, culture[], religion[], size, guests (the default guest count),
 * groups[] (suggested pledge groups), roles[] (suggested committee seats) and categories[] of items. An item:
 * name, unit, qty, price (UGX, whole shillings) and, when it grows with the guests, per (guests per qty units).
 *
 * For G guests an item's quantity is qty, or ceil(G / per) × qty when it has per; its total is price × quantity.
 * Totals are always worked out, never stored. In an account that does not count in shillings, unit prices are
 * converted with the file's approximate `rates` (UGX per unit), rounded to whole units.
 */
final class BudgetTemplates
{
    public const SIZES = ['small', 'medium', 'large', 'luxury'];

    public const MIN_GUESTS = 1;

    public const MAX_GUESTS = 5000;

    /** @var array<string, mixed>|null the decoded file (per process) */
    private static ?array $data = null;

    private static ?string $raw = null;

    /** The kernel's own copy (not resource_path(): the web portal runs this code from its own app root). */
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/data/budget_templates.json';
    }

    /** @return array<string, mixed> the whole file */
    public static function data(): array
    {
        if (self::$data === null) {
            $raw = (string) @file_get_contents(self::path());
            $decoded = json_decode($raw, true);
            if (! is_array($decoded) || ! isset($decoded['templates']) || ! is_array($decoded['templates'])) {
                throw new \RuntimeException('resources/data/budget_templates.json is missing or not valid JSON.');
            }
            self::$raw = $raw;
            self::$data = $decoded;
        }

        return self::$data;
    }

    /** Forget the cached file (tests). */
    public static function flush(): void
    {
        self::$data = null;
        self::$raw = null;
    }

    public static function version(): string
    {
        return (string) (self::data()['version'] ?? '0');
    }

    /** A strong ETag for the file: its version and a hash of its bytes. */
    public static function etag(): string
    {
        self::data();

        return '"bt-'.self::version().'-'.substr(sha1((string) self::$raw), 0, 12).'"';
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return array_values(self::data()['templates']);
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $key): ?array
    {
        $key = trim((string) $key);
        foreach (self::all() as $t) {
            if (($t['key'] ?? null) === $key) {
                return $t;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function findOrFail(?string $key): array
    {
        return self::find($key) ?? throw BusinessRuleException::make('unknown_template', 'This budget template was not found.');
    }

    /** @return list<array{key: string, label: string}> */
    public static function eventTypes(): array
    {
        return array_values((array) (self::data()['event_types'] ?? []));
    }

    /** @return list<string> the default pledge groups */
    public static function defaultGroups(): array
    {
        return array_values(array_map('strval', (array) (self::data()['groups'] ?? [])));
    }

    /** @return array<string, array<string, string>> kind => lang => text */
    public static function defaultMessages(): array
    {
        return (array) (self::data()['messages'] ?? []);
    }

    /**
     * The templates, filtered: type (event_type), culture, religion (a template tagged "any" matches every value),
     * size, and q (words in the name, description or tags).
     *
     * @param  array{type?: ?string, culture?: ?string, religion?: ?string, size?: ?string, q?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public static function filter(array $f = []): array
    {
        $tag = fn (array $t, string $field, ?string $want) => $want === null || $want === ''
            || in_array(strtolower($want), array_map('strtolower', (array) ($t[$field] ?? [])), true)
            || in_array('any', (array) ($t[$field] ?? []), true);
        $q = strtolower(trim((string) ($f['q'] ?? '')));

        return array_values(array_filter(self::all(), function (array $t) use ($f, $tag, $q) {
            if (! empty($f['type']) && ($t['event_type'] ?? '') !== $f['type']) {
                return false;
            }
            if (! empty($f['size']) && ($t['size'] ?? '') !== $f['size']) {
                return false;
            }
            if (! $tag($t, 'culture', $f['culture'] ?? null) || ! $tag($t, 'religion', $f['religion'] ?? null)) {
                return false;
            }
            if ($q !== '') {
                $hay = strtolower(implode(' ', [$t['name'] ?? '', $t['description'] ?? '', $t['key'] ?? '', ...((array) ($t['culture'] ?? [])), ...((array) ($t['religion'] ?? []))]));
                foreach (preg_split('/\s+/', $q) ?: [] as $word) {
                    if ($word !== '' && ! str_contains($hay, $word)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    public static function clampGuests(mixed $guests, array $template): int
    {
        $g = is_numeric($guests) ? (int) $guests : (int) ($template['guests'] ?? 100);

        return max(self::MIN_GUESTS, min(self::MAX_GUESTS, $g));
    }

    /** An item's quantity for this many guests. */
    public static function quantity(array $item, int $guests): int|float
    {
        $qty = $item['qty'] ?? 1;
        $qty = is_numeric($qty) ? $qty + 0 : 1;
        $per = isset($item['per']) ? (int) $item['per'] : 0;

        return $per >= 1 ? (int) ceil($guests / $per) * $qty : $qty;
    }

    /** A UGX price in the account's currency (whole units, at least 1). */
    public static function price(int|float $ugx, ?string $currency = null): int
    {
        $currency = strtoupper(trim((string) $currency));
        $rate = $currency === '' ? 1 : (self::data()['rates'][$currency] ?? null);
        if ($rate === null || (float) $rate <= 0 || (float) $rate === 1.0) {
            return (int) round($ugx);
        }

        return max(1, (int) round($ugx / (float) $rate));
    }

    /**
     * The template laid out for G guests: categories with their items, each with its quantity, unit price (in the
     * account's currency) and total. This is what a person reviews (and may untick or edit) before applying it.
     *
     * @return list<array{name: string, items: list<array{name: string, unit: string, quantity: int|float, unit_price: int, total: int, per: ?int}>}>
     */
    public static function plan(array $template, int $guests, ?string $currency = null): array
    {
        $out = [];
        foreach ((array) ($template['categories'] ?? []) as $cat) {
            $items = [];
            foreach ((array) ($cat['items'] ?? []) as $it) {
                $q = self::quantity($it, $guests);
                $price = self::price($it['price'] ?? 0, $currency);
                $items[] = ['name' => (string) $it['name'], 'unit' => (string) ($it['unit'] ?? ''), 'quantity' => $q, 'unit_price' => $price,
                    'total' => (int) round($q * $price), 'per' => isset($it['per']) ? (int) $it['per'] : null];
            }
            $out[] = ['name' => (string) $cat['name'], 'items' => $items];
        }

        return $out;
    }

    /** @param list<array{items: list<array{total?: int, quantity?: int|float, unit_price?: int|float}>}> $plan */
    public static function total(array $plan): int
    {
        $sum = 0;
        foreach ($plan as $cat) {
            foreach ($cat['items'] as $it) {
                $sum += (int) ($it['total'] ?? round(($it['quantity'] ?? 0) * ($it['unit_price'] ?? 0)));
            }
        }

        return $sum;
    }

    /** What the list shows for a template: its tags, guests, counts and total at its own guest count. */
    public static function summary(array $template, ?string $currency = null): array
    {
        $guests = self::clampGuests(null, $template);
        $plan = self::plan($template, $guests, $currency);

        return [
            'key' => $template['key'], 'name' => $template['name'], 'description' => $template['description'] ?? '',
            'event_type' => $template['event_type'] ?? 'other', 'culture' => array_values((array) ($template['culture'] ?? [])),
            'religion' => array_values((array) ($template['religion'] ?? [])), 'size' => $template['size'] ?? 'medium',
            'guests' => $guests, 'categories' => count($plan), 'items' => array_sum(array_map(fn ($c) => count($c['items']), $plan)),
            'total' => self::total($plan),
        ];
    }

    /** One template with its plan for G guests (the API's GET budget-templates/{key}). */
    public static function detail(array $template, ?int $guests = null, ?string $currency = null): array
    {
        $g = self::clampGuests($guests, $template);
        $plan = self::plan($template, $g, $currency);

        return array_merge(self::summary($template, $currency), [
            'guests' => $g, 'default_guests' => self::clampGuests(null, $template), 'total' => self::total($plan),
            'groups' => array_values((array) ($template['groups'] ?? [])), 'roles' => array_values((array) ($template['roles'] ?? [])), 'plan' => $plan,
        ]);
    }

    /**
     * What is wrong with the data file (the test and `budget:templates --check` use this): unique keys, known
     * event types and sizes, at least one category with items, every item named with a unit, a positive whole
     * price, a positive quantity and per >= 1, default messages in English for every kind, known placeholders.
     *
     * @return list<string>
     */
    public static function problems(?array $data = null): array
    {
        $data ??= self::data();
        $errors = [];
        $types = array_column((array) ($data['event_types'] ?? []), 'key');
        $keys = [];
        foreach ((array) ($data['templates'] ?? []) as $n => $t) {
            $key = (string) ($t['key'] ?? '');
            $at = $key !== '' ? $key : "#{$n}";
            if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key)) {
                $errors[] = "{$at}: the key must be lower-case words joined by dashes.";
            }
            if (isset($keys[$key])) {
                $errors[] = "{$at}: the key is used twice.";
            }
            $keys[$key] = true;
            foreach (['name', 'description'] as $field) {
                if (trim((string) ($t[$field] ?? '')) === '') {
                    $errors[] = "{$at}: {$field} is empty.";
                }
            }
            if (! in_array($t['event_type'] ?? null, $types, true)) {
                $errors[] = "{$at}: unknown event_type.";
            }
            if (! in_array($t['size'] ?? null, self::SIZES, true)) {
                $errors[] = "{$at}: size must be one of ".implode(', ', self::SIZES).'.';
            }
            if (! is_int($t['guests'] ?? null) || $t['guests'] < 1) {
                $errors[] = "{$at}: guests must be a whole number above 0.";
            }
            foreach (['culture', 'religion', 'groups', 'roles'] as $field) {
                if (! is_array($t[$field] ?? null)) {
                    $errors[] = "{$at}: {$field} must be a list.";
                }
            }
            foreach ((array) ($t['roles'] ?? []) as $r) {
                if (! in_array($r['role'] ?? null, BudgetAccess::ROLES, true) || trim((string) ($r['title'] ?? '')) === '') {
                    $errors[] = "{$at}: a suggested committee seat needs a title and a role (".implode(', ', BudgetAccess::ROLES).').';
                }
            }
            $cats = (array) ($t['categories'] ?? []);
            if ($cats === []) {
                $errors[] = "{$at}: no categories.";
            }
            $catNames = [];
            foreach ($cats as $c) {
                $cn = mb_strtolower(trim((string) ($c['name'] ?? '')));
                if ($cn === '' || isset($catNames[$cn])) {
                    $errors[] = "{$at}: a category is unnamed or named twice ({$cn}).";
                }
                $catNames[$cn] = true;
                $itemNames = [];
                if ((array) ($c['items'] ?? []) === []) {
                    $errors[] = "{$at} / {$cn}: no items.";
                }
                foreach ((array) ($c['items'] ?? []) as $it) {
                    $in = mb_strtolower(trim((string) ($it['name'] ?? '')));
                    if ($in === '' || isset($itemNames[$in])) {
                        $errors[] = "{$at} / {$cn}: an item is unnamed or named twice ({$in}).";
                    }
                    $itemNames[$in] = true;
                    if (mb_strlen((string) ($it['name'] ?? '')) > 150) {
                        $errors[] = "{$at} / {$in}: the name is longer than 150 characters.";
                    }
                    if (trim((string) ($it['unit'] ?? '')) === '') {
                        $errors[] = "{$at} / {$in}: no unit.";
                    }
                    if (! is_int($it['price'] ?? null) || $it['price'] <= 0) {
                        $errors[] = "{$at} / {$in}: the price must be whole shillings above 0.";
                    }
                    if (! is_numeric($it['qty'] ?? null) || $it['qty'] <= 0) {
                        $errors[] = "{$at} / {$in}: the quantity must be above 0.";
                    }
                    if (array_key_exists('per', $it) && (! is_int($it['per']) || $it['per'] < 1)) {
                        $errors[] = "{$at} / {$in}: per must be a whole number of guests, at least 1.";
                    }
                }
            }
        }
        $known = MessageTemplates::PLACEHOLDERS;
        foreach (MessageTemplates::KINDS as $kind) {
            $texts = (array) ($data['messages'][$kind] ?? []);
            if (trim((string) ($texts['en'] ?? '')) === '') {
                $errors[] = "messages.{$kind}: no English text.";
            }
            foreach ($texts as $lang => $text) {
                preg_match_all('/\{([a-z_]+)\}/', (string) $text, $m);
                foreach (array_diff($m[1], $known) as $unknown) {
                    $errors[] = "messages.{$kind}.{$lang}: unknown placeholder {{$unknown}}.";
                }
            }
        }
        if (array_filter((array) ($data['groups'] ?? []), fn ($g) => ! is_string($g) || trim($g) === '') !== [] || (array) ($data['groups'] ?? []) === []) {
            $errors[] = 'groups: the default pledge groups must be a list of names.';
        }

        return $errors;
    }
}
