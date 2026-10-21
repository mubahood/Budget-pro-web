<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * An event's own settings, in budget_programs columns that all may be empty (older rows, older apps):
 *
 *   event_type       what kind of event (BudgetTemplates::eventTypes keys, or free text)
 *   template_key     the budget template last applied to it
 *   venue            where it is
 *   groups           its pledge groups, a JSON list of names (an older value typed as text, one per line or
 *                    comma-separated, is still read); empty = the default list (BudgetTemplates::defaultGroups)
 *   settings         JSON: {v, messages: {kind: text}, message_lang, presets: [amounts], currency_display, guests,
 *                    meeting: {when, where}}; keys this version does not know are kept
 *   payment_details  JSON: {mtn, mtn_name, airtel, airtel_name, bank, bank_account, bank_account_name, treasurer,
 *                    treasurer_phone, note}: where pledgers pay, shown in messages
 *
 * Who may change them (BudgetAccess): the settings, the owner, anyone with manage_budget and the event's chair;
 * payment_details, those and the treasurer. The phone reads and writes the same columns (lib/pledges/event_settings.dart)
 * through sync; BudgetProgram normalises whatever arrives (bad JSON becomes empty, never an error), so an old or
 * hand-made value never breaks an event.
 */
final class EventSettings
{
    public const SETTINGS_FIELDS = ['event_type', 'template_key', 'venue', 'groups', 'settings'];

    public const PAYMENT_FIELD = 'payment_details';

    public const PAYMENT_KEYS = ['mtn', 'mtn_name', 'airtel', 'airtel_name', 'bank', 'bank_account', 'bank_account_name', 'treasurer', 'treasurer_phone', 'note'];

    public const PHONE_KEYS = ['mtn', 'airtel', 'treasurer_phone'];

    public const MAX_GROUPS = 40;

    public const MAX_PRESETS = 12;

    public const MAX_MESSAGE = 2000;

    /**
     * The event's settings as the API and the portal show them.
     *
     * @return array{event_type: string, template_key: string, venue: string, groups: list<string>, custom_groups: bool, settings: array<string, mixed>, payment_details: array<string, string>}
     */
    public static function read(object $program): array
    {
        $groups = self::parseGroups($program->groups ?? null);

        return [
            'event_type' => trim((string) ($program->event_type ?? '')),
            'template_key' => trim((string) ($program->template_key ?? '')),
            'venue' => trim((string) ($program->venue ?? '')),
            'groups' => $groups !== [] ? $groups : BudgetTemplates::defaultGroups(),
            'custom_groups' => $groups !== [],
            'settings' => self::settings($program->settings ?? null),
            'payment_details' => self::paymentDetails($program->payment_details ?? null),
        ];
    }

    /**
     * The groups column read leniently: a JSON list of names (or of {key,label} / {label} objects), else text
     * split on new lines, commas or semicolons. Trimmed, at most 60 characters each, no repeats in any case.
     *
     * @return list<string>
     */
    public static function parseGroups(mixed $raw): array
    {
        $out = [];
        $add = function (mixed $v) use (&$out) {
            $t = trim((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : ''));
            if ($t === '' || strtolower($t) === 'null') {
                return;
            }
            $t = trim(mb_substr($t, 0, 60));
            foreach ($out as $x) {
                if (mb_strtolower($x) === mb_strtolower($t)) {
                    return;
                }
            }
            if (count($out) < self::MAX_GROUPS) {
                $out[] = $t;
            }
        };
        $text = is_array($raw) ? (string) json_encode($raw) : trim((string) $raw);
        if ($text === '' || strtolower($text) === 'null') {
            return [];
        }
        if (str_starts_with($text, '[')) {
            $list = json_decode($text, true);
            if (is_array($list)) {
                foreach ($list as $v) {
                    is_array($v) ? $add($v['label'] ?? $v['name'] ?? $v['key'] ?? null) : $add($v);
                }

                return $out;
            }
        }
        foreach (preg_split('/[\n\r,;]+/', $text) ?: [] as $part) {
            $add($part);
        }

        return $out;
    }

    /** @param list<string> $groups @return ?string what the groups column stores (null: the default list) */
    public static function groupsJson(array $groups): ?string
    {
        $clean = self::parseGroups(array_values($groups));

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> the settings column, cleaned (unknown keys kept) */
    public static function settings(mixed $raw): array
    {
        $s = self::object($raw);
        $out = array_diff_key($s, array_flip(['v', 'messages', 'message_lang', 'presets', 'currency_display', 'guests', 'meeting']));
        $out['v'] = 1;
        $messages = [];
        foreach ((array) ($s['messages'] ?? []) as $kind => $text) {
            if (is_string($kind) && is_scalar($text) && trim((string) $text) !== '') {
                $messages[$kind] = mb_substr(str_replace("\r\n", "\n", (string) $text), 0, self::MAX_MESSAGE);
            }
        }
        if ($messages !== []) {
            $out['messages'] = $messages;
        }
        $lang = trim((string) ($s['message_lang'] ?? ''));
        if (in_array($lang, MessageTemplates::LANGS, true)) {
            $out['message_lang'] = $lang;
        }
        $presets = [];
        foreach ((array) ($s['presets'] ?? []) as $v) {
            $n = is_numeric(str_replace(',', '', (string) $v)) ? (int) round((float) str_replace(',', '', (string) $v)) : 0;
            if ($n > 0 && ! in_array($n, $presets, true) && count($presets) < self::MAX_PRESETS) {
                $presets[] = $n;
            }
        }
        if ($presets !== []) {
            $out['presets'] = $presets;
        }
        $label = trim(mb_substr((string) (is_scalar($s['currency_display'] ?? null) ? $s['currency_display'] : ''), 0, 12));
        if ($label !== '') {
            $out['currency_display'] = $label;
        }
        $guests = is_numeric($s['guests'] ?? null) ? (int) $s['guests'] : 0;
        if ($guests >= BudgetTemplates::MIN_GUESTS && $guests <= BudgetTemplates::MAX_GUESTS) {
            $out['guests'] = $guests;
        }
        $meeting = (array) ($s['meeting'] ?? []);
        $when = trim(mb_substr((string) (is_scalar($meeting['when'] ?? null) ? $meeting['when'] : ''), 0, 120));
        $where = trim(mb_substr((string) (is_scalar($meeting['where'] ?? null) ? $meeting['where'] : ''), 0, 120));
        if ($when !== '' || $where !== '') {
            $out['meeting'] = ['when' => $when, 'where' => $where];
        }

        return $out;
    }

    /** @return array<string, string> the payment_details column, cleaned (only the known keys, non-empty) */
    public static function paymentDetails(mixed $raw, string $country = 'UG'): array
    {
        $d = self::object($raw);
        $out = [];
        foreach (self::PAYMENT_KEYS as $k) {
            $v = trim(mb_substr((string) (is_scalar($d[$k] ?? null) ? $d[$k] : ''), 0, $k === 'note' ? 300 : 120));
            if ($v === '') {
                continue;
            }
            if (in_array($k, self::PHONE_KEYS, true)) {
                $v = Phone::e164($v, $country) ?? $v; // a number we cannot read is kept as typed (the API refuses it, sync does not)
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** What to store for a settings column (null when there is nothing), from anything a client sent. */
    public static function settingsJson(mixed $raw): ?string
    {
        $s = self::settings($raw);

        return count($s) <= 1 ? null : json_encode($s, JSON_UNESCAPED_UNICODE);
    }

    public static function paymentDetailsJson(mixed $raw, string $country = 'UG'): ?string
    {
        $d = self::paymentDetails($raw, $country);

        return $d === [] ? null : json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    /** Clean the settings columns of a program about to be saved (BudgetProgram::saving): never refuses. */
    public static function normalize(BudgetProgram $program): void
    {
        if ($program->isDirty('groups') && $program->groups !== null) {
            $program->groups = self::groupsJson(self::parseGroups($program->groups));
        }
        if ($program->isDirty('settings') && $program->settings !== null) {
            $program->settings = self::settingsJson($program->settings);
        }
        if ($program->isDirty('payment_details') && $program->payment_details !== null) {
            $program->payment_details = self::paymentDetailsJson($program->payment_details, self::country((int) $program->company_id));
        }
        foreach (['event_type' => 40, 'template_key' => 60, 'venue' => 191] as $column => $max) {
            if ($program->isDirty($column) && $program->{$column} !== null) {
                $v = trim(mb_substr(trim((string) $program->{$column}), 0, $max));
                $program->{$column} = $v === '' ? null : $v;
            }
        }
    }

    /**
     * Change an event's settings (API, web). Only the fields given change. Settings need the chair, the owner or
     * manage_budget; payment details also the treasurer. A phone number that cannot be read is refused here.
     *
     * @param  array{event_type?: ?string, venue?: ?string, groups?: list<string>|string|null, settings?: array<string, mixed>|null, payment_details?: array<string, string>|null}  $input
     */
    public function update(User $by, BudgetProgram $program, array $input): BudgetProgram
    {
        $pid = (int) $program->id;
        $touchesSettings = array_intersect_key($input, array_flip(self::SETTINGS_FIELDS)) !== [];
        if ($touchesSettings && ! BudgetAccess::canEditSettings($by, $pid)) {
            throw BusinessRuleException::make('forbidden', "Only the event's chair or the owner can change its settings.", ['permission' => 'event_settings']);
        }
        if (array_key_exists(self::PAYMENT_FIELD, $input) && ! BudgetAccess::canEditPaymentDetails($by, $pid)) {
            throw BusinessRuleException::make('forbidden', "Only the event's chair, its treasurer or the owner can change where people pay.", ['permission' => 'payment_details']);
        }
        if (array_key_exists('event_type', $input)) {
            $program->event_type = $input['event_type'];
        }
        if (array_key_exists('venue', $input)) {
            $program->venue = $input['venue'];
        }
        if (array_key_exists('groups', $input)) {
            $program->groups = self::groupsJson(is_array($input['groups']) ? $input['groups'] : self::parseGroups($input['groups']));
        }
        if (array_key_exists('settings', $input)) {
            // A partial settings object merges into what is there (a client that only knows the messages keeps the presets).
            $merged = array_replace(self::settings($program->settings), (array) ($input['settings'] ?? []));
            $program->settings = self::settingsJson($merged);
        }
        if (array_key_exists(self::PAYMENT_FIELD, $input)) {
            $country = self::country((int) $program->company_id);
            foreach (self::PHONE_KEYS as $k) {
                $v = trim((string) ($input[self::PAYMENT_FIELD][$k] ?? ''));
                if ($v !== '' && Phone::e164($v, $country) === null) {
                    throw BusinessRuleException::make('invalid_phone', 'Enter a valid phone number for '.str_replace(['_phone', '_'], ['', ' '], $k).', or leave it empty.', ['field' => "payment_details.{$k}"]);
                }
            }
            $program->payment_details = self::paymentDetailsJson((array) ($input[self::PAYMENT_FIELD] ?? []), $country);
        }
        $program->save();

        return $program->fresh() ?? $program;
    }

    /** The settings with what this person may change and the default texts (the API's GET …/settings). */
    public function present(User $by, BudgetProgram $program): array
    {
        $read = self::read($program);
        $lang = (string) ($read['settings']['message_lang'] ?? 'en') ?: 'en';

        return $read + [
            'id' => (int) $program->id,
            'can' => ['settings' => BudgetAccess::canEditSettings($by, (int) $program->id), 'payment_details' => BudgetAccess::canEditPaymentDetails($by, (int) $program->id)],
            'messages' => array_combine(MessageTemplates::KINDS, array_map(fn ($k) => MessageTemplates::textFor($k, $read['settings']), MessageTemplates::KINDS)),
            'default_messages' => array_combine(MessageTemplates::KINDS, array_map(fn ($k) => MessageTemplates::defaultText($k, $lang), MessageTemplates::KINDS)),
            'placeholders' => MessageTemplates::PLACEHOLDERS,
            'event_types' => BudgetTemplates::eventTypes(),
        ];
    }

    /** The pledge groups of an event, for the pledge form and filters: its list, else the defaults. @return list<string> */
    public static function groupsOf(int $companyId, int $programId): array
    {
        $raw = DB::table('budget_programs')->where('company_id', $companyId)->where('id', $programId)->value('groups');
        $groups = self::parseGroups($raw);

        return $groups !== [] ? $groups : BudgetTemplates::defaultGroups();
    }

    /** @return array<string, mixed> */
    private static function object(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return (array) $raw;
        }
        $t = trim((string) $raw);
        if ($t === '' || ! str_starts_with($t, '{')) {
            return [];
        }
        $v = json_decode($t, true);

        return is_array($v) ? $v : [];
    }

    private static function country(int $companyId): string
    {
        return (string) (DB::table('companies')->where('id', $companyId)->value('country') ?: 'UG');
    }
}
