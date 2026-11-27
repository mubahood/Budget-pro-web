<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityBallot;
use App\Models\UnityBallotOption;
use App\Models\UnityBallotQuestion;
use App\Models\UnityVote;
use App\Services\Billing\Quotas;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;

/**
 * Elections and polls (M7). A ballot is drafted, opened, then closed. Each eligible member votes once per question; the
 * vote names the option and the voter only so they cannot vote twice: results are counts, and nobody, the chair included,
 * is shown who chose what. Elections need the plan feature unity_elections.
 */
final class Ballots
{
    public const KINDS = ['election', 'poll'];

    public const ELIGIBILITY = ['current', 'paid_up'];

    /** The members who may vote: current (in the ballot's chapter when it has one), and paid up when the ballot says so. */
    public static function eligible(int $cid, object $ballot): \Illuminate\Database\Query\Builder
    {
        $q = MemberFilters::current($cid)->when($ballot->chapter_id, fn ($w) => $w->where('chapter_id', $ballot->chapter_id));
        if ($ballot->eligibility === 'paid_up') {
            Memberships::whereStanding($q, Memberships::GOOD, LocalDate::today($cid));
        }

        return $q;
    }

    public static function assertPlan(Company $company, string $kind): void
    {
        if ($kind === 'election' && ! (new Quotas())->featureOn($company, 'unity_elections')) {
            throw BusinessRuleException::make('feature_not_in_plan', 'Elections come with the Organisation plan. Polls work on every plan.');
        }
    }

    /**
     * @param  array<string, mixed>  $d  {kind, title, description?, chapter_id?, opens_at?, closes_at?, eligibility, questions: [{title, options: [{label, member_id?}]}]}
     */
    public static function create(Company $company, array $d, int $userId): UnityBallot
    {
        $cid = (int) $company->id;
        self::assertPlan($company, $d['kind']);
        self::checkQuestions($cid, $d['questions']);

        return DB::transaction(function () use ($cid, $d, $userId) {
            $b = UnityBallot::create(['company_id' => $cid, 'kind' => $d['kind'], 'title' => $d['title'], 'description' => $d['description'] ?? null,
                'chapter_id' => $d['chapter_id'] ?? null, 'opens_at' => isset($d['opens_at']) ? Meetings::parse($cid, $d['opens_at']) : null,
                'closes_at' => isset($d['closes_at']) ? Meetings::parse($cid, $d['closes_at']) : null, 'status' => 'draft',
                'eligibility' => $d['eligibility'] ?? 'current', 'created_by_id' => $userId]);
            self::writeQuestions($b, $d['questions']);

            return $b->fresh();
        });
    }

    /** Changes a draft (the questions too, when sent: they replace the old ones). */
    public static function update(Company $company, UnityBallot $b, array $d): UnityBallot
    {
        if ($b->status !== 'draft') {
            throw BusinessRuleException::make('not_draft', 'An open or closed ballot is not changed.');
        }
        $cid = (int) $company->id;
        if (isset($d['kind'])) {
            self::assertPlan($company, $d['kind']);
        }
        if (isset($d['questions'])) {
            self::checkQuestions($cid, $d['questions']);
        }

        return DB::transaction(function () use ($cid, $b, $d) {
            foreach (['kind', 'title', 'description', 'chapter_id', 'eligibility'] as $k) {
                if (array_key_exists($k, $d)) {
                    $b->{$k} = $d[$k];
                }
            }
            foreach (['opens_at', 'closes_at'] as $k) {
                if (array_key_exists($k, $d)) {
                    $b->{$k} = $d[$k] === null ? null : Meetings::parse($cid, $d[$k]);
                }
            }
            $b->save();
            if (isset($d['questions'])) {
                $qids = UnityBallotQuestion::withoutGlobalScopes()->where('ballot_id', $b->id)->pluck('id');
                UnityBallotOption::withoutGlobalScopes()->whereIn('question_id', $qids)->delete();
                UnityBallotQuestion::withoutGlobalScopes()->where('ballot_id', $b->id)->delete();
                self::writeQuestions($b, $d['questions']);
            }

            return $b->fresh();
        });
    }

    public static function open(Company $company, UnityBallot $b): UnityBallot
    {
        if ($b->status !== 'draft') {
            throw BusinessRuleException::make('not_draft', 'Only a draft is opened.');
        }
        self::assertPlan($company, $b->kind);
        $b->status = 'open';
        $b->opens_at ??= now();
        $b->save();

        return $b->fresh();
    }

    public static function close(UnityBallot $b): UnityBallot
    {
        if ($b->status !== 'open') {
            throw BusinessRuleException::make('not_open', 'Only an open ballot is closed.');
        }
        $b->status = 'closed';
        $b->closes_at = now()->lt($b->closes_at ?? now()) ? now() : ($b->closes_at ?? now());
        $b->save();

        return $b->fresh();
    }

    /** True while the ballot takes votes: open, and the closing time (if any) not passed. */
    public static function isOpen(UnityBallot $b): bool
    {
        return $b->status === 'open' && ($b->closes_at === null || now()->lt($b->closes_at)) && ($b->opens_at === null || now()->gte($b->opens_at));
    }

    /**
     * The member's vote, one option for each question of the ballot (all at once). 409 not_open, not_eligible, already_voted.
     *
     * @param  list<array{question_id: int, option_id: int}>  $answers
     */
    public static function vote(UnityBallot $b, SaccoMember $m, array $answers): int
    {
        $cid = (int) $b->company_id;
        if (! self::isOpen($b)) {
            throw BusinessRuleException::make('not_open', 'This ballot is not open for voting.');
        }
        if (! self::eligible($cid, $b)->where('id', $m->id)->exists()) {
            throw BusinessRuleException::make('not_eligible', $b->eligibility === 'paid_up' ? 'Only members with dues paid up may vote in this ballot.' : 'Only current members may vote in this ballot.');
        }
        $questions = UnityBallotQuestion::withoutGlobalScopes()->where('ballot_id', $b->id)->where('is_deleted', 0)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $options = UnityBallotOption::withoutGlobalScopes()->whereIn('question_id', $questions)->where('is_deleted', 0)->pluck('question_id', 'id');
        $chosen = [];
        foreach ($answers as $a) {
            $q = (int) $a['question_id'];
            $o = (int) $a['option_id'];
            if (! in_array($q, $questions, true) || (int) ($options[$o] ?? 0) !== $q || isset($chosen[$q])) {
                throw BusinessRuleException::make('validation', 'Choose one option from each question of this ballot.', ['answers' => ['Each answer is an option of its question, once.']]);
            }
            $chosen[$q] = $o;
        }
        if ($chosen === []) {
            throw BusinessRuleException::make('validation', 'Choose at least one option.', ['answers' => ['Required.']]);
        }

        return DB::transaction(function () use ($b, $m, $chosen, $cid) {
            if (UnityVote::withoutGlobalScopes()->whereIn('question_id', array_keys($chosen))->where('member_id', $m->id)->lockForUpdate()->exists()) {
                throw BusinessRuleException::make('already_voted', 'You have already voted on this.');
            }
            $now = now();
            foreach ($chosen as $q => $o) {
                try {
                    UnityVote::create(['company_id' => $cid, 'ballot_id' => $b->id, 'question_id' => $q, 'option_id' => $o, 'member_id' => $m->id, 'cast_at' => $now]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    throw BusinessRuleException::make('already_voted', 'You have already voted on this.');
                }
            }

            return count($chosen);
        });
    }

    /** @return array<string, mixed> the ballot with questions, options, my_vote, can_vote and (when shown) results */
    public static function show(UnityBallot $b, ?SaccoMember $me, bool $officer): array
    {
        $cid = (int) $b->company_id;
        $questions = UnityBallotQuestion::withoutGlobalScopes()->where('ballot_id', $b->id)->where('is_deleted', 0)->orderBy('sort_order')->orderBy('id')->get();
        $options = UnityBallotOption::withoutGlobalScopes()->whereIn('question_id', $questions->pluck('id'))->where('is_deleted', 0)->orderBy('sort_order')->orderBy('id')->get()->groupBy('question_id');
        $mine = $me === null ? [] : UnityVote::withoutGlobalScopes()->where('ballot_id', $b->id)->where('member_id', $me->id)->pluck('option_id')->map(fn ($i) => (int) $i)->all();
        $showResults = $b->status === 'closed' || $officer;
        $counts = $showResults ? DB::table('unity_votes')->where('ballot_id', $b->id)->where('is_deleted', 0)->selectRaw('option_id, COUNT(*) AS n')->groupBy('option_id')->pluck('n', 'option_id') : collect();
        $photoIds = DB::table('sacco_members')->where('company_id', $cid)->whereIn('id', $options->flatten()->pluck('member_id')->filter()->all())->whereNotNull('photo_file_id')->pluck('photo_file_id', 'id');
        $links = UnityLinks::photos($cid, $photoIds->values()->map(fn ($i) => (int) $i)->all());
        $candidates = $photoIds->map(fn ($f) => $links[(int) $f] ?? null)->all();
        $eligible = (int) self::eligible($cid, $b)->count();
        $voted = (int) DB::table('unity_votes')->where('ballot_id', $b->id)->where('is_deleted', 0)->distinct()->count('member_id');

        return self::shape($b) + [
            'questions' => $questions->map(fn ($q) => ['id' => (int) $q->id, 'title' => $q->title,
                'options' => ($options[$q->id] ?? collect())->map(fn ($o) => ['id' => (int) $o->id, 'label' => $o->label,
                    'member_id' => $o->member_id ? (int) $o->member_id : null, 'photo_url' => $o->member_id ? ($candidates[(int) $o->member_id] ?? null) : null])->values()->all()])->values()->all(),
            'my_vote' => $mine,
            'can_vote' => $me !== null && $mine === [] && self::isOpen($b) && self::eligible($cid, $b)->where('id', $me->id)->exists(),
            'results' => ! $showResults ? null : ['turnout' => ['voted' => $voted, 'eligible' => $eligible, 'pct' => $eligible > 0 ? round($voted * 100 / $eligible, 1) : null],
                'questions' => $questions->map(fn ($q) => ['question_id' => (int) $q->id, 'options' => ($options[$q->id] ?? collect())
                    ->map(fn ($o) => ['option_id' => (int) $o->id, 'label' => $o->label, 'votes' => (int) ($counts[$o->id] ?? 0)])->sortByDesc('votes')->values()->all()])->values()->all()],
        ];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityBallot $b): array
    {
        $cid = (int) $b->company_id;

        return ['id' => (int) $b->id, 'uuid' => $b->uuid, 'kind' => $b->kind, 'title' => $b->title, 'description' => $b->description,
            'chapter_id' => $b->chapter_id ? (int) $b->chapter_id : null, 'opens_at' => Meetings::iso($cid, $b->getRawOriginal('opens_at')),
            'closes_at' => Meetings::iso($cid, $b->getRawOriginal('closes_at')), 'status' => $b->status, 'eligibility' => $b->eligibility, 'version' => (int) $b->version];
    }

    /** @param list<array<string, mixed>> $questions */
    private static function checkQuestions(int $cid, array $questions): void
    {
        $ids = [];
        foreach ($questions as $q) {
            if (count($q['options'] ?? []) < 2) {
                throw BusinessRuleException::make('validation', 'Each question needs at least two options.', ['questions' => ['At least two options each.']]);
            }
            foreach ($q['options'] as $o) {
                if (! empty($o['member_id'])) {
                    $ids[] = (int) $o['member_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== [] && MemberFilters::current($cid)->whereIn('id', $ids)->count() !== count($ids)) {
            throw BusinessRuleException::make('validation', 'A candidate must be a current member of the group.', ['questions' => ['Unknown candidate.']]);
        }
    }

    /** @param list<array<string, mixed>> $questions */
    private static function writeQuestions(UnityBallot $b, array $questions): void
    {
        foreach (array_values($questions) as $i => $q) {
            $question = UnityBallotQuestion::create(['company_id' => $b->company_id, 'ballot_id' => $b->id, 'title' => $q['title'], 'sort_order' => $i]);
            foreach (array_values($q['options']) as $j => $o) {
                UnityBallotOption::create(['company_id' => $b->company_id, 'question_id' => $question->id, 'label' => $o['label'],
                    'member_id' => $o['member_id'] ?? null, 'sort_order' => $j]);
            }
        }
    }
}
