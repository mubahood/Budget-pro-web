<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\KinBranch;
use App\Models\KinEvent;
use App\Models\KinEventPerson;
use App\Models\SaccoDuesProgramme;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Sacco\Dues\DuesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Family gatherings (doc 13.2): meetings, weddings, burials, introductions. kin_events (the head and the secretary) plans
 * them, keeps the register and writes the minutes; each person answers for themself (rsvp). A gathering may carry a
 * one-time contribution (a dues programme), whose per-person amounts are the pledges.
 */
class KinEvents
{
    public function __construct(private DuesService $dues) {}

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$req, Rule::in(KinEvent::KINDS)],
            'title' => [$req, 'string', 'max:150'],
            'starts_at' => [$req, 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'venue' => ['sometimes', 'nullable', 'string', 'max:190'],
            'venue_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'with_contribution' => [$creating ? 'sometimes' : 'prohibited', 'array'],
            'with_contribution.name' => ['required_with:with_contribution', 'string', 'max:120'],
            'with_contribution.target_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.DuesService::MAX_MINOR],
            'with_contribution.amount_mode' => ['sometimes', Rule::in(DuesService::AMOUNT_MODES)],
            'with_contribution.amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.DuesService::MAX_MINOR],
        ];
    }

    public static function find(int $companyId, int $id): KinEvent
    {
        return KinEvent::withoutGlobalScopes()->where('company_id', $companyId)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That gathering was not found.');
    }

    /** @param array<string, mixed> $d */
    public function create(Company $company, array $d, User $by): KinEvent
    {
        $cid = (int) $company->id;
        $this->checkBranch($cid, $d['branch_id'] ?? null);
        $this->checkTimes($d['starts_at'], $d['ends_at'] ?? null);

        return DB::transaction(function () use ($company, $cid, $d, $by) {
            $programmeId = null;
            if (! empty($d['with_contribution'])) {
                $c = $d['with_contribution'];
                $mode = (string) ($c['amount_mode'] ?? 'any');
                $p = $this->dues->create($company, ['name' => $c['name'], 'kind' => 'once', 'amount_mode' => $mode, 'amount_minor' => (int) ($c['amount_minor'] ?? 0),
                    'target_minor' => $c['target_minor'] ?? null, 'description' => mb_substr('For '.$d['title'], 0, 1000)], $by);
                $programmeId = (int) $p->id;
            }

            return KinEvent::create(['company_id' => $cid, 'kind' => $d['kind'], 'title' => $d['title'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'] ?? null,
                'venue' => $d['venue'] ?? null, 'venue_url' => $d['venue_url'] ?? null, 'description' => $d['description'] ?? null, 'agenda' => $d['agenda'] ?? null,
                'status' => 'planned', 'branch_id' => $d['branch_id'] ?? null, 'dues_programme_id' => $programmeId, 'created_by' => $by->id])->fresh();
        });
    }

    /** @param array<string, mixed> $d */
    public function update(KinEvent $e, array $d): KinEvent
    {
        if ($e->status === 'cancelled') {
            throw BusinessRuleException::make('event_cancelled', 'This gathering was cancelled.');
        }
        if (array_key_exists('branch_id', $d)) {
            $this->checkBranch((int) $e->company_id, $d['branch_id']);
        }
        $this->checkTimes($d['starts_at'] ?? $e->starts_at, array_key_exists('ends_at', $d) ? $d['ends_at'] : $e->ends_at);
        $e->fill(array_intersect_key($d, array_flip(['kind', 'title', 'starts_at', 'ends_at', 'venue', 'venue_url', 'description', 'agenda', 'branch_id'])))->save();

        return $e->fresh();
    }

    /** Each person answers for themself (the sign-in's person, meta.me_id). */
    public function rsvp(KinEvent $e, User $user, string $answer): KinEventPerson
    {
        $me = DuesService::personOf($user, (int) $e->company_id)
            ?? throw BusinessRuleException::make('no_person', 'Your sign-in is not linked to a person in the family yet. Ask the head of the family to link it.');
        if ($e->status !== 'planned') {
            throw BusinessRuleException::make($e->status === 'cancelled' ? 'event_cancelled' : 'event_over', $e->status === 'cancelled' ? 'This gathering was cancelled.' : 'This gathering has taken place.');
        }

        return $this->mark($e, (int) $me->id, ['rsvp' => $answer]);
    }

    /** The register. @param list<array{member_id: int, attendance: string}> $lines */
    public function attendance(KinEvent $e, array $lines): int
    {
        if ($e->status === 'cancelled') {
            throw BusinessRuleException::make('event_cancelled', 'This gathering was cancelled.');
        }
        $ids = array_values(array_unique(array_map(fn ($l) => (int) $l['member_id'], $lines)));
        $found = SaccoMember::withoutGlobalScopes()->where('company_id', $e->company_id)->where('is_deleted', 0)->whereIn('id', $ids)->count();
        if ($found !== count($ids)) {
            throw BusinessRuleException::make('not_found', 'One of those people is not in this family.');
        }
        DB::transaction(function () use ($e, $lines) {
            foreach ($lines as $l) {
                $this->mark($e, (int) $l['member_id'], ['attendance' => $l['attendance']]);
            }
        });

        return count($ids);
    }

    public function minutes(KinEvent $e, string $minutes, User $by): KinEvent
    {
        if ($e->status === 'cancelled') {
            throw BusinessRuleException::make('event_cancelled', 'This gathering was cancelled.');
        }
        $e->fill(['minutes' => $minutes, 'minutes_by' => $by->id, 'status' => 'held'])->save();

        return $e->fresh();
    }

    public function cancel(KinEvent $e, string $reason): KinEvent
    {
        if ($e->status === 'held') {
            throw BusinessRuleException::make('event_over', 'This gathering has taken place; it cannot be cancelled.');
        }
        $e->fill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

        return $e->fresh();
    }

    /** @param array<string, mixed> $values */
    private function mark(KinEvent $e, int $memberId, array $values): KinEventPerson
    {
        $row = KinEventPerson::withoutGlobalScopes()->firstOrNew(['event_id' => $e->id, 'member_id' => $memberId], ['company_id' => $e->company_id]);
        $row->fill($values)->save();

        return $row;
    }

    private function checkBranch(int $cid, mixed $branchId): void
    {
        if ($branchId !== null && ! KinBranch::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereKey((int) $branchId)->exists()) {
            throw BusinessRuleException::make('not_found', 'That branch is not in this family.');
        }
    }

    private function checkTimes(mixed $starts, mixed $ends): void
    {
        if ($ends !== null && \Carbon\Carbon::parse($ends)->lt(\Carbon\Carbon::parse($starts))) {
            throw BusinessRuleException::make('dates_invalid', 'The end is before the start.', ['ends_at' => ['Before the start.']]);
        }
    }

    // ---------------------------------------------------------------- what the API shows

    /** @param Collection<int, KinEvent> $events @return list<array<string, mixed>> */
    public function list(Collection $events, ?int $meId): array
    {
        $ids = $events->pluck('id')->all();
        $counts = $ids === [] ? collect() : DB::table('kin_event_people')->whereIn('event_id', $ids)
            ->selectRaw("event_id, SUM(rsvp = 'going') AS going, SUM(rsvp = 'maybe') AS maybe, SUM(rsvp = 'no') AS no_, SUM(attendance = 'present') AS present")
            ->groupBy('event_id')->get()->keyBy('event_id');
        $mine = $meId === null || $ids === [] ? collect() : DB::table('kin_event_people')->whereIn('event_id', $ids)->where('member_id', $meId)->pluck('rsvp', 'event_id');

        return $events->map(function (KinEvent $e) use ($counts, $mine) {
            $c = $counts[$e->id] ?? null;

            return ['id' => (int) $e->id, 'kind' => $e->kind, 'title' => $e->title, 'starts_at' => $e->starts_at?->toIso8601String(), 'ends_at' => $e->ends_at?->toIso8601String(),
                'venue' => $e->venue, 'status' => $e->status, 'branch_id' => $e->branch_id ? (int) $e->branch_id : null,
                'going' => (int) ($c->going ?? 0), 'maybe' => (int) ($c->maybe ?? 0), 'no' => (int) ($c->no_ ?? 0), 'present' => (int) ($c->present ?? 0),
                'my_rsvp' => $mine[$e->id] ?? null, 'dues_programme_id' => $e->dues_programme_id ? (int) $e->dues_programme_id : null];
        })->values()->all();
    }

    /** @return array{event: array<string, mixed>, people: list<array<string, mixed>>, contribution: ?array<string, mixed>} */
    public function detail(KinEvent $e, ?int $meId): array
    {
        $cid = (int) $e->company_id;
        $row = $this->list(collect([$e]), $meId)[0];
        $authorName = $e->minutes_by ? User::withoutGlobalScopes()->whereKey($e->minutes_by)->get(['id', 'name', 'first_name', 'last_name'])
            ->map(fn ($u) => trim((string) ($u->name ?: $u->first_name.' '.$u->last_name)))->first() : null;
        $absent = DB::table('kin_event_people')->where('event_id', $e->id)->where('attendance', 'apology')->count();
        $event = $row + ['venue_url' => $e->venue_url, 'description' => $e->description, 'agenda' => $e->agenda, 'minutes' => $e->minutes,
            'minutes_by' => $e->minutes_by ? (int) $e->minutes_by : null, 'minutes_by_name' => $authorName ?: null, 'cancel_reason' => $e->cancel_reason,
            'apology' => $absent, 'branch_name' => $e->branch_id ? KinBranch::withoutGlobalScopes()->where('company_id', $cid)->whereKey($e->branch_id)->value('name') : null,
            'created_by' => $e->created_by ? (int) $e->created_by : null, 'created_at' => $e->created_at?->toIso8601String(), 'updated_at' => $e->updated_at?->toIso8601String()];

        // Who is asked: the living people of the family (or of the branch), and anyone already answered or marked.
        $marks = KinEventPerson::withoutGlobalScopes()->where('event_id', $e->id)->get()->keyBy('member_id');
        $people = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNotIn('status', ['deceased', 'exited'])->whereNull('date_of_death')
                ->when($e->branch_id, fn ($b) => $b->where('branch_id', $e->branch_id)))->orWhereIn('id', $marks->keys()->all()))
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'other_names', 'last_name', 'photo_file_id']);
        $photos = DuesService::photoUrls($cid, $people->pluck('photo_file_id')->filter()->map(fn ($v) => (int) $v)->values()->all());

        $contribution = null;
        if ($e->dues_programme_id) {
            $p = SaccoDuesProgramme::withoutGlobalScopes()->where('company_id', $cid)->find($e->dues_programme_id);
            $contribution = $p ? $this->dues->present($p) : null;
        }

        return ['event' => $event, 'people' => $people->map(fn ($m) => ['member_id' => (int) $m->id, 'name' => $m->full_name,
            'photo_url' => $m->photo_file_id ? ($photos[(int) $m->photo_file_id] ?? null) : null,
            'rsvp' => $marks[$m->id]->rsvp ?? null, 'attendance' => $marks[$m->id]->attendance ?? null])->values()->all(), 'contribution' => $contribution];
    }
}
