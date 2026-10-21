<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use App\Services\Sacco\Shares\ShareService;
use App\Support\Rules\SaccoRules;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * AK-69 (the owner's instruction, 2026-10-21): no age limits anywhere, and the checks that rest on a law apply only to
 * a regulated SACCO (one with a licence number). An unregistered SACCO may add a five-year-old with no guardian, seat a
 * sixteen-year-old in an office, nominate freely and hold shares without the cap; a licensed one keeps the legal
 * checks, never the age rules. No refusal cites a law.
 */
class RegulatedRulesTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertDoesNotMatchRegularExpression('/\b(Act|DPPA|Regulations|reg\.|SI \d+|s\.\d+)/', $e->getMessage(), 'plain words, no law cited');

            return $e->errorCode();
        }

        return 'none';
    }

    public function test_an_unregistered_sacco_has_no_age_limits_and_none_of_the_legal_checks(): void
    {
        $s = $this->saccoCompany('office', 'akiba-enterprise', false);
        $c = $s['company'];
        $this->assertFalse(SaccoRules::strict($c));
        $svc = app(MemberService::class);
        $child = $svc->register($c, ['first_name' => 'Little', 'last_name' => 'Saver', 'date_of_birth' => now()->subYears(5)->toDateString()], $s['owner'], ['status' => 'active'])['member'];
        $this->assertNull($child->guardian_name);
        $teen = $svc->register($c, ['first_name' => 'Young', 'last_name' => 'Officer', 'date_of_birth' => now()->subYears(16)->toDateString()], $s['owner'], ['status' => 'active'])['member'];
        $this->assertSame('secretary', app(OfficerService::class)->appoint((int) $c->id, ['position' => 'secretary', 'member_id' => $teen->id], (int) $s['owner']->id)->position);

        app(ShareService::class)->purchase($c, ['member_id' => $child->id, 'shares_count' => 1], $s['teller']);
        $this->assertCount(2, $svc->nominate($c, (int) $child->id, [['name' => 'Mum', 'share_bp' => 5000], ['name' => 'Dad', 'share_bp' => 5000]], '', '', null, $s['owner']));
        foreach ([$teen, $this->activeMember($c, $s['owner'], 'Third')] as $m) {
            app(ShareService::class)->purchase($c, ['member_id' => $m->id, 'shares_count' => 1], $s['teller']);
        }
        $this->assertSame('posted', app(ShareService::class)->purchase($c, ['member_id' => $child->id, 'shares_count' => 20], $s['teller'])['transaction']->status, 'no one-third cap');

        $a = $this->savingsAccount($c, $child, $s['teller']);
        $this->deposit($c, $a, 50_000, $s['teller']);
        app(SavingsAccounts::class)->markDormant($c, (int) $a->id, $s['treasurer']);
        $this->assertSame('posted', $this->withdraw($c, $a, 1_000, $s['teller'])->status, 'a dormant account needs no second officer here');
        $p = app(SavingsProducts::class)->create($c, ['code' => 'INT', 'name' => 'Interest', 'kind' => 'voluntary', 'interest_rate_bp' => 600], $s['owner']);
        $req = app(SavingsProducts::class)->requestTermsChange($c, (int) $p->id, ['interest_rate_bp' => 500], $s['owner']);
        $this->assertSame(now()->toDateString(), $req->payload['effective_from'], 'a rate cut may take effect at once');
    }

    public function test_a_licensed_sacco_keeps_the_legal_checks_but_never_an_age_rule(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $this->assertTrue(SaccoRules::strict($c));
        $svc = app(MemberService::class);
        $this->assertSame('consent_required', $this->code(fn () => $svc->register($c, ['first_name' => 'No', 'last_name' => 'Consent'], $s['owner'])));
        $child = $svc->register($c, ['first_name' => 'Little', 'last_name' => 'Saver', 'date_of_birth' => now()->subYears(5)->toDateString(), 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $teen = $svc->register($c, ['first_name' => 'Young', 'last_name' => 'Officer', 'date_of_birth' => now()->subYears(16)->toDateString(), 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $this->assertSame('treasurer', app(OfficerService::class)->appoint((int) $c->id, ['position' => 'treasurer', 'member_id' => $teen->id], (int) $s['owner']->id)->position);

        app(ShareService::class)->purchase($c, ['member_id' => $child->id, 'shares_count' => 1], $s['teller']);
        $two = [['name' => 'Mum', 'share_bp' => 5000], ['name' => 'Dad', 'share_bp' => 5000]];
        $this->assertSame('witnesses_required', $this->code(fn () => $svc->nominate($c, (int) $child->id, $two, 'W', 'w', null, $s['owner'])));
        $this->assertSame('nomination_needs_shares', $this->code(fn () => $svc->nominate($c, (int) $child->id, $two, 'W One', 'W Two', null, $s['owner'])));
    }
}
