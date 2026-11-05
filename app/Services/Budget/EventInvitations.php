<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\EventInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who an event's invitation cards are for, and the log of them. The people a name can be picked from are the account's
 * own: the event's pledge book and committee, the pledge books of its other events, and the team. A name someone typed
 * is welcome too. One row per person on an event, by name (as the pledge book does). Marking and logging need
 * BudgetAccess::PLEDGE on an open event, the same people who write in the pledge book.
 */
final class EventInvitations
{
    public const FILTERS = ['all', 'waiting', 'made', 'invited'];

    /** A person's place in the suggestions: this event's first. */
    private const RANK = ['pledge' => 0, 'committee' => 1, 'invited' => 2, 'team' => 3, 'other_event' => 4];

    /** How many names to bring from the account's other events. */
    private const OTHER_EVENTS_LIMIT = 3000;

    private const ROLES = ['chair' => 'Chair', 'treasurer' => 'Treasurer', 'secretary' => 'Secretary', 'member' => 'Member', 'viewer' => 'Viewer'];

    /**
     * The titles people write in front of a name in Uganda, most used first, for the chips on the name box. `words` is every
     * way it gets typed, in lower case with no full stop. A `solo` title stands alone (Mr., Aunt); a `rank` can stack, in order
     * of `stack` (Hon. Dr.), and take a partner (Dr. & Mrs.). The browser reads this same list, so both sides agree on who is who.
     */
    public const TITLES = [
        ['key' => 'mr', 'text' => 'Mr.', 'words' => ['mr', 'mister'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'mrs', 'text' => 'Mrs.', 'words' => ['mrs'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'dr', 'text' => 'Dr.', 'words' => ['dr', 'doctor'], 'kind' => 'rank', 'stack' => 6],
        ['key' => 'eng', 'text' => 'Eng.', 'words' => ['eng', 'engineer'], 'kind' => 'rank', 'stack' => 7],
        ['key' => 'pastor', 'text' => 'Pastor', 'words' => ['pastor', 'pr', 'ps'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'mama', 'text' => 'Mama', 'words' => ['mama', 'maama'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'hon', 'text' => 'Hon.', 'words' => ['hon', 'honourable', 'honorable'], 'kind' => 'rank', 'stack' => 1],
        ['key' => 'rev', 'text' => 'Rev.', 'words' => ['rev', 'reverend'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'ms', 'text' => 'Ms.', 'words' => ['ms'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'miss', 'text' => 'Miss', 'words' => ['miss'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'prof', 'text' => 'Prof.', 'words' => ['prof', 'professor'], 'kind' => 'rank', 'stack' => 5],
        ['key' => 'hajji', 'text' => 'Hajji', 'words' => ['hajji', 'haji', 'hajj'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'hajjat', 'text' => 'Hajjat', 'words' => ['hajjat', 'hajat', 'hajati'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'sheikh', 'text' => 'Sheikh', 'words' => ['sheikh', 'sheik'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'fr', 'text' => 'Fr.', 'words' => ['fr', 'father'], 'kind' => 'rank', 'stack' => 3],
        ['key' => 'bishop', 'text' => 'Bishop', 'words' => ['bishop', 'bp'], 'kind' => 'rank', 'stack' => 2],
        ['key' => 'uncle', 'text' => 'Uncle', 'words' => ['uncle'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'aunt', 'text' => 'Aunt', 'words' => ['aunt', 'auntie', 'aunty'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'sister', 'text' => 'Sister', 'words' => ['sister', 'sr', 'sis'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'bro', 'text' => 'Bro.', 'words' => ['bro', 'brother'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'mzee', 'text' => 'Mzee', 'words' => ['mzee'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'jjajja', 'text' => 'Jjajja', 'words' => ['jjajja', 'jajja'], 'kind' => 'solo', 'stack' => 0],
        ['key' => 'capt', 'text' => 'Capt.', 'words' => ['capt', 'captain'], 'kind' => 'rank', 'stack' => 4],
        ['key' => 'maj', 'text' => 'Maj.', 'words' => ['maj', 'major'], 'kind' => 'rank', 'stack' => 4],
        ['key' => 'gen', 'text' => 'Gen.', 'words' => ['gen', 'general'], 'kind' => 'rank', 'stack' => 4],
        ['key' => 'counsel', 'text' => 'Counsel', 'words' => ['counsel'], 'kind' => 'rank', 'stack' => 6],
    ];

    public static function cleanName(mixed $name): string
    {
        return mb_substr(Str::squish(strip_tags((string) $name)), 0, 120);
    }

    /**
     * Who a name means: lower case, single spaces, and without its titles, so "Mr. & Mrs. Kato Paul" is the "Kato Paul" of the
     * pledge book, and a title added on a card never makes a second guest. Only with two words of name left: "Mama Joan" is
     * usually Joan's mother, and "Mr. Kato" and "Mrs. Kato" are two people. resources/js/cards/titles.js does the same.
     */
    public static function key(string $name): string
    {
        $clean = self::cleanName($name);
        $rest = trim((string) preg_replace(self::titlesPattern(), '', $clean));

        return mb_strtolower(count(preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 2 ? $rest : $clean);
    }

    /** The titles at the start of a name, however they were typed: "Mr. & Mrs.", "Mr and Mrs", "Hon. Dr.". */
    private static function titlesPattern(): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $words = array_merge(...array_column(self::TITLES, 'words'));
            usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));
            $any = implode('|', $words);
            $pattern = '/^(?:(?:'.$any.')(?:\.\s*|\s+|(?=&))(?:(?:&|and\b)\s*(?:'.$any.')(?:\.\s*|\s+))?)+/iu';
        }

        return $pattern;
    }

    /**
     * Everybody a card could be for, merged by name.
     *
     * @return list<array{key: string, name: string, source: string, note: string, phone: ?string, pledge_id: ?int, here: bool, id: ?int, status: ?string, invited_at: ?string, invited_by: ?string, cards: int, last_card_at: ?string}>
     *                                                                                                                                                                                                                                   `here`: of this event (its pledge book or committee, or already on its log); others are only suggestions
     */
    public function people(int $companyId, int $eventId): array
    {
        $people = [];
        $add = function (string $name, string $source, string $note, array $extra = []) use (&$people): void {
            $key = self::key($name);
            if ($key === '') {
                return;
            }
            $people[$key] ??= ['key' => $key, 'name' => self::cleanName($name), 'source' => $source, 'notes' => [], 'phone' => null, 'pledge_id' => null, 'here' => false, 'kept_note' => null,
                'id' => null, 'status' => null, 'invited_at' => null, 'invited_by' => null, 'cards' => 0, 'last_card_at' => null];
            if ($note !== '' && ! in_array($note, $people[$key]['notes'], true)) {
                $people[$key]['notes'][] = $note;
            }
            if (self::RANK[$source] < self::RANK[$people[$key]['source']]) {
                $people[$key]['source'] = $source;
            }
            foreach ($extra as $field => $value) {
                $people[$key][$field] = $value ?? $people[$key][$field];
            }
        };

        DB::table('contribution_records')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)
            ->orderBy('name')->get(['id', 'name', 'phone', 'category_id'])
            ->each(function ($r) use ($add) {
                $group = trim((string) $r->category_id);
                $add((string) $r->name, 'pledge', 'Pledge book'.($group !== '' ? ' · '.EventBook::groupLabel($group) : ''), ['phone' => $r->phone, 'pledge_id' => (int) $r->id, 'here' => true]);
            });

        DB::table('budget_program_members as m')->join('admin_users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $companyId)->where('m.budget_program_id', $eventId)->where('m.is_deleted', 0)->where('m.status', 'active')
            ->get(['u.name', 'm.role', 'u.phone_number'])
            ->each(function ($r) use ($add) {
                $add((string) $r->name, 'committee', 'Committee · '.(self::ROLES[$r->role] ?? ucfirst((string) $r->role)), ['phone' => $r->phone_number ?: null, 'here' => true]);
            });

        DB::table('event_invitations as i')->leftJoin('admin_users as u', 'u.id', '=', 'i.invited_by_id')
            ->where('i.company_id', $companyId)->where('i.budget_program_id', $eventId)->where('i.is_deleted', 0)
            ->get(['i.id', 'i.name', 'i.status', 'i.source', 'i.source_note', 'i.invited_at', 'i.cards_made', 'i.last_card_at', 'u.name as by_name'])
            ->each(function ($r) use ($add) {
                $add((string) $r->name, 'invited', '', [
                    'name' => (string) $r->name, // as it was last printed on their card, with the title they were given
                    'kept_note' => $r->source_note ?: 'Added by hand', // what was known when they were logged: shown only if the books no longer know them
                    'here' => true, 'id' => (int) $r->id, 'status' => (string) $r->status, 'invited_at' => $r->invited_at, 'invited_by' => $r->by_name, 'cards' => (int) $r->cards_made, 'last_card_at' => $r->last_card_at,
                ]);
            });

        DB::table('admin_users')->where('company_id', $companyId)->whereNotNull('name')->where('name', '!=', '')->get(['name', 'phone_number'])
            ->each(function ($r) use ($add) {
                $add((string) $r->name, 'team', 'Team', ['phone' => $r->phone_number ?: null]);
            });

        DB::table('contribution_records as r')->join('budget_programs as p', 'p.id', '=', 'r.budget_program_id')
            ->where('r.company_id', $companyId)->where('r.budget_program_id', '!=', $eventId)->where('r.is_deleted', 0)->where('p.is_deleted', 0)
            ->orderByDesc('r.id')->limit(self::OTHER_EVENTS_LIMIT)->get(['r.name', 'r.phone', 'p.name as event'])
            ->each(function ($r) use ($add) {
                $add((string) $r->name, 'other_event', 'Pledged on '.$r->event, ['phone' => $r->phone]);
            });

        $list = array_map(function (array $p) {
            $p['note'] = implode(' · ', array_slice($p['notes'], 0, 2)) ?: (string) $p['kept_note'];
            unset($p['notes'], $p['kept_note']);

            return $p;
        }, array_values($people));
        usort($list, fn ($a, $b) => [self::RANK[$a['source']], $a['name']] <=> [self::RANK[$b['source']], $b['name']]);

        return $list;
    }

    /**
     * The log: this event's people, filtered and searched.
     *
     * @param  list<array<string, mixed>>  $people  from people()
     * @return list<array<string, mixed>>
     */
    public static function log(array $people, string $filter = 'all', string $search = ''): array
    {
        $needle = mb_strtolower(self::cleanName($search));
        $found = fn (array $p) => str_contains($p['key'], $needle) || str_contains(mb_strtolower($p['name']), $needle) || str_contains($p['key'], self::key($search));
        $rows = array_filter($people, fn ($p) => $p['here']
            && match ($filter) {
                'invited' => $p['status'] === 'invited',
                'made' => $p['status'] === 'made',
                'waiting' => $p['status'] !== 'invited',
                default => true,
            }
            && ($needle === '' || $found($p)));
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['invited_at'] ?? '', $a['name']] <=> [$a['invited_at'] ?? '', $b['name']]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $people  from people()
     * @return array{all: int, invited: int, made: int, waiting: int}
     */
    public static function counts(array $people): array
    {
        $here = array_filter($people, fn ($p) => $p['here']);
        $invited = count(array_filter($here, fn ($p) => $p['status'] === 'invited'));

        return ['all' => count($here), 'invited' => $invited, 'made' => count(array_filter($here, fn ($p) => $p['status'] === 'made')), 'waiting' => count($here) - $invited];
    }

    /** They were invited: their name goes on the log, with who marked it and when. Marked again, it stays as first marked. */
    public function markInvited(User $by, BudgetProgram $program, string $name, ?int $templateId = null): EventInvitation
    {
        $this->mayWrite($by, $program);

        return $this->onRow($by, $program, $name, $templateId, function (EventInvitation $row) use ($by) {
            if ($row->status !== 'invited') {
                $row->status = 'invited';
                $row->invited_at = now();
                $row->invited_by_id = $by->id;
            }
        });
    }

    /** A card was downloaded for someone. */
    public function cardMade(User $by, BudgetProgram $program, string $name, string $format, ?int $templateId = null): EventInvitation
    {
        $this->mayWrite($by, $program);
        if (! in_array($format, ['png', 'pdf'], true)) {
            throw BusinessRuleException::make('validation', 'A card is a PNG or a PDF.');
        }

        return $this->onRow($by, $program, $name, $templateId, function (EventInvitation $row) use ($format) {
            $row->cards_made = (int) $row->cards_made + 1;
            $row->last_format = $format;
            $row->last_card_at = now();
        });
    }

    /**
     * The demo's guest list starts part way through, so each filter has something to show: some invited, some with a
     * card made. Only on an empty log: what visitors do is kept until the demo is rebuilt.
     */
    public function seedDemo(int $companyId, int $eventId, int $byId, int $templateId): void
    {
        if (DB::table('event_invitations')->where('budget_program_id', $eventId)->exists()) {
            return;
        }
        $here = array_values(array_filter($this->people($companyId, $eventId), fn ($p) => $p['here']));
        foreach (array_slice($here, 0, 9) as $i => $p) {
            $invited = $i < 6;
            $at = now()->subDays(9 - $i)->subHours($i);
            $row = new EventInvitation;
            $row->forceFill(['company_id' => $companyId, 'budget_program_id' => $eventId, 'template_id' => $templateId, 'name' => $p['name'], 'name_key' => $p['key'], 'source' => $p['source'] === 'invited' ? 'typed' : $p['source'],
                'source_note' => $p['note'], 'contribution_record_id' => $p['pledge_id'], 'status' => $invited ? 'invited' : 'made', 'invited_at' => $invited ? $at : null, 'invited_by_id' => $invited ? $byId : null,
                'cards_made' => 1, 'last_format' => $i % 2 === 0 ? 'png' : 'pdf', 'last_card_at' => $at, 'created_by_id' => $byId]);
            $row->save();
        }
    }

    /** Marked by mistake: back to not invited. */
    public function unmark(User $by, BudgetProgram $program, int $id): EventInvitation
    {
        $this->mayWrite($by, $program);
        $row = EventInvitation::withoutGlobalScopes()->where('company_id', $program->company_id)->where('budget_program_id', $program->id)->where('is_deleted', 0)->find($id);
        if ($row === null) {
            throw BusinessRuleException::make('not_found', 'This person is no longer on the list.');
        }
        $row->status = 'made';
        $row->invited_at = null;
        $row->invited_by_id = null;
        $row->is_deleted = (int) $row->cards_made === 0; // no card either: nothing left to log (it comes back if they are marked again)
        $row->save();

        return $row;
    }

    /** The same from a phone, which knows the person by name. Nobody on the list by that name: nothing to do, and null says so. */
    public function unmarkByName(User $by, BudgetProgram $program, string $name): ?EventInvitation
    {
        $this->mayWrite($by, $program);
        $id = EventInvitation::withoutGlobalScopes()->where('company_id', $program->company_id)->where('budget_program_id', $program->id)->where('name_key', self::key($name))->where('is_deleted', 0)->value('id');

        return $id === null ? null : $this->unmark($by, $program, (int) $id);
    }

    private function mayWrite(User $by, BudgetProgram $program): void
    {
        if (! EventBook::isOpen($program->status)) {
            throw BusinessRuleException::make('event_closed', 'This event is closed. Reopen it to change its invitation list.');
        }
        if (! BudgetAccess::can($by, (int) $program->id, BudgetAccess::PLEDGE)) {
            throw BusinessRuleException::make('forbidden', 'Your role on this event does not allow this. Ask the chair.');
        }
    }

    private function onRow(User $by, BudgetProgram $program, string $name, ?int $templateId, callable $change): EventInvitation
    {
        $clean = self::cleanName($name);
        $key = self::key($clean);
        if ($key === '') {
            throw BusinessRuleException::make('validation', 'Type the name first.');
        }
        $cid = (int) $program->company_id;
        $eid = (int) $program->id;
        // Who they are comes from the books, never from the browser.
        $known = collect($this->people($cid, $eid))->firstWhere('key', $key);
        $template = $templateId === null ? null : DB::table('event_card_templates')->where('company_id', $cid)->where('budget_program_id', $eid)->where('id', $templateId)->value('id');

        return DB::transaction(function () use ($by, $cid, $eid, $clean, $key, $known, $template, $change) {
            DB::table('budget_programs')->where('id', $eid)->lockForUpdate()->value('id');
            $row = EventInvitation::withoutGlobalScopes()->where('budget_program_id', $eid)->where('name_key', $key)->first();
            if ($row === null) {
                $source = $known['source'] ?? 'typed';
                $row = new EventInvitation;
                $row->forceFill(['company_id' => $cid, 'budget_program_id' => $eid, 'name' => $clean, 'name_key' => $key, 'source' => $source === 'invited' ? 'typed' : $source,
                    'source_note' => ($known['source'] ?? '') === 'other_event' ? 'Pledged on another event' : ($known['note'] ?? null), 'contribution_record_id' => $known['pledge_id'] ?? null, 'status' => 'made', 'created_by_id' => $by->id]);
            }
            $row->name = $clean; // the same person, as now printed: a title added or dropped
            $row->is_deleted = false;
            if ($template !== null) {
                $row->template_id = (int) $template;
            }
            $change($row);
            $row->save();

            return $row;
        });
    }
}
