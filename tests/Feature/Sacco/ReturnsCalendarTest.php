<?php

namespace Tests\Feature\Sacco;

use App\Models\StoredFile;
use App\Services\Sacco\Ledger\PeriodService;
use App\Services\Sacco\Reports\AuditorExport;
use App\Services\Sacco\Reports\ReturnsCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P4.5 (and the returns' storage of P4.2, the auditor's export of P4.11): what a licensed and registered
 * SACCO owes and when, due dates moved to the next working day, reminders on the 10th and the 14th (moved back to a
 * working day), once a day; generate keeps the figures and the files, mark filed records it; an unlicensed SACCO owes
 * the regulator nothing.
 */
class ReturnsCalendarTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    public function test_due_dates_fall_on_working_days_and_reminders_on_the_10th_and_14th(): void
    {
        $this->assertSame('2026-06-15', ReturnsCalendar::dueOn('F3', Carbon::parse('2026-05-31'))->toDateString());
        $this->assertSame('2026-08-17', ReturnsCalendar::dueOn('F3', Carbon::parse('2026-07-31'))->toDateString(), 'the 15th is a Saturday');
        $this->assertSame('2026-10-15', ReturnsCalendar::dueOn('F1', Carbon::parse('2026-09-30'))->toDateString());
        $this->assertSame('2027-04-30', ReturnsCalendar::dueOn('REG_ANNUAL', Carbon::parse('2026-12-31'))->toDateString(), 'four months after the year');
        $this->assertSame('2027-01-15', ReturnsCalendar::dueOn('S55AS', Carbon::parse('2026-12-31'))->toDateString());
        $this->assertSame(['2026-10-08', '2026-10-14'], ReturnsCalendar::reminderDays(Carbon::parse('2026-10-15')),
            'the 10th is a Saturday and the 9th Independence Day: the reminder goes on Thursday the 8th');
        $this->assertSame(['2026-08-10', '2026-08-14'], ReturnsCalendar::reminderDays(Carbon::parse('2026-08-17')));
    }

    public function test_the_calendar_generate_mark_filed_and_reminders(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        (new PeriodService())->ensureAhead($cid, Carbon::parse('2026-05-01'), 2);
        $cal = new ReturnsCalendar();
        $this->assertSame(['F3', 'F4', 'SAV', 'F1', 'F2', 'F5', 'F6', 'F7', 'S55AS', 'REG_ANNUAL'], ReturnsCalendar::owed($cid));
        $list = $cal->list($cid);
        $byForm = collect($list)->groupBy('form');
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], $byForm['F3']->pluck('period_key')->all());
        $this->assertSame(['2026-Q2', '2026-Q3'], $byForm['F1']->pluck('period_key')->all());
        $this->assertSame(['2026-04-01', '2026-06-30', '2026-07-15'], [$byForm['F1'][0]['period_from'], $byForm['F1'][0]['period_to'], $byForm['F1'][0]['due_on']]);
        $this->assertArrayNotHasKey('REG_ANNUAL', $byForm->all(), 'the year has not ended');
        $may = $byForm['F3'][0];
        $this->assertSame('2026-06-15', $may['due_on']);
        $this->assertGreaterThan(80, $may['working_days_late']);
        $this->assertSame(0, $cal->ensure($cid), 'made once');

        $this->assertSame('return_not_generated', $this->code(fn () => $cal->markFiled($c, $may['id'], ['reference' => 'X'], $s['owner'])));
        $f = $cal->generate($c, $may['id'], $s['owner']);
        $this->assertSame(['generated', ['csv', 'pdf', 'xlsx']], [$f->status, collect($f->file_ids)->keys()->sort()->values()->all()]);
        $this->assertArrayHasKey('1.1.12', $f->figures['lines']);
        foreach ($f->file_ids as $id) {
            Storage::disk('local')->assertExists(StoredFile::withoutGlobalScopes()->findOrFail($id)->path);
        }
        $this->assertSame('forbidden', $this->code(fn () => $cal->markFiled($c, $may['id'], [], $s['teller'])));
        $filed = $cal->markFiled($c, $may['id'], ['filed_on' => '2026-10-20', 'reference' => 'UMRA portal 778812'], $s['owner']);
        $this->assertSame(['filed', '2026-10-20', 'UMRA portal 778812'], [$filed->status, $filed->filed_on->toDateString(), $filed->filed_reference]);
        $this->assertSame('return_filed', $this->code(fn () => $cal->generate($c, $may['id'], $s['owner'])));
        $this->assertSame(0, ReturnsCalendar::present($filed, now())['working_days_late']);

        // reminders on the 14th for everything due on the 15th of October, once
        Carbon::setTestNow('2026-10-14 08:00:00');
        $sent = $cal->remind($cid);
        $this->assertSame(9, $sent, 'F3, F4 and the savings statement for September, Forms 1, 2, 5, 6 and 7 and the Registrar\'s report for the third quarter');
        $this->assertSame(0, $cal->remind($cid), 'once a day');
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $cid)->where('type', 'sacco_returns')->where('user_id', $s['owner']->id)->count());
        Carbon::setTestNow('2026-10-13 08:00:00');
        $this->assertSame(0, $cal->remind($cid), 'not a reminder day');
    }

    public function test_an_unlicensed_unregistered_sacco_owes_nothing_but_can_still_generate(): void
    {
        $s = $this->saccoCompany(licensed: false);
        $cid = (int) $s['company']->id;
        \App\Models\SaccoSetting::for($cid)->forceFill(['registration_no' => null])->save();
        $this->assertSame([], ReturnsCalendar::owed($cid));
        $this->assertSame([], (new ReturnsCalendar())->list($cid));
        $report = (new \App\Services\Sacco\Reports\SaccoReports())->build($s['company'], 'umra_f3', ['to' => '2026-09-30'], $s['owner']);
        $this->assertSame('F3', $report['meta']['form']);
    }

    public function test_the_auditor_export_holds_every_workbook(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $auditor = $this->saccoStaff($c, 'sacco_auditor');
        $this->deposit($c, $this->savingsAccount($c, $this->activeMember($c, $s['owner']), $s['owner']), 50_000, $s['owner']);
        [$bytes, $type, $name] = (new AuditorExport())->build($c, '2026-10-01', '2026-10-31', $auditor);
        $this->assertSame(['application/zip', 'sacco-audit-2026-10-01-2026-10-31.zip'], [$type, $name]);
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($path);
        $this->assertSame(array_merge(array_map(fn ($n) => $n.'.xlsx', array_keys(AuditorExport::REPORTS)), ['12-audit-trail.xlsx']), $names);
        $this->assertSame('forbidden', $this->code(fn () => (new AuditorExport())->build($c, '2026-10-01', '2026-10-31', $s['teller'])));
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $c->id)->where('action', 'sacco.audit.exported')->exists(), 'the export is itself audited');
    }
}
