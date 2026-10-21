<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoSetting;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\Reports\SaccoReportFormat;
use App\Services\Sacco\Reports\SaccoReportLinks;
use App\Services\Sacco\Reports\SaccoReports;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Shares\ShareService;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.14 (04 §12): the P1 reports on a fixed fictional SACCO with hand-computed figures, the PDF header
 * (name, CS No., licence; "Regulated by" only when licensed), exports without the NIN, and signed download links.
 */
class SaccoReportsTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private const NIN = 'CM80010112345XYZ';

    /** Two members; Opio: 10 shares, 500,000 in, 120,000 out (with an approval above 100,000), Auma: 5 shares, 80,000 in. */
    private function fixture(): array
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Africa/Kampala'));
        $s = $this->saccoCompany();
        $c = $s['company'];
        SaccoSetting::for((int) $c->id)->update(['withdrawal_approval_above_minor' => 100_000]);
        $opio = app(MemberService::class)->register($c, ['first_name' => 'Opio', 'last_name' => 'James', 'nin' => self::NIN, 'consent_data' => true, 'district' => 'Lira'], $s['owner'], ['status' => 'active'])['member'];
        $auma = $this->activeMember($c, $s['owner'], 'Auma', 'Grace');
        $a1 = $this->savingsAccount($c, $opio, $s['teller']);
        $a2 = $this->savingsAccount($c, $auma, $s['teller']);
        $this->deposit($c, $a1, 500_000, $s['teller']);
        $this->deposit($c, $a2, 80_000, $s['teller']);
        app(ShareService::class)->purchase($c, ['member_id' => $opio->id, 'shares_count' => 10], $s['teller']);
        app(ShareService::class)->purchase($c, ['member_id' => $auma->id, 'shares_count' => 5], $s['teller']);
        $w = $this->withdraw($c, $a1, 120_000, $s['teller']);
        app(SaccoApprovals::class)->approve($c, (int) $w->approval_id, $s['treasurer']);

        return [$s, $opio, $auma, $a1];
    }

    public function test_the_p1_reports_give_the_hand_computed_figures(): void
    {
        [$s, $opio, , $a1] = $this->fixture();
        $c = $s['company'];
        $r = new SaccoReports();
        $p = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $tb = $r->build($c, 'trial_balance', $p, $s['owner']);
        $this->assertTrue($tb['meta']['balanced']);
        $byCode = collect($tb['rows'])->keyBy('code');
        $this->assertSame(500_000 + 80_000 + 150_000 - 120_000, $byCode['1010']['debit_balance_minor']);
        $this->assertSame(460_000, $byCode['2110']['credit_balance_minor']);
        $this->assertSame(150_000, $byCode['3100']['credit_balance_minor']);
        $this->assertSame(610_000, $tb['totals']['debit_balance_minor']);

        $st = $r->build($c, 'member_statement', $p + ['member_id' => $opio->id], $s['owner']);
        $sav = collect($st['sections'])->firstWhere('type', 'savings');
        $this->assertSame([0, 380_000, 3], [$sav['opening_minor'], $sav['closing_minor'], count($sav['rows'])]);
        $this->assertSame(100_000, collect($st['sections'])->firstWhere('type', 'shares')['closing_minor']);

        $cash = $r->build($c, 'cash_book', $p, $s['owner']);
        $this->assertSame([730_000, 120_000, 610_000], [$cash['totals']['in_minor'], $cash['totals']['out_minor'], $cash['totals']['closing_minor']]);
        $day = $r->build($c, 'day_book', ['date' => '2026-10-14'], $s['owner']);
        $this->assertSame($day['totals']['debit_minor'], $day['totals']['credit_minor']);
        $this->assertSame(730_000 + 120_000, $day['totals']['debit_minor']);

        $shares = $r->build($c, 'share_register', $p, $s['owner']);
        $this->assertSame([15, 150_000], [$shares['totals']['shares'], $shares['totals']['value_minor']]);
        $this->assertSame(6666, collect($shares['rows'])->firstWhere('member_no', $opio->member_no)['holding_bp']);
        $this->assertSame('Above the limit', collect($shares['rows'])->firstWhere('member_no', $opio->member_no)['over_cap'], 'two holders: flagged on the register');

        $sb = $r->build($c, 'savings_balances', $p, $s['owner']);
        $this->assertSame([460_000, 460_000], [$sb['totals']['balance_minor'], $sb['totals']['available_minor']]);
        $reg = $r->build($c, 'member_register', $p, $s['owner']);
        $this->assertSame(10, collect($reg['rows'])->firstWhere('member_no', $opio->member_no)['shares']);
        $log = $r->build($c, 'approvals_log', $p, $s['owner']);
        $this->assertSame([1, 'withdrawal', 'executed'], [count($log['rows']), $log['rows'][0]['action'], $log['rows'][0]['status']]);
        $this->assertStringContainsString('(approve)', $log['rows'][0]['checkers']);

        $this->assertSame('forbidden', rescue(fn () => $r->build($c, 'trial_balance', $p, $s['teller']) && 'none', fn ($e) => $e->errorCode(), false));
    }

    public function test_the_pdf_names_the_sacco_the_regulated_line_shows_only_when_licensed_and_no_export_carries_a_nin(): void
    {
        [$s] = $this->fixture();
        $c = $s['company'];
        $r = new SaccoReports();
        $report = $r->build($c, 'member_register', [], $s['owner']);
        $html = view()->file(SaccoReportFormat::view(), ['report' => $report])->render();
        $this->assertStringContainsString('Test Teachers SACCO', $html);
        $this->assertStringContainsString('CS 1234', $html);
        $this->assertStringNotContainsString('Regulated by', $html);
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            [$bytes] = SaccoReportFormat::render($report, $format);
            $this->assertStringNotContainsString(self::NIN, $bytes, "{$format} carries no NIN");
            $this->assertStringNotContainsString(substr(self::NIN, -4), $format === 'csv' ? $bytes : '', 'not even the last four');
        }
        $this->assertStringStartsWith('%PDF', SaccoReportFormat::render($report, 'pdf')[0]);
        $this->assertSame(['380,000', '-1,250.50', '0.05'], [SaccoReportFormat::money(380_000, 'UGX'), SaccoReportFormat::money(-125050, 'USD'), SaccoReportFormat::money(5, 'USD')]);
        $this->assertStringContainsString('380000', SaccoReportFormat::render($r->build($c, 'savings_balances', [], $s['owner']), 'csv')[0], 'CSV money in major units, exact');

        SaccoSetting::for((int) $c->id)->update(['licence_no' => 'UMRA/T4/0099', 'show_regulated_line' => true]);
        $html = view()->file(SaccoReportFormat::view(), ['report' => $r->build($c->fresh(), 'trial_balance', [], $s['owner'])])->render();
        $this->assertStringContainsString('Regulated by Uganda Microfinance Regulatory Authority · Licence UMRA/T4/0099', $html);
    }

    public function test_a_download_link_opens_for_thirty_minutes_and_never_for_another_sacco(): void
    {
        [$s] = $this->fixture();
        $c = $s['company'];
        $link = SaccoReportLinks::make($c, $s['owner'], 'trial_balance', ['from' => '2026-10-01', 'to' => '2026-10-31'], 'csv');
        $this->assertSame(1800, $link['expires_in']);
        $path = parse_url($link['url'], PHP_URL_PATH).'?'.parse_url($link['url'], PHP_URL_QUERY);
        $res = $this->get($path);
        $res->assertOk();
        $this->assertStringContainsString('Trial balance', $res->getContent());
        $this->get(str_replace('company_id='.$c->id, 'company_id='.($c->id + 1), $path))->assertStatus(403);
        $this->travel(31)->minutes();
        $this->get($path)->assertStatus(403);
        $this->assertSame('forbidden', rescue(fn () => SaccoReportLinks::make($c, $s['teller'], 'trial_balance', [], 'pdf') && 'none', fn ($e) => $e->errorCode(), false));
    }
}
