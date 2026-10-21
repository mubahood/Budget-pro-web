<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetProgram;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Puts a budget template (BudgetTemplates) into an event's budget: for the API (POST budget-programs/{id}/apply-template),
 * the web portal and the demo. The phone does the same on its own tables, offline, with the same rules
 * (budget-pro-mobo lib/pledges/template_logic.dart), and its categories and items reach the server through sync.
 *
 * The plan is what the person reviewed: BudgetTemplates::plan() for their guest count, with the lines they left
 * out removed and the quantities and prices they changed. Merge rules (it never deletes anything):
 *
 *  1. A category whose name matches one the event has (trimmed, any case) is reused, never made twice.
 *  2. An item whose name matches one already in that category (trimmed, any case) is left as it is and reported
 *     as skipped: never overwritten, never duplicated. A line repeated in the plan is skipped the second time.
 *  3. New items get the plan's quantity and unit price, nothing spent yet, not approved, and "unit: <unit>, from
 *     template <key>" as their details.
 *  4. The event's type is set from the template when it has none, the template key always; its pledge groups are
 *     the template's only when it has none of its own yet; settings.guests keeps the guest count.
 *
 * Who may: full access or the event's chair (BudgetAccess::canManageEvent), as for the web's budget pages.
 */
class TemplateApplier
{
    public const MAX_LINES = 300;

    /**
     * @param  list<array{name: string, items: list<array{name: string, unit?: ?string, quantity: int|float|string, unit_price: int|float|string}>}>  $plan
     * @return array{categories_added: int, categories_reused: int, items_added: int, items_skipped: list<string>, total_added: string, program_id: int}
     */
    public function apply(?User $by, BudgetProgram $program, array $plan, ?string $templateKey = null, ?int $guests = null, bool $checkAccess = true): array
    {
        if ($checkAccess && ! BudgetAccess::canManageEvent($by, (int) $program->id)) {
            throw BusinessRuleException::make('forbidden', "Only the event's chair or the owner can change its budget.", ['permission' => 'manage_budget']);
        }
        $template = $templateKey !== null && $templateKey !== '' ? BudgetTemplates::findOrFail($templateKey) : null;
        $lines = $this->clean($plan);
        $companyId = (int) $program->company_id;
        $userId = (int) ($by?->id ?: DB::table('companies')->where('id', $companyId)->value('owner_id'));
        if ($userId <= 0 || ! DB::table('admin_users')->where('id', $userId)->where('company_id', $companyId)->exists()) {
            throw BusinessRuleException::make('validation', 'Someone on this account must apply the template.');
        }

        $notify = BudgetItem::$notify;
        BudgetItem::$notify = false;
        try {
            return DB::transaction(function () use ($program, $lines, $template, $guests, $companyId, $userId) {
                DB::table('budget_programs')->where('id', $program->id)->lockForUpdate()->value('id');
                $report = ['categories_added' => 0, 'categories_reused' => 0, 'items_added' => 0, 'items_skipped' => [], 'total_added' => '0.00', 'program_id' => (int) $program->id];
                $cats = [];
                foreach (DB::table('budget_item_categories')->where('company_id', $companyId)->where('budget_program_id', $program->id)->where('is_deleted', 0)->get(['id', 'name']) as $c) {
                    $cats[self::key($c->name)] ??= (int) $c->id;
                }
                $seen = [];
                foreach ($lines as $cat) {
                    $ck = self::key($cat['name']);
                    if (isset($cats[$ck])) {
                        $report['categories_reused']++;
                    } else {
                        $model = new BudgetItemCategory();
                        $model->company_id = $companyId;
                        $model->budget_program_id = $program->id;
                        $model->name = $cat['name'];
                        $model->save();
                        $cats[$ck] = (int) $model->id;
                        $report['categories_added']++;
                    }
                    $catId = $cats[$ck];
                    $have = DB::table('budget_items')->where('budget_item_category_id', $catId)->where('is_deleted', 0)->pluck('name')->map(fn ($n) => self::key((string) $n))->flip();
                    foreach ($cat['items'] as $it) {
                        $ik = $catId.'|'.self::key($it['name']);
                        if ($have->has(self::key($it['name'])) || isset($seen[$ik])) {
                            $report['items_skipped'][] = $cat['name'].' / '.$it['name'];

                            continue;
                        }
                        $seen[$ik] = true;
                        $item = new BudgetItem();
                        $item->company_id = $companyId;
                        $item->budget_item_category_id = $catId;
                        $item->budget_program_id = $program->id;
                        $item->created_by_id = $userId;
                        $item->name = $it['name'];
                        $item->unit_price = $it['unit_price'];
                        $item->quantity = $it['quantity'];
                        $item->invested_amount = 0;
                        $item->approved = 'No';
                        $item->priority = 'Normal';
                        $item->details = trim(implode(', ', array_filter([$it['unit'] !== '' ? 'unit: '.$it['unit'] : '', $template ? 'from template '.$template['key'] : ''])));
                        $item->save();
                        $report['items_added']++;
                        $report['total_added'] = Decimal::add($report['total_added'], Decimal::mul($it['unit_price'], $it['quantity']));
                    }
                }
                if ($template !== null || $guests !== null) {
                    $this->remember($program->fresh() ?? $program, $template, $guests);
                }
                BudgetProgram::recalculateFromChildren((int) $program->id);

                return $report;
            });
        } finally {
            BudgetItem::$notify = $notify;
        }
    }

    /** The template laid out for the guests and applied as it is (the demo; "apply everything" on the web). */
    public function applyTemplate(?User $by, BudgetProgram $program, string $key, ?int $guests = null, bool $checkAccess = true): array
    {
        $template = BudgetTemplates::findOrFail($key);
        $g = BudgetTemplates::clampGuests($guests, $template);
        $currency = (string) DB::table('companies')->where('id', $program->company_id)->value('currency');

        return $this->apply($by, $program, BudgetTemplates::plan($template, $g, $currency), $key, $g, $checkAccess);
    }

    private function remember(BudgetProgram $program, ?array $template, ?int $guests): void
    {
        if ($template !== null) {
            if (trim((string) $program->event_type) === '') {
                $program->event_type = $template['event_type'] ?? null;
            }
            $program->template_key = $template['key'];
            if (EventSettings::parseGroups($program->groups) === [] && ! empty($template['groups'])) {
                $program->groups = EventSettings::groupsJson((array) $template['groups']);
            }
        }
        if ($guests !== null) {
            $program->settings = EventSettings::settingsJson(['guests' => $guests] + EventSettings::settings($program->settings));
        }
        if ($program->isDirty()) {
            $program->save();
        }
    }

    /** @return list<array{name: string, items: list<array{name: string, unit: string, quantity: string, unit_price: string}>}> */
    private function clean(array $plan): array
    {
        $out = [];
        $count = 0;
        foreach ($plan as $cat) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($cat['name'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                throw BusinessRuleException::make('validation', 'Each budget category needs a name of 2 to 100 characters.', ['field' => 'plan.name']);
            }
            $items = [];
            foreach ((array) ($cat['items'] ?? []) as $it) {
                $iname = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['name'] ?? '')));
                $qty = str_replace(',', '', trim((string) ($it['quantity'] ?? '')));
                $price = str_replace(',', '', trim((string) ($it['unit_price'] ?? '')));
                if (mb_strlen($iname) < 2 || mb_strlen($iname) > 150) {
                    throw BusinessRuleException::make('validation', "Each budget item needs a name of 2 to 150 characters ({$name}).", ['field' => 'plan.items.name']);
                }
                if (! Decimal::valid($qty) || Decimal::cmp($qty, '0') <= 0 || Decimal::cmp($qty, '1000000') > 0) {
                    throw BusinessRuleException::make('validation', "Enter a quantity above 0 for {$iname}.", ['field' => 'plan.items.quantity']);
                }
                if (! Decimal::valid($price) || Decimal::cmp($price, '0') < 0 || Decimal::cmp($price, '1000000000000') > 0) {
                    throw BusinessRuleException::make('validation', "Enter a price of 0 or more for {$iname}.", ['field' => 'plan.items.unit_price']);
                }
                $items[] = ['name' => $iname, 'unit' => trim(mb_substr((string) ($it['unit'] ?? ''), 0, 30)), 'quantity' => Decimal::of($qty, 3), 'unit_price' => Decimal::of($price)];
                if (++$count > self::MAX_LINES) {
                    throw BusinessRuleException::make('validation', 'A template can add at most '.self::MAX_LINES.' items at once.');
                }
            }
            if ($items !== []) {
                $out[] = ['name' => $name, 'items' => $items];
            }
        }
        if ($out === []) {
            throw BusinessRuleException::make('validation', 'Choose at least one budget item to add.', ['field' => 'plan']);
        }

        return $out;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
