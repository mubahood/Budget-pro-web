<?php

namespace Tests\Feature\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\KinLink;
use App\Models\KinSpouse;
use App\Models\SaccoMember;
use App\Services\Kin\TreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/** The family tree's guards (TreeService) and its generations. Every name is made up. */
class KinTreeTest extends ApiTestCase
{
    use MakesKin;

    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->co] = $this->kinAccount();
    }

    private function person(string $first, string $gender = 'male', ?string $born = null, ?Company $co = null): SaccoMember
    {
        $m = new SaccoMember(['company_id' => ($co ?? $this->co)->id, 'kind' => 'individual', 'first_name' => $first, 'last_name' => 'Sample', 'gender' => $gender, 'date_of_birth' => $born]);
        $m->save();

        return $m->fresh();
    }

    private function link(SaccoMember $child, SaccoMember $parent, string $role, string $kind = 'birth'): KinLink
    {
        return KinLink::create(['company_id' => $this->co->id, 'child_id' => $child->id, 'parent_id' => $parent->id, 'role' => $role, 'kind' => $kind]);
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    public function test_a_family_person_is_numbered_active_and_counted_as_people(): void
    {
        $p = $this->person('Amos');
        $this->assertSame('active', $p->status);
        $this->assertNotEmpty($p->member_no);
        $this->assertSame(1, (int) $p->generation, 'a person with no parents is a founder');
        $this->assertSame((int) $p->id, (int) $p->root_id);
    }

    public function test_the_link_guards(): void
    {
        $dad = $this->person('Dad', 'male', '1950-01-01');
        $mum = $this->person('Mum', 'female', '1952-01-01');
        $kid = $this->person('Kid', 'female', '1980-01-01');
        $this->link($kid, $dad, 'father');
        $this->link($kid, $mum, 'mother');

        $this->refused('link_self', fn () => $this->link($kid, $kid, 'parent'));
        $this->refused('link_exists', fn () => $this->link($kid, $dad, 'father'));
        $this->refused('link_birth_parent', fn () => $this->link($kid, $this->person('Other dad'), 'father'));
        $this->refused('link_cycle', fn () => $this->link($dad, $kid, 'parent'));
        $grandkid = $this->person('Grandkid');
        $this->link($grandkid, $kid, 'mother');
        $this->refused('link_cycle', fn () => $this->link($dad, $grandkid, 'parent', 'adoptive'));

        // Adoptive, step and guardian parents may be many.
        $this->link($kid, $this->person('Step dad'), 'father', 'step');
        $this->link($kid, $this->person('Guardian one'), 'parent', 'guardian');
        $this->link($kid, $this->person('Guardian two'), 'parent', 'guardian');
        $this->assertSame(5, KinLink::withoutGlobalScopes()->where('child_id', $kid->id)->count());

        // Another family's person is never linked.
        [$other] = $this->kinAccount();
        $stranger = $this->person('Stranger', 'male', null, $other);
        $this->refused('not_in_family', fn () => $this->link($grandkid, $stranger, 'father'));
    }

    public function test_a_child_older_than_a_parent_is_a_warning_not_a_refusal(): void
    {
        $young = $this->person('Young', 'male', '1990-01-01');
        $old = $this->person('Old', 'male', '1980-01-01');
        $link = $this->link($old, $young, 'father');
        $this->assertContains('child_older_than_parent', array_column(TreeService::warningsFor($link), 'code'));
    }

    public function test_spouses_may_be_several_and_a_name_only(): void
    {
        $man = $this->person('Husband');
        $w1 = $this->person('Wife one', 'female');
        $w2 = $this->person('Wife two', 'female');
        KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $man->id, 'spouse_id' => $w1->id, 'sort_order' => 1]);
        KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $man->id, 'spouse_id' => $w2->id, 'sort_order' => 2]);
        KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $man->id, 'spouse_name' => 'An earlier wife', 'status' => 'ended']);
        $this->refused('spouse_exists', fn () => KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $w1->id, 'spouse_id' => $man->id]));
        $this->refused('spouse_required', fn () => KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $man->id]));
        $this->refused('link_self', fn () => KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $man->id, 'spouse_id' => $man->id]));

        $r = TreeService::relatives((int) $this->co->id, (int) $man->id);
        $this->assertCount(3, $r['spouses']);
        $this->assertContains('An earlier wife', array_column($r['spouses'], 'name'));
    }

    public function test_generations_roots_siblings_and_people_who_married_in(): void
    {
        $founder = $this->person('Founder');
        $wife = $this->person('Wife', 'female');
        KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $founder->id, 'spouse_id' => $wife->id]);
        $a = $this->person('A');
        $b = $this->person('B', 'female');
        foreach ([$a, $b] as $c) {
            $this->link($c, $founder, 'father');
            $this->link($c, $wife, 'mother');
        }
        $half = $this->person('Half');
        $this->link($half, $founder, 'father');
        $inLaw = $this->person('In-law', 'female');
        KinSpouse::create(['company_id' => $this->co->id, 'person_id' => $a->id, 'spouse_id' => $inLaw->id]);
        $grand = $this->person('Grand');
        $this->link($grand, $a, 'father');
        $this->link($grand, $inLaw, 'mother');

        $g = fn (SaccoMember $p) => [(int) $p->fresh()->generation, (int) $p->fresh()->root_id];
        $this->assertSame([1, $founder->id], $g($founder));
        $this->assertSame([1, $founder->id], $g($wife), 'a wife with no parents takes her husband\'s line');
        $this->assertSame([2, $founder->id], $g($a));
        $this->assertSame([2, $founder->id], $g($inLaw), 'married in: the spouse\'s generation');
        $this->assertSame([3, $founder->id], $g($grand));

        $siblings = collect(TreeService::relatives((int) $this->co->id, (int) $a->id)['siblings'])->keyBy('id');
        $this->assertSame('full', $siblings[$b->id]['relation']);
        $this->assertSame('half', $siblings[$half->id]['relation']);

        // Unlinking moves the line back: the grandchild becomes a founder.
        KinLink::withoutGlobalScopes()->where('child_id', $grand->id)->get()->each->delete();
        $this->assertSame([1, $grand->id], $g($grand));
    }

    public function test_six_hundred_people_over_five_generations_in_well_under_a_second(): void
    {
        $cid = (int) $this->co->id;
        $now = now();
        $rows = [];
        $gen = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        $sizes = [1 => 8, 2 => 32, 3 => 80, 4 => 180, 5 => 300];
        $i = 0;
        foreach ($sizes as $g => $n) {
            for ($k = 0; $k < $n; $k++) {
                $i++;
                $rows[] = ['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'member_no' => 'P'.$i, 'first_name' => 'Person '.$i, 'last_name' => 'Sample',
                    'gender' => $k % 2 ? 'female' : 'male', 'status' => 'active', 'kind' => 'individual', 'joined_on' => $now->toDateString(), 'server_seq' => $i, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('sacco_members')->insert($chunk);
        }
        $ids = DB::table('sacco_members')->where('company_id', $cid)->orderBy('id')->pluck('id')->all();
        $offset = 0;
        foreach ($sizes as $g => $n) {
            $gen[$g] = array_slice($ids, $offset, $n);
            $offset += $n;
        }
        $links = [];
        foreach ([2, 3, 4, 5] as $g) {
            $parents = $gen[$g - 1];
            foreach ($gen[$g] as $k => $child) {
                $father = $parents[(2 * $k) % count($parents) & ~1] ?? $parents[0];
                $mother = $parents[((2 * $k) % count($parents) & ~1) + 1] ?? $parents[1];
                foreach (['father' => $father, 'mother' => $mother] as $role => $pid) {
                    $links[] = ['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'child_id' => $child, 'parent_id' => $pid, 'role' => $role, 'kind' => 'birth',
                        'server_seq' => 0, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        foreach (array_chunk($links, 300) as $chunk) {
            DB::table('kin_links')->insert($chunk);
        }

        $t = microtime(true);
        $r = TreeService::recompute($cid);
        $payload = TreeService::payload($cid);
        $seconds = microtime(true) - $t;

        $this->assertSame(600, $r['people']);
        $this->assertSame(5, $payload['data']['generations']);
        $this->assertCount(600, $payload['data']['people']);
        $this->assertLessThan(1.0, $seconds, "recompute and payload took {$seconds}s");
        $this->assertSame(5, (int) DB::table('sacco_members')->where('id', end($gen[5]))->value('generation'));
    }
}
