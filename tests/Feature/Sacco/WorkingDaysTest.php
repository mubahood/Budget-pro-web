<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoPublicHoliday;
use App\Services\Sacco\Calendar\PublicHolidays;
use App\Support\Sacco\WorkingDays;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Akiba Ledger P2.1 (AK-51, AK-26, SI 108 reg. 11): working days skip weekends and Uganda's public holidays, so a
 * loan's 5-working-day cooling-off runs past Easter, Independence Day, Christmas and the new year, and an Eid the
 * platform admin moves changes the count.
 */
class WorkingDaysTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        WorkingDays::forget();
    }

    protected function tearDown(): void
    {
        WorkingDays::forget();
        parent::tearDown();
    }

    public function test_easter_is_computed_and_the_year_is_seeded_once(): void
    {
        foreach (['2024' => '2024-03-31', '2025' => '2025-04-20', '2026' => '2026-04-05', '2027' => '2027-03-28', '2030' => '2030-04-21'] as $y => $sunday) {
            $this->assertSame($sunday, PublicHolidays::easterSunday((int) $y)->toDateString());
        }
        $h = new PublicHolidays();
        $h->seed('UG', 2026);
        $this->assertSame(0, $h->seed('UG', 2026), 'seeding again adds nothing');
        $days = SaccoPublicHoliday::query()->where('country', 'UG')->whereYear('date', 2026)->pluck('name', 'date')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => $n])->all();
        $this->assertSame('Good Friday', $days['2026-04-03']);
        $this->assertSame('Easter Monday', $days['2026-04-06']);
        $this->assertSame('Independence Day', $days['2026-10-09']);
        $this->assertSame('Eid al-Fitr', $days['2026-03-20']);
        $this->assertCount(14, $days, '10 fixed, 2 Easter, 2 Eid');
        $this->assertTrue((bool) SaccoPublicHoliday::query()->where('date', '2026-03-20')->value('is_estimate'), 'the Eid waits for the gazette');
    }

    public function test_cooling_off_across_weekends_and_holidays(): void
    {
        $this->assertSame('2026-10-23', WorkingDays::add('2026-10-16', 5)->toDateString(), 'Friday + 5: the next Friday');
        $this->assertSame('2026-04-13', WorkingDays::add('2026-04-02', 5)->toDateString(), 'Good Friday and Easter Monday are skipped');
        $this->assertSame('2026-10-16', WorkingDays::add('2026-10-08', 5)->toDateString(), 'Independence Day (a Friday) is skipped');
        $this->assertSame('2026-12-31', WorkingDays::add('2026-12-23', 5)->toDateString(), 'Christmas is skipped; Boxing Day falls on a Saturday');
        $this->assertSame('2027-01-07', WorkingDays::add('2026-12-30', 5)->toDateString(), 'across New Year, seeding 2027 on the way');
        $this->assertFalse(WorkingDays::isWorkingDay('2026-06-03'), "Martyrs' Day");
        $this->assertFalse(WorkingDays::isWorkingDay('2026-10-17'), 'a Saturday');
        $this->assertTrue(WorkingDays::isWorkingDay('2026-10-21'));
        $this->assertSame(5, WorkingDays::between('2026-04-02', '2026-04-13'));
        $this->assertSame(0, WorkingDays::between('2026-04-13', '2026-04-02'));
    }

    public function test_an_eid_the_admin_moves_changes_the_count_and_reseeding_keeps_it(): void
    {
        $this->assertSame('2026-03-24', WorkingDays::add('2026-03-16', 5)->toDateString(), 'Eid al-Fitr expected on Friday 20 March');
        $h = new PublicHolidays();
        $h->set('UG', '2026-03-18', 'Eid al-Fitr (eve, declared)');
        $this->assertSame('2026-03-25', WorkingDays::add('2026-03-16', 5)->toDateString(), 'a second holiday that week pushes the end a day');
        $h->set('UG', '2026-03-19', 'Eid al-Fitr', '2026-03-20');
        $this->assertFalse(SaccoPublicHoliday::query()->where('date', '2026-03-20')->exists(), 'the estimate moved to its gazetted day');
        $this->assertTrue(WorkingDays::isWorkingDay('2026-03-20'));
        $this->assertFalse(WorkingDays::isWorkingDay('2026-03-19'));
        $this->assertFalse((bool) SaccoPublicHoliday::query()->where('date', '2026-03-19')->value('is_estimate'), 'confirmed, not an estimate');
        $h->seed('UG', 2026);
        $this->assertSame('Eid al-Fitr', SaccoPublicHoliday::query()->where('date', '2026-03-19')->value('name'), 'reseeding keeps the admin\'s day');
        // Reseeding puts the data file's estimate back too; the admin removes it again.
        $this->assertTrue($h->remove('UG', '2026-03-20'));
        $this->assertTrue(WorkingDays::isWorkingDay('2026-03-20'));
    }

    public function test_a_year_with_no_rows_is_seeded_on_first_use_and_bad_input_is_refused(): void
    {
        SaccoPublicHoliday::query()->whereYear('date', 2031)->delete();
        WorkingDays::forget();
        $this->assertFalse(WorkingDays::isWorkingDay('2031-01-01'), 'New Year 2031 without any seeding first');
        $this->assertTrue(SaccoPublicHoliday::query()->whereYear('date', 2031)->exists());
        $this->expectExceptionMessage('is not a date');
        (new PublicHolidays())->set('UG', '2026-02-30', 'Nonsense');
    }
}
