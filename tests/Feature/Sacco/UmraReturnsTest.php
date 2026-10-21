<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoLedgerAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\TrialBalance;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\Reports\Ratios;
use App\Services\Sacco\Reports\SaccoReportFormat;
use App\Services\Sacco\Reports\SaccoReports;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Shares\ShareService;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.1, P4.2, P4.3 and P4.4 on a fixed fictional ledger (every figure below is worked out by hand from the
 * postings in book()): the statement of financial position and the income statement tie to the trial balance; every
 * line of Forms 2 to 6 and the statement of savings; Form 3 counts the current surplus at 50 %; Form 4's ratio is net
 * liquid assets over deposits and short-term liabilities; each prudential ratio; the membership statistics and the
 * quarterly report to the Registrar.
 *
 * The ledger, all of it posted in May 2026: four members buy 3,500,000 in shares and pay 80,000 in entrance fees;
 * savings 6,500,000 voluntary and 1,500,000 compulsory; then 5,000,000 cash to the bank and 300,000 to mobile money;
 * 1,000,000 into treasury bills (1251) and 400,000 into other investments; land and buildings 2,000,000 on a bank loan;
 * furniture 600,000; depreciation 50,000; loan interest 2,500,000 and loan fees 300,000 received; personnel 900,000;
 * governance 150,000 in cash; savings interest 120,000 accrued; prepayments 60,000; a capital grant of 1,000,000.
 */
class UmraReturnsTest extends ApiTestCase
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

    private function id(int $cid, string $code): int
    {
        return (int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', $code)->value('id');
    }

    private function journal(int $cid, string $debit, string $credit, int $minor, string $key): void
    {
        (new PostingService())->post(JournalDraft::make($cid, 'manual', 'golden:'.$key)->debit($this->id($cid, $debit), Amount::of($minor, 'UGX'))
            ->credit($this->id($cid, $credit), Amount::of($minor, 'UGX'))->dated('2026-05-20', '2026-05-20'));
    }

    /** @return array{0: Company, 1: User} */
    private function book(): array
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $owner = $s['owner'];
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2026-05-01'), 6);
        (new ChartOfAccounts())->addAccount($cid, ['code' => '1251', 'name' => 'Treasury bills (91 days)', 'type' => 'asset', 'parent_id' => $this->id($cid, '1250')], null);
        $people = [['Akello', 'female', '1990-03-01', 1_100_000, 4_000_000, 'ORD'], ['Okello', 'male', '2000-01-15', 1_000_000, 2_500_000, 'ORD'],
            ['Nansubuga', 'female', '1960-07-01', 900_000, 1_500_000, 'CMP'], ['Mugisha', 'male', null, 500_000, 0, null]];
        foreach ($people as $i => [$name, $gender, $dob, $shares, $savings, $product]) {
            $m = app(MemberService::class)->register($c, ['first_name' => $name, 'last_name' => 'Golden', 'gender' => $gender, 'date_of_birth' => $dob, 'consent_data' => true,
                'joined_on' => '2026-05-02'], $owner, ['status' => 'active'])['member'];
            $day = '2026-05-0'.($i + 3);
            (new ShareService())->payEntranceFee($c, ['member_id' => $m->id, 'value_date' => $day], $owner, ['entry_date' => $day]);
            (new ShareService())->purchase($c, ['member_id' => $m->id, 'amount_minor' => $shares, 'channel' => 'cash', 'value_date' => $day], $owner, ['entry_date' => $day]);
            if ($product !== null) {
                $a = (new SavingsAccounts())->open($c, (int) $m->id, (int) $this->product($c, $product)->id, $owner, ['opened_on' => $day]);
                $this->deposit($c, $a, $savings, $owner, ['value_date' => $day], ['entry_date' => $day]);
            }
        }
        foreach ([['1100', '1010', 5_000_000], ['1150', '1010', 300_000], ['1251', '1100', 1_000_000], ['1600', '1100', 400_000], ['1710', '2320', 2_000_000],
            ['1720', '1100', 600_000], ['5600', '1790', 50_000], ['1100', '4100', 2_500_000], ['1100', '4200', 300_000], ['5300', '1100', 900_000], ['5400', '1010', 150_000],
            ['5100', '2140', 120_000], ['1500', '1100', 60_000], ['1100', '3500', 1_000_000]] as $i => [$dr, $cr, $minor]) {
            $this->journal($cid, $dr, $cr, $minor, (string) $i);
        }

        return [$c, $owner];
    }

    /** @return array<string, int|null> */
    private function lines(Company $c, User $by, string $key, string $to = '2026-06-30'): array
    {
        return (new SaccoReports())->build($c, $key, ['to' => $to], $by)['meta']['lines'];
    }

    public function test_the_statements_tie_to_the_trial_balance(): void
    {
        [$c, $owner] = $this->book();
        $bs = (new SaccoReports())->build($c, 'balance_sheet', ['to' => '2026-06-30'], $owner);
        $l = $bs['meta']['lines'];
        $this->assertSame([6_130_000, 6_140_000, 60_000, 1_000_000, 400_000, 2_550_000], [$l['cash_hand'], $l['cash_bank'], $l['prepayments'], $l['gov_securities'],
            $l['other_investments'], $l['ppe']]);
        $this->assertSame([6_500_000, 1_500_000, 120_000, 2_000_000], [$l['savings'], $l['non_withdrawable'], $l['interest_payable'], $l['external_borrowings']]);
        $this->assertSame([3_500_000, 1_000_000, 1_660_000, 0], [$l['share_capital'], $l['grants'], $l['current_surplus'], $l['retained_prior']]);
        $this->assertSame(['assets' => 16_280_000, 'liabilities' => 10_120_000, 'equity' => 6_160_000, 'liabilities_and_equity' => 16_280_000], $bs['meta']['totals']);
        $this->assertTrue($bs['meta']['balanced'] && $bs['meta']['trial_balance_balanced']);
        $tb = (new TrialBalance())->between((int) $c->id, null, '2026-06-30');
        $assetsInTb = array_sum(array_map(fn ($r) => $r['type'] === 'asset' ? ($r['normal_side'] === 'debit' ? $r['closing_minor'] : -$r['closing_minor']) : 0, $tb['rows']));
        $this->assertSame($bs['meta']['totals']['assets'], $assetsInTb, 'the same accounts, the same balances');

        $is = (new SaccoReports())->build($c, 'income_statement', ['from' => '2026-01-01', 'to' => '2026-06-30'], $owner);
        $this->assertSame(['income' => 2_880_000, 'expenses' => 1_220_000, 'net_surplus' => 1_660_000], $is['meta']['totals']);
        $this->assertSame($l['current_surplus'], $is['meta']['totals']['net_surplus'], 'the year to date on the position is the income statement\'s surplus');
    }

    public function test_every_line_of_forms_2_to_6_and_the_statement_of_savings(): void
    {
        [$c, $owner] = $this->book();
        $this->assertSame(['1.1' => 5_330_000, '1.2' => 16_280_000, '1.3' => 8_120_000, '1.4' => 8_740_000, '1.5' => 400_000, '1.6' => 2_000_000, '2.1' => 1229, '2.2' => 1000,
            '2.3' => -229, '3.0' => 750, '3.1' => 4000, '3.2' => 1_732_000], $this->lines($c, $owner, 'umra_f2'));

        $f3 = $this->lines($c, $owner, 'umra_f3');
        $this->assertSame(['1.1.1' => 3_500_000, '1.1.2' => 0, '1.1.3' => 0, '1.1.4' => 830_000, '1.1.5' => 1_000_000, '1.1.6' => 0, '1.1.7' => 0, '1.1.8' => 5_330_000,
            '1.1.9' => 0, '1.1.10' => 0, '1.1.11' => 0, '1.1.12' => 5_330_000, '1.1.13' => 1_830_000, '2.1' => 12_270_000, '2.2' => 1_000_000, '2.3' => 0, '2.4' => 0,
            '2.5' => 400_000, '2.6' => 2_550_000, '2.7' => 60_000, '2.8' => 16_280_000, '2.9' => 0, '3' => 0, '4.1' => 16_280_000, '4.2' => 0, '4.3' => 16_280_000,
            '4.4' => 1_628_000, '4.5' => 8_120_000, '4.6' => 3274, '4.7' => 1000], $f3, 'the current surplus of 1,660,000 counts at 50 %');
        $rows = collect((new SaccoReports())->build($c, 'umra_f3', ['to' => '2026-06-30'], $owner)['rows'])->keyBy('line');
        $this->assertSame([3_500, 830, 12_270], [$rows['1.1.1']['thousands'], $rows['1.1.4']['thousands'], $rows['2.1']['thousands']], "UGX '000");

        $this->assertSame(['1.1' => 6_130_000, '1.2' => 0, '2.1' => 6_140_000, '2.2' => 0, '2.3' => 0, '3.1' => 0, '3.2' => 0, '3.3' => 0, '3.4' => 0, '3.5' => 0,
            '4.1' => 1_000_000, '4.2' => 0, '5' => 13_270_000, '6.1' => 8_120_000, '6.2' => 0, '6.3' => 8_120_000, '6.4' => 0, '6.8' => 8_120_000, '7.1' => 0, '7.2' => 0,
            '7.3' => 0, '8.3' => 16342, '8.4' => 1500, '8.5' => 12_052_000], $this->lines($c, $owner, 'umra_f4'), 'ratio = 5 / (6.3 + 7.3)');

        $f5 = $this->lines($c, $owner, 'umra_f5');
        $this->assertSame([6_130_000, 6_140_000, 2_550_000, 6_500_000, 1_500_000, 3_500_000, 1_660_000, 16_280_000, 6_160_000, 16_280_000],
            [$f5['1.1'], $f5['1.2'], $f5['6'], $f5['9.1'], $f5['9.3'], $f5['16.1'], $f5['16.4'], $f5['A'], $f5['C'], $f5['D']]);

        $this->assertSame(['2.1' => 2_500_000, '2.2' => 300_000, '3.1' => 0, '3.4' => 80_000, '3' => 2_880_000, '4.1' => 0, '4.2' => 120_000, '4.3' => 0, '4.4' => 0,
            '4' => 120_000, '5' => 2_760_000, '6.1' => 0, '6.2' => 0, '7.1' => 900_000, '7.2' => 150_000, '7.3' => 0, '7.4' => 50_000, '7.5' => 0, '7' => 1_100_000,
            '8' => 1_660_000, '9' => 0, '10' => 1_660_000, '11' => 0, '12' => 1_660_000, '13.1' => 0, '13.2' => 0, '14' => 1_660_000], $this->lines($c, $owner, 'umra_f6'));

        $this->assertSame(['1' => 6_500_000, '2' => 1_500_000, 'A' => 6_500_000, 'B' => 1_500_000, 'C' => 120_000, 'D' => 8_120_000],
            $this->lines($c, $owner, 'umra_savings_statement'));

        $f6 = (new SaccoReports())->build($c, 'umra_f6', ['to' => '2026-06-30'], $owner);
        $this->assertSame(['2026-04-01', '2026-06-30'], [$f6['from'], $f6['to']], 'a quarterly form covers the quarter');
        $details = collect($f6['sections'][0]['rows'])->pluck('value', 'field');
        $this->assertSame(['CS 1234', '2026-01-01', '2026-12-31'], [$details['CS No.'], $details['Financial year start'], $details['Financial year end']]);
        $this->assertSame(['Authorised by', 'Countersigned by'], array_column($f6['sections'][1]['rows'], 'role'));
        $this->assertNotSame('', $f6['sections'][1]['rows'][0]['name'], 'the chairperson seated at setup signs');
        foreach (['pdf', 'xlsx', 'csv'] as $format) {
            [$bytes] = SaccoReportFormat::render($f6, $format);
            $this->assertNotEmpty($bytes);
        }
        $this->assertStringNotContainsString('Budget Pro', (string) SaccoReportFormat::render($f6, 'csv')[0], 'a return names only the SACCO');
    }

    public function test_each_ratio_the_weekly_liquidity_and_the_central_bank_threshold(): void
    {
        [$c] = $this->book();
        $r = (new Ratios())->compute((int) $c->id, '2026-06-30');
        $by = collect($r['ratios'])->keyBy('key');
        $this->assertSame(['core_capital' => 3274, 'institutional_capital' => 1124, 'core_to_deposits' => 6564, 'liquidity' => 16342, 'savings_in_banks' => 8424,
            'external_borrowing' => 1229, 'non_earning' => 5369, 'investments' => 750], $by->map(fn ($x) => $x['value_bp'])->all());
        $this->assertSame(['core_capital' => true, 'institutional_capital' => true, 'core_to_deposits' => true, 'liquidity' => true, 'savings_in_banks' => true,
            'external_borrowing' => true, 'non_earning' => false, 'investments' => true], $by->map(fn ($x) => $x['ok'])->all());
        $this->assertSame('2026-06-26', $r['liquidity_on'], 'the last working day of the last finished week');
        $this->assertSame('2026-10-08', Ratios::weekEnd(Carbon::parse('2026-10-09'))->toDateString(), 'Independence Day, a Friday: the week ends on Thursday');
        $this->assertSame(['below', 'below', null], [$r['bou']['savings'], $r['bou']['capital'], $r['bou']['warning']]);
        $weekly = (new Ratios())->weekly((int) $c->id, '2026-06-30', 10);
        $this->assertCount(10, $weekly);
        $this->assertSame('2026-04-24', $weekly[0]['on']);
        $this->assertSame('2026-06-26', end($weekly)['on']);
        $this->assertSame(null, $weekly[0]['value_bp'], 'before the books had deposits nothing is measurable');
    }

    public function test_membership_statistics_and_the_quarterly_report_to_the_registrar(): void
    {
        [$c, $owner] = $this->book();
        $stats = (new SaccoReports())->build($c, 'membership_stats', ['from' => '2026-04-01', 'to' => '2026-06-30'], $owner)['meta']['stats'];
        $this->assertSame(4, $stats['Total']['Members']);
        $this->assertSame(['Female' => 2, 'Male' => 2, 'Other' => 0, 'Not given' => 0], $stats['Gender']);
        $this->assertSame(['Under 18' => 0, 'Youth (18 to 30)' => 1, '31 to 59' => 1, '60 and over' => 1, 'Not given' => 1], $stats['Age']);
        $this->assertSame(['Joined' => 4, 'Left' => 0], $stats['In the period']);

        $q = (new SaccoReports())->build($c, 's55as_quarterly', ['to' => '2026-06-30'], $owner);
        $rows = collect($q['rows'])->keyBy('measure');
        $this->assertSame(2_880_000, $rows['Operating income']['amount_minor']);
        $this->assertSame(1_220_000, $rows['Financial, provision and operating costs']['amount_minor']);
        $this->assertSame(23607, $rows['Operational self-sufficiency (income / costs)']['ratio_bp']);
        $this->assertSame(1_660_000, $rows['Net surplus for the quarter']['amount_minor']);
        $this->assertSame(0, $rows['Gross loan portfolio']['amount_minor']);
    }

    public function test_another_saccos_books_never_reach_the_forms(): void
    {
        [$c, $owner] = $this->book();
        $other = $this->saccoCompany();
        $this->assertSame(0, (new SaccoReports())->build($other['company'], 'umra_f3', ['to' => '2026-06-30'], $other['owner'])['meta']['lines']['1.1.1']);
        $this->assertSame(3_500_000, $this->lines($c, $owner, 'umra_f3')['1.1.1']);
    }
}
