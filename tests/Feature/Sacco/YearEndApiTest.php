<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoReturnFiling;
use App\Models\User;
use App\Services\Sacco\Ledger\PeriodService;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4 through API v1 (04 §13.2): the returns calendar, the ratios, the new reports, the auditor's export,
 * the year-end requests and previews; who may call what (ApiPermissionMap and the controllers); another SACCO's ids
 * answer 404.
 */
class YearEndApiTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_returns_ratios_reports_and_the_auditor_export(): void
    {
        $s = $this->saccoCompany();
        $cid = (int) $s['company']->id;
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2026-08-01'), 2);
        $this->deposit($s['company'], $this->savingsAccount($s['company'], $this->activeMember($s['company'], $s['owner']), $s['owner']), 75_000, $s['owner'],
            ['value_date' => '2026-08-10'], ['entry_date' => '2026-08-10']);
        $owner = ['Authorization' => 'Bearer '.$s['token'], 'Accept' => 'application/json'];

        $list = $this->getJson('/api/v1/sacco/returns', $owner)->assertOk()->json('data');
        $this->assertContains('F3', array_column($list, 'form'));
        $f3 = collect($list)->firstWhere('period_key', '2026-08');
        $this->postJson("/api/v1/sacco/returns/{$f3['id']}/generate", [], $owner)->assertOk()->assertJsonPath('data.status', 'generated');
        $this->get("/api/v1/sacco/returns/{$f3['id']}/files/csv", $owner)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->postJson("/api/v1/sacco/returns/{$f3['id']}/mark-filed", ['reference' => 'UMRA 1'], $this->h($s['teller']))->assertStatus(403);
        $this->postJson("/api/v1/sacco/returns/{$f3['id']}/mark-filed", ['reference' => 'UMRA 1', 'filed_on' => '2026-09-14'], $owner)->assertOk()->assertJsonPath('data.status', 'filed');
        $this->getJson('/api/v1/sacco/returns', $this->h($s['teller']))->assertStatus(403);

        $ratios = $this->getJson('/api/v1/sacco/ratios?date=2026-09-30', $owner)->assertOk()->json('data');
        $this->assertCount(8, $ratios['ratios']);
        $this->assertCount(8, $ratios['weekly']);

        $keys = array_column($this->getJson('/api/v1/sacco/reports', $owner)->assertOk()->json('data'), 'key');
        foreach (['balance_sheet', 'income_statement', 'umra_f1', 'umra_f7', 'umra_savings_statement', 'ratios', 'registrar_annual_return', 's55as_quarterly', 'agm_pack',
            'insider_loans', 'dormant_register', 'membership_stats', 'journal_listing'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->getJson('/api/v1/sacco/reports/umra_f4?to=2026-09-30', $owner)->assertOk()->assertJsonPath('data.meta.form', 'F4');
        $this->getJson('/api/v1/sacco/reports/balance_sheet?to=2026-09-30&format=pdf', $owner)->assertOk()->assertJsonStructure(['data' => ['url']]);

        $link = $this->getJson('/api/v1/sacco/auditor-export?from=2026-08-01&to=2026-09-30', $this->h($this->saccoStaff($s['company'], 'sacco_auditor')))->assertOk()->json('data.url');
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get($link.'x')->assertStatus(403);
        $this->getJson('/api/v1/sacco/auditor-export?from=2026-08-01&to=2026-09-30', $this->h($s['teller']))->assertStatus(403);

        // another SACCO never sees this one's returns
        $b = $this->saccoCompany();
        $bOwner = ['Authorization' => 'Bearer '.$b['token'], 'Accept' => 'application/json'];
        $this->getJson("/api/v1/sacco/returns/{$f3['id']}", $bOwner)->assertStatus(404);
        $this->postJson("/api/v1/sacco/returns/{$f3['id']}/generate", [], $bOwner)->assertStatus(404);
        $this->get("/api/v1/sacco/returns/{$f3['id']}/files/pdf", $bOwner)->assertStatus(404);
        $this->assertSame('filed', SaccoReturnFiling::withoutGlobalScopes()->find($f3['id'])->status);
    }

    public function test_the_year_end_routes_preview_refuse_and_ask(): void
    {
        $s = $this->saccoCompany();
        $owner = ['Authorization' => 'Bearer '.$s['token'], 'Accept' => 'application/json'];
        $this->getJson('/api/v1/sacco/year-end/2025', $owner)->assertOk()->assertJsonPath('data.figures.surplus_minor', 0);
        $this->postJson('/api/v1/sacco/year-end/2025/close', [], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_to_close');
        $this->postJson('/api/v1/sacco/year-end/2025/close', [], $this->h($s['teller']))->assertStatus(403);
        $this->postJson('/api/v1/sacco/year-end/2026/close', [], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'year_not_ended');
        $this->postJson('/api/v1/sacco/year-end/2025/appropriation/preview', [], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'year_not_closed');
        $this->postJson('/api/v1/sacco/year-end/2025/appropriate', ['stf_minor' => 1.5], $owner)->assertStatus(422);
        $this->postJson('/api/v1/sacco/dividend-runs/preview', ['year' => 2025], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'no_dividend_declared');
        $this->postJson('/api/v1/sacco/interest-runs/preview', ['month' => '2026-09'], $owner)->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/v1/sacco/interest-runs', ['month' => '2026-09'], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'no_interest_products');
        $this->getJson('/api/v1/sacco/dividend-runs', $owner)->assertOk();
        $this->getJson('/api/v1/sacco/dividend-runs/999999', $owner)->assertStatus(404);
        $this->getJson('/api/v1/sacco/dormancy', $owner)->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/v1/sacco/dormancy/advertised', ['account_ids' => [1], 'reference' => 'Paper', 'on' => '2026-10-01'], $this->h($s['teller']))->assertStatus(403);

        $m = $this->activeMember($s['company'], $s['owner']);
        $this->getJson("/api/v1/sacco/members/{$m->id}/stf-buyback", $owner)->assertStatus(422)->assertJsonPath('errors.code', 'no_shares');
        $this->postJson("/api/v1/sacco/members/{$m->id}/stf-buyback", [], $this->h($s['teller']))->assertStatus(403);
    }
}
