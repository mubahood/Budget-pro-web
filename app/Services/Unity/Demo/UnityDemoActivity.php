<?php

namespace App\Services\Unity\Demo;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityBallot;
use App\Models\UnityChapter;
use App\Models\UnityCommittee;
use App\Models\UnityCommitteeMember;
use App\Models\UnityDrive;
use App\Models\UnityMeeting;
use App\Models\UnityResolution;
use App\Models\UnitySetting;
use App\Models\UnityWelfareRequest;
use App\Models\User;
use App\Services\Unity\Ballots;
use App\Services\Unity\Drives;
use App\Services\Unity\Meetings;
use App\Services\Unity\Voice;
use App\Services\Unity\Welfare;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A year in the life of the demo association, after its members are in (UnityDemoBuilder): committees, a year of
 * chapter meetings with attendance and resolutions, the AGM, an upcoming general meeting and a dinner with RSVPs, a levy
 * and a harambee, the welfare fund and its requests, a closed election, an open poll and announcements.
 *
 * Attendance is shaped so the home screen has a story: Mbarara attends best, Jinja improved sharply this quarter, and a
 * few members have stopped coming. Picks use UnityDemoBuilder::pick (crc32), so every build is the same.
 */
final class UnityDemoActivity
{
    /** Attendance rate by chapter index (Jinja's is set by quarter below). */
    private const RATES = [0 => 64, 1 => 58, 2 => 52, 3 => 46, 4 => 47, 5 => 83, 6 => 69];

    private const VENUES = ['Main hall, Kampala office', 'Nansana community hall', 'Mukono resource centre', 'Jinja town hall annex',
        'Mbale municipal hall', 'Mbarara members\' room', 'Fort Portal social centre'];

    private const RESOLUTIONS = ['Collect the year\'s outstanding dues before the next meeting.', 'Visit members who have missed three meetings.',
        'Prepare the chapter report for the AGM.', 'Find a cheaper venue for chapter meetings.', 'Register the new members who applied this month.',
        'Raise ten members\' pledges for the bursary harambee.', 'Open a chapter mobile money account.', 'Plan the chapter\'s community clean-up day.'];

    private int $cid;

    private string $tz;

    /** @var array<int, UnityChapter> */
    private array $chapters;

    /** @var array<int, int> member id => chapter lead's member id, by chapter index */
    private array $leads = [];

    public function __construct(private Company $co, private array $users, private array $people, array $chapters, private Carbon $today)
    {
        $this->cid = (int) $co->id;
        $this->tz = (string) ($co->timezone ?: 'Africa/Kampala');
        $this->chapters = $chapters;
    }

    public function run(): void
    {
        UnitySetting::for($this->cid);
        $this->committees();
        $this->meetings();
        $this->drives();
        $this->welfare();
        $this->ballots();
        $this->announcements();
    }

    private static function pick(string $label, int $n): int
    {
        return UnityDemoBuilder::pick($label, $n);
    }

    /** Current members (active or dormant) who joined on or before $day, optionally in one chapter. @return array<int, array> index => person */
    private function current(?int $chap = null, ?Carbon $day = null, bool $activeOnly = false): array
    {
        return array_filter($this->people, fn (array $p) => in_array($p['fate'], $activeOnly ? ['current', 'approved'] : ['current', 'approved', 'dormant'], true)
            && $p['since'] !== null && ($day === null || $p['since'] <= $day->toDateString()) && ($chap === null || $p['chap'] === $chap));
    }

    private function member(int $i): SaccoMember
    {
        return SaccoMember::withoutGlobalScopes()->findOrFail($this->people[$i]['id']);
    }

    private function index(string $login): int
    {
        foreach ($this->people as $i => $p) {
            if ($p['login'] === $login) {
                return $i;
            }
        }

        throw new \LogicException("No member for {$login}.");
    }

    /** A local day and time as UTC (how meetings and ballots are stored). */
    private function at(Carbon $day, string $time): Carbon
    {
        return Carbon::parse($day->toDateString().' '.$time, $this->tz)->utc();
    }

    // ── Committees ─────────────────────────────────────────────────────

    private function committees(): void
    {
        $elected = $this->today->copy()->subDays(95)->toDateString();
        $used = array_map(fn (string $k) => $this->index($k), array_keys(UnityDemoBuilder::LOGINS));
        $take = function (?int $chap, string $seed) use (&$used): int {
            $pool = array_values(array_diff(array_keys($this->current($chap, $this->today->copy()->subYears(2), true)), $used));
            $i = $pool[UnityDemoBuilder::pick($seed, count($pool))];
            $used[] = $i;

            return $i;
        };
        $seat = function (UnityCommittee $c, int $i, string $title, int $order, string $from, ?string $to = null) {
            UnityCommitteeMember::create(['company_id' => $this->cid, 'committee_id' => $c->id, 'member_id' => $this->people[$i]['id'],
                'title' => $title, 'sort_order' => $order, 'starts_on' => $from, 'ends_on' => $to]);
        };

        $exec = UnityCommittee::create(['company_id' => $this->cid, 'chapter_id' => null, 'name' => 'Executive Committee', 'sort_order' => 0]);
        $seat($exec, $this->index('chair'), 'Chairperson', 1, $elected);
        $seat($exec, $take(null, 'vice'), 'Vice Chairperson', 2, $elected);
        $seat($exec, $this->index('secretary'), 'General Secretary', 3, $elected);
        $seat($exec, $this->index('treasurer'), 'Treasurer', 4, $elected);
        $seat($exec, $take(null, 'publicity'), 'Publicity Secretary', 5, $elected);
        $seat($exec, $take(null, 'welfare'), 'Welfare Secretary', 6, $elected);
        $seat($exec, $take(null, 'cm1'), 'Committee Member', 7, $elected);
        $seat($exec, $take(null, 'cm2'), 'Committee Member', 8, $elected);
        // The last term's vice chair served until the election (the history keeps them).
        $seat($exec, $take(null, 'oldvice'), 'Vice Chairperson', 2, $this->today->copy()->subYears(2)->subDays(95)->toDateString(), $elected);

        foreach ($this->chapters as $c => $chapter) {
            $com = UnityCommittee::create(['company_id' => $this->cid, 'chapter_id' => $chapter->id, 'name' => $chapter->name.' Chapter Committee', 'sort_order' => $c + 1]);
            $lead = $c === 0 ? $this->index('lead') : $take($c, "lead{$c}");
            $from = $this->today->copy()->subDays(300 + $c * 20)->toDateString();
            $seat($com, $lead, 'Chapter Lead', 1, $from);
            $seat($com, $take($c, "sec{$c}"), 'Secretary', 2, $from);
            $seat($com, $take($c, "tre{$c}"), 'Treasurer', 3, $from);
            $chapter->lead_member_id = $this->people[$lead]['id'];
            $chapter->save();
            $this->leads[$c] = $this->people[$lead]['id'];
        }
    }

    // ── Meetings ───────────────────────────────────────────────────────

    /**
     * Each chapter meets once a month (Kampala on the 6th, each next chapter three days later), for the last twelve months,
     * and next month's meetings are on the calendar. Then the AGM eight months ago, a general meeting in nine days and the
     * end-of-year dinner in three weeks, both taking RSVPs.
     */
    private function meetings(): void
    {
        $quarter = $this->today->copy()->firstOfQuarter();
        $createdBy = (int) $this->users['secretary']->id;
        $heldJinjaThisQuarter = false;
        foreach ($this->chapters as $c => $chapter) {
            for ($k = 12; $k >= -1; $k--) {
                $day = $this->today->copy()->startOfMonth()->subMonthsNoOverflow($k)->addDays(5 + $c * 3);
                if ($day->lt($this->today->copy()->subMonths(12))) {
                    continue;
                }
                $held = $day->lt($this->today);
                $mt = UnityMeeting::create(['company_id' => $this->cid, 'kind' => 'meeting', 'chapter_id' => $chapter->id,
                    'title' => $chapter->name.' chapter meeting, '.$day->format('F Y'), 'starts_at' => $this->at($day, '10:00'), 'ends_at' => $this->at($day, '12:30'),
                    'venue' => self::VENUES[$c], 'agenda' => "1. Opening and apologies\n2. Minutes of the last meeting\n3. Dues and drives\n4. Welfare\n5. Any other business",
                    'minutes' => $held ? 'Attendance taken. Dues, the drives and welfare requests were reviewed. Next meeting confirmed.' : null,
                    'status' => $held ? 'held' : 'scheduled', 'rsvp_open' => false, 'created_by_id' => $createdBy]);
                if (! $held) {
                    continue;
                }
                $rate = $c === 3 ? ($day->gte($quarter) ? 80 : 45) : self::RATES[$c];
                $rate += self::pick("rn{$c}-{$k}", 11) - 5;
                $this->mark($mt, $day, $this->current($c, $day), $rate);
                $heldJinjaThisQuarter = $heldJinjaThisQuarter || ($c === 3 && $day->gte($quarter));
                if ($k <= 6 && self::pick("res{$c}-{$k}", 100) < 40) {
                    $this->resolution($mt, $day, $this->leads[$c], "{$c}-{$k}");
                }
            }
        }
        // Early in a quarter Jinja may not have met yet: a special meeting this morning keeps its rise visible.
        if (! $heldJinjaThisQuarter) {
            $mt = UnityMeeting::create(['company_id' => $this->cid, 'kind' => 'meeting', 'chapter_id' => $this->chapters[3]->id,
                'title' => 'Jinja chapter special meeting', 'starts_at' => $this->at($this->today, '07:00'), 'venue' => self::VENUES[3],
                'minutes' => 'Special meeting on the chapter\'s drive pledges.', 'status' => 'held', 'created_by_id' => $createdBy]);
            $this->mark($mt, $this->today, $this->current(3, $this->today), 80);
        }

        $agmDay = $this->today->copy()->subDays(240);
        $agm = UnityMeeting::create(['company_id' => $this->cid, 'kind' => 'agm', 'chapter_id' => null, 'title' => 'Annual General Meeting '.$agmDay->year,
            'starts_at' => $this->at($agmDay, '09:00'), 'ends_at' => $this->at($agmDay, '16:00'), 'venue' => 'Main hall, Kampala office',
            'agenda' => "1. Chairperson's report\n2. Treasurer's report and audited accounts\n3. Welfare fund report\n4. Dues for the coming year\n5. Any other business",
            'minutes' => 'The AGM received the reports, kept the ordinary fee at UGX 50,000 a year and approved the bursary harambee.',
            'status' => 'held', 'created_by_id' => $createdBy]);
        $this->mark($agm, $agmDay, $this->current(null, $agmDay), 58);
        $this->resolution($agm, $agmDay, $this->people[$this->index('treasurer')]['id'], 'agm1', 'Present the audited accounts to every chapter.', true);
        $this->resolution($agm, $agmDay, $this->people[$this->index('chair')]['id'], 'agm2', 'Launch the bursary harambee for members\' children.', true);
        $this->resolution($agm, $agmDay, $this->people[$this->index('secretary')]['id'], 'agm3', 'Bring every member\'s record up to date, with a photo and next of kin.', false);

        $general = UnityMeeting::create(['company_id' => $this->cid, 'kind' => 'meeting', 'chapter_id' => null, 'title' => 'General meeting: half-year review',
            'starts_at' => $this->at($this->today->copy()->addDays(9), '10:00'), 'ends_at' => $this->at($this->today->copy()->addDays(9), '13:00'),
            'venue' => 'Main hall, Kampala office', 'agenda' => "1. Half-year report\n2. The levy and the harambee\n3. Plans for the AGM", 'status' => 'scheduled',
            'rsvp_open' => true, 'created_by_id' => $createdBy]);
        $dinner = UnityMeeting::create(['company_id' => $this->cid, 'kind' => 'event', 'chapter_id' => null, 'title' => 'Members\' end-of-year dinner',
            'starts_at' => $this->at($this->today->copy()->addDays(20), '18:30'), 'ends_at' => $this->at($this->today->copy()->addDays(20), '22:00'),
            'venue' => 'Lakeside gardens, Munyonyo', 'agenda' => 'Dinner, the year in pictures and awards for the best chapter.', 'status' => 'scheduled',
            'rsvp_open' => true, 'capacity' => 150, 'created_by_id' => $createdBy]);
        $answers = ['yes', 'yes', 'yes', 'yes', 'maybe', 'no'];
        foreach ($this->current(null, null, true) as $i => $p) {
            if ($p['login'] !== null) {
                continue; // the sign-ins answer for themselves in the demo
            }
            if (self::pick("gm{$i}", 100) < 16) {
                Meetings::rsvp($general, $this->member($i), $answers[self::pick("ga{$i}", 6)]);
            }
            if (self::pick("dn{$i}", 100) < 30) {
                Meetings::rsvp($dinner, $this->member($i), $answers[self::pick("da{$i}", 6)]);
            }
        }
    }

    /**
     * Marks a held meeting: present by the rate, absent with an apology now and then. Dormant members rarely come, and a
     * handful of members stopped coming three months ago (the "missed three meetings" list).
     *
     * @param  array<int, array>  $expected
     */
    private function mark(UnityMeeting $mt, Carbon $day, array $expected, int $rate): void
    {
        if ($expected === []) {
            return;
        }
        $now = now();
        $seq = SyncSequence::reserve(count($expected));
        $start = $this->at($day, '10:00');
        $rows = [];
        foreach ($expected as $i => $p) {
            $roll = self::pick("at{$mt->id}-{$i}", 100);
            $stopped = self::pick("dr{$i}", 100) < 7 && $day->gte($this->today->copy()->subDays(100));
            $present = ! $stopped && $roll < ($p['fate'] === 'dormant' ? 15 : $rate);
            $rows[] = ['company_id' => $this->cid, 'uuid' => (string) Str::uuid(), 'meeting_id' => $mt->id, 'member_id' => $p['id'],
                'present' => $present, 'apology' => ! $present && ! $stopped && $roll % 4 === 0,
                'checked_in_at' => $present ? $start->copy()->addMinutes(self::pick("ci{$mt->id}-{$i}", 40)) : null,
                'method' => $present ? ($roll % 3 === 0 ? 'qr' : 'list') : null,
                'server_seq' => $seq++, 'version' => 1, 'is_deleted' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('unity_attendance')->insert($chunk);
        }
    }

    private function resolution(UnityMeeting $mt, Carbon $day, int $ownerId, string $seed, ?string $text = null, ?bool $done = null): void
    {
        $due = $day->copy()->addDays(30);
        $done ??= $due->lt($this->today) && self::pick("rd{$seed}", 3) > 0;
        UnityResolution::create(['company_id' => $this->cid, 'meeting_id' => $mt->id, 'text' => $text ?? self::RESOLUTIONS[self::pick("rt{$seed}", count(self::RESOLUTIONS))],
            'owner_member_id' => $ownerId, 'due_on' => $due->toDateString(), 'status' => $done ? 'done' : 'open',
            'done_on' => $done ? $due->copy()->subDays(3)->min($this->today)->toDateString() : null]);
    }

    // ── Drives ─────────────────────────────────────────────────────────

    /** A levy on every member (due last month, so the unpaid are overdue) and the bursary harambee (pledges, some overdue). */
    private function drives(): void
    {
        $by = (int) $this->users['treasurer']->id;
        $levy = UnityDrive::create(['company_id' => $this->cid, 'kind' => 'levy', 'title' => 'Office rent levy '.$this->today->year,
            'description' => 'Each member\'s share of the year\'s office rent, agreed at the AGM.', 'per_member_minor' => 20000,
            'due_on' => $this->today->copy()->subDays(25)->toDateString(), 'status' => 'open', 'created_by_id' => (int) $this->users['chair']->id]);
        Drives::apply($levy);
        foreach ($this->current() as $i => $p) {
            $r = self::pick("lv{$i}", 100);
            $paid = $p['fate'] === 'dormant' ? ($r < 10 ? 20000 : 0) : ($r < 64 ? 20000 : ($r < 76 ? 10000 : 0));
            if ($paid > 0 && $p['login'] !== 'member') {
                Drives::pay($levy, $this->member($i), ['amount_minor' => $paid, 'method' => $r % 3 === 0 ? 'cash' : 'mobile_money',
                    'paid_on' => $this->today->copy()->subDays(5 + self::pick("lvd{$i}", 70))->max($p['since'])->toDateString()], $by);
            }
        }

        $harambee = UnityDrive::create(['company_id' => $this->cid, 'kind' => 'harambee', 'title' => 'Bursary harambee for members\' children',
            'description' => 'School fees for members\' children who passed well but cannot pay. Every pledge counts.', 'target_minor' => 12000000,
            'due_on' => $this->today->copy()->addDays(40)->toDateString(), 'status' => 'open', 'created_by_id' => (int) $this->users['chair']->id]);
        $amounts = [50000, 50000, 100000, 100000, 100000, 150000, 200000, 300000, 500000];
        foreach ($this->current(null, null, true) as $i => $p) {
            if (self::pick("hb{$i}", 100) >= 27 && ! in_array($p['login'], ['chair', 'treasurer'], true)) {
                continue;
            }
            $m = $this->member($i);
            $amount = $amounts[self::pick("ha{$i}", count($amounts))];
            $ownDue = self::pick("hd{$i}", 100) < 40 ? $this->today->copy()->subDays(10 + self::pick("hdd{$i}", 40))->toDateString() : null;
            Drives::pledge($harambee, $m, $amount, $ownDue);
            $r = self::pick("hp{$i}", 100);
            $paid = $r < 45 ? $amount : ($r < 72 ? intdiv($amount, 2) : 0);
            if ($paid > 0) {
                Drives::pay($harambee, $m, ['amount_minor' => $paid, 'method' => $r % 2 ? 'mobile_money' : 'bank',
                    'paid_on' => $this->today->copy()->subDays(1 + self::pick("hpd{$i}", 70))->toDateString()], $by);
            }
        }
    }

    // ── Welfare ────────────────────────────────────────────────────────

    /** A year of contributions, the dinner's proceeds, small costs, and requests in every state. */
    private function welfare(): void
    {
        $by = (int) $this->users['treasurer']->id;
        $people = $this->current(null, null, true);
        $keys = array_keys($people);
        for ($k = 11; $k >= 0; $k--) {
            $month = $this->today->copy()->startOfMonth()->subMonthsNoOverflow($k);
            for ($j = 0; $j < 18; $j++) {
                $i = $keys[self::pick("wc{$k}-{$j}", count($keys))];
                $on = $month->copy()->addDays(self::pick("wcd{$k}-{$j}", 27))->min($this->today);
                if ($people[$i]['since'] > $on->toDateString()) {
                    continue;
                }
                Welfare::entry($this->cid, ['direction' => 'in', 'kind' => 'contribution', 'amount_minor' => self::pick("wca{$k}-{$j}", 3) === 0 ? 20000 : 10000,
                    'member_id' => $people[$i]['id'], 'on' => $on->toDateString(), 'method' => 'mobile_money', 'note' => 'Monthly welfare contribution'], $by);
            }
        }
        Welfare::entry($this->cid, ['direction' => 'in', 'kind' => 'income', 'amount_minor' => 1800000, 'on' => $this->today->copy()->subDays(150)->toDateString(),
            'method' => 'bank', 'note' => 'Proceeds of the fundraising walk'], $by);
        Welfare::entry($this->cid, ['direction' => 'out', 'kind' => 'expense', 'amount_minor' => 45000, 'on' => $this->today->copy()->subDays(60)->toDateString(),
            'method' => 'bank', 'note' => 'Bank charges for the half year'], $by);

        $treasurer = $this->users['treasurer'];
        $chair = $this->users['chair'];
        $requests = [
            // [seed, kind, details, requested, approved, state, days ago]
            ['w1', 'bereavement', 'Burial of my father in Tororo.', 500000, 400000, 'paid', 200],
            ['w2', 'sickness', 'Hospital bill after an operation.', 600000, 350000, 'paid', 160],
            ['w3', 'birth', 'Birth of our twins.', 200000, 150000, 'paid', 120],
            ['w4', 'bereavement', 'Burial of my mother.', 500000, 400000, 'paid', 75],
            ['w5', 'sickness', 'Treatment for malaria and admission for a week.', 300000, 200000, 'paid', 40],
            ['w6', 'wedding', 'Contribution towards my wedding.', 400000, 250000, 'approved', 12],
            ['w7', 'sickness', 'Physiotherapy after a road accident.', 450000, 300000, 'approved', 6],
            ['w8', 'other', 'School fees for a nephew.', 800000, null, 'rejected', 50],
            ['w9', 'sickness', 'A second request for the same treatment already paid.', 200000, null, 'rejected', 30],
            ['w10', 'bereavement', 'Burial of my brother in Mbale.', 500000, null, 'pending', 3],
            ['w11', 'birth', 'Birth of our first child.', 200000, null, 'pending', 2],
            ['w12', 'sickness', 'Medicine for my diabetic mother.', 250000, null, 'pending', 1],
        ];
        $taken = [];
        foreach ($requests as [$seed, $kind, $details, $asked, $approved, $state, $ago]) {
            do {
                $i = $keys[self::pick("{$seed}-".count($taken), count($keys))];
                $seed .= 'x';
            } while (isset($taken[$i]) || $people[$i]['login'] !== null);
            $taken[$i] = true;
            $day = $this->today->copy()->subDays($ago);
            $r = Welfare::request($this->member($i), $kind, $details, $asked, (int) $this->users['secretary']->id);
            $decided = $day->copy()->addDays(min(4, $ago))->min($this->today);
            if ($state === 'rejected') {
                $r = Welfare::reject($r, $chair, $kind === 'other' ? 'School fees are outside the welfare rules; the bursary harambee covers members\' children only.' : 'Already supported for this treatment.');
            } elseif ($approved !== null) {
                $r = Welfare::approve($r, $chair, $approved, 'Approved by the Executive Committee.');
                if ($state === 'paid') {
                    $r = Welfare::pay($r, $treasurer, 'mobile_money', $decided->copy()->addDays(1)->min($this->today)->toDateString(), null);
                }
            }
            DB::table('unity_welfare_requests')->where('id', $r->id)->update(['created_at' => $day->copy()->setTime(9, 0),
                'decided_on' => $state === 'pending' ? null : $decided->toDateString()]);
        }
    }

    // ── Ballots ────────────────────────────────────────────────────────

    /**
     * The Executive Committee election three months ago (closed; paid-up members voted) and an open poll on next year's
     * AGM venue. Votes are cast through Ballots::vote, then dated inside the ballot's window. The sign-ins have not voted
     * in the poll, so a visitor can.
     */
    private function ballots(): void
    {
        $chairUserId = (int) $this->users['chair']->id;
        $pool = array_values(array_filter($this->current(null, $this->today->copy()->subYears(2), true), fn (array $p) => $p['login'] === null));
        $rival = $pool[self::pick('rival1', count($pool))];
        $rival2 = $pool[(self::pick('rival1', count($pool)) + 1 + self::pick('rival2', count($pool) - 1)) % count($pool)];
        $election = Ballots::create($this->co, ['kind' => 'election', 'title' => 'Executive Committee election '.$this->today->year,
            'description' => 'Paid-up members elect the chairperson and the treasurer for the next two years.', 'eligibility' => 'paid_up',
            'opens_at' => now()->subMinute()->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String(),
            'questions' => [
                ['title' => 'Chairperson', 'options' => [['label' => 'Agnes Nakato', 'member_id' => $this->people[$this->index('chair')]['id']],
                    ['label' => $rival['name'], 'member_id' => $rival['id']]]],
                ['title' => 'Treasurer', 'options' => [['label' => 'Harriet Achieng', 'member_id' => $this->people[$this->index('treasurer')]['id']],
                    ['label' => $rival2['name'], 'member_id' => $rival2['id']]]],
            ]], $chairUserId);
        $election = Ballots::open($this->co, $election);
        $this->vote($election, 'el', 62, 68, false);
        Ballots::close($election);
        $opens = $this->at($this->today->copy()->subDays(97), '08:00');
        DB::table('unity_ballots')->where('id', $election->id)->update(['opens_at' => $opens, 'closes_at' => $this->at($this->today->copy()->subDays(95), '17:00')]);
        DB::table('unity_votes')->where('ballot_id', $election->id)->update(['cast_at' => DB::raw("DATE_ADD('{$opens->format('Y-m-d H:i:s')}', INTERVAL (id % 3300) MINUTE)")]);

        $poll = Ballots::create($this->co, ['kind' => 'poll', 'title' => 'Where should next year\'s AGM be held?',
            'description' => 'One answer each. The Executive Committee will book the venue with the most votes.', 'eligibility' => 'current',
            'opens_at' => now()->subDays(2)->toIso8601String(), 'closes_at' => $this->at($this->today->copy()->addDays(5), '17:00')->toIso8601String(),
            'questions' => [['title' => 'AGM venue', 'options' => [['label' => 'Kampala'], ['label' => 'Jinja'], ['label' => 'Mbarara']]]]], $chairUserId);
        $poll = Ballots::open($this->co, $poll);
        $this->vote($poll, 'po', 34, 45, true);
        $from = now()->subDays(2)->utc()->format('Y-m-d H:i:s');
        DB::table('unity_votes')->where('ballot_id', $poll->id)->update(['cast_at' => DB::raw("DATE_ADD('{$from}', INTERVAL (id % 2700) MINUTE)")]);
    }

    /** Eligible members vote: $turnout percent of them, the first option winning about $lean percent of the time. */
    private function vote(UnityBallot $b, string $seed, int $turnout, int $lean, bool $skipLogins): void
    {
        $questions = DB::table('unity_ballot_questions')->where('ballot_id', $b->id)->orderBy('sort_order')->orderBy('id')->pluck('id');
        $options = DB::table('unity_ballot_options')->whereIn('question_id', $questions)->orderBy('sort_order')->orderBy('id')->get()->groupBy('question_id');
        $eligible = Ballots::eligible($this->cid, $b)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        foreach ($this->people as $i => $p) {
            if (! isset($eligible[$p['id']]) || ($skipLogins && $p['login'] !== null) || ($p['login'] === null && self::pick("{$seed}{$i}", 100) >= $turnout)) {
                continue;
            }
            $answers = [];
            foreach ($questions as $q) {
                $opts = $options[$q]->values();
                $roll = self::pick("{$seed}{$i}-{$q}", 100);
                $o = $roll < $lean ? $opts[0] : $opts[1 + ($roll % max(1, $opts->count() - 1))];
                $answers[] = ['question_id' => (int) $q, 'option_id' => (int) $o->id];
            }
            Ballots::vote($b, SaccoMember::withoutGlobalScopes()->find($p['id']), $answers);
        }
    }

    // ── Announcements ──────────────────────────────────────────────────

    private function announcements(): void
    {
        $by = (int) $this->users['secretary']->id;
        $items = [
            [30, ['title' => 'Election results', 'body' => 'Agnes Nakato was re-elected Chairperson and Harriet Achieng Treasurer. Thank you to every member who voted.']],
            [20, ['title' => 'Office rent levy now overdue', 'body' => 'The levy of UGX 20,000 was due last month. Please pay your chapter treasurer or by mobile money and keep your receipt.']],
            [8, ['title' => 'Jinja chapter has a new venue', 'body' => 'From next month the Jinja chapter meets at the town hall annex, 10:00.', 'audience' => 'chapter', 'audience_id' => $this->chapters[3]->id]],
            [2, ['title' => 'End-of-year dinner: tickets open', 'body' => 'Join us at Lakeside gardens, Munyonyo. Answer the invitation in the app to get your ticket number.', 'pinned' => true]],
        ];
        foreach ($items as [$ago, $d]) {
            $a = Voice::announce($this->co, $d + ['send_notice' => true, 'send_sms' => false], $by);
            DB::table('unity_announcements')->where('id', $a->id)->update(['published_at' => now()->subDays($ago)]);
        }
    }
}
