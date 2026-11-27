<?php

namespace Tests\Feature\Kin;

use App\Models\Company;
use App\Models\KinAnnouncement;
use App\Models\SaccoMember;
use App\Services\Kin\FamilyStats;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/** GET /api/v1/kin/stats, counted by hand over a small made-up family. */
class KinStatsTest extends ApiTestCase
{
    use MakesKin;

    private Company $co;

    private function person(array $data): int
    {
        return (int) SaccoMember::withoutGlobalScopes()->create($data + ['company_id' => $this->co->id, 'kind' => 'individual', 'status' => 'active', 'country' => 'Uganda'])->id;
    }

    public function test_the_figures_match_a_hand_count(): void
    {
        [$this->co, , $token] = $this->kinAccount();
        $h = ['Authorization' => 'Bearer '.$token];
        $year = (int) now()->year;

        // Living: Amos (1950, 66-80), Ruth (year 1940 only, 81+), Joel (1985, 36-50), Ada (2015, 0-12), Kim (no birth date).
        $amos = $this->person(['first_name' => 'Amos', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1950-01-01', 'birth_date_precision' => 'exact',
            'education_level' => 'degree', 'district' => 'Kampala', 'occupation' => 'Farmer']);
        $ruth = $this->person(['first_name' => 'Ruth', 'last_name' => 'Sample', 'gender' => 'female', 'date_of_birth' => '1940-01-01', 'birth_date_precision' => 'year',
            'education_level' => 'primary', 'district' => 'Kampala', 'occupation' => 'Farmer']);
        $this->person(['first_name' => 'Joel', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1985-01-01', 'birth_date_precision' => 'exact',
            'education_level' => 'masters', 'district' => 'Mbarara', 'occupation' => 'Teacher', 'country' => 'Kenya']);
        $this->person(['first_name' => 'Ada', 'last_name' => 'Sample', 'gender' => 'female', 'date_of_birth' => '2015-01-01', 'birth_date_precision' => 'exact', 'education_level' => 'primary']);
        $this->person(['first_name' => 'Kim', 'last_name' => 'Sample', 'gender' => 'female']);
        // Not counted among the living: one departed, one who left the family's books.
        $this->person(['first_name' => 'Yakobo', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1920-01-01', 'status' => 'deceased', 'date_of_death' => '1990-01-01',
            'education_level' => 'phd', 'district' => 'Kampala']);
        $this->person(['first_name' => 'Leo', 'last_name' => 'Sample', 'gender' => 'male', 'date_of_birth' => '1990-01-01', 'status' => 'exited', 'education_level' => 'phd']);

        KinAnnouncement::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'title' => 'Older news', 'published_at' => now()->subDay()]);
        KinAnnouncement::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'title' => 'Pinned news', 'pinned' => true, 'published_at' => now()->subDays(5)]);
        KinAnnouncement::withoutGlobalScopes()->create(['company_id' => $this->co->id, 'title' => 'Later news', 'published_at' => now()->addDay()]);

        $d = $this->getJson('/api/v1/kin/stats', $h)->assertOk()->json('data');

        $this->assertSame(['total' => 7, 'living' => 5, 'late' => 1, 'male' => 2, 'female' => 3, 'unknown_gender' => 0], $d['population']);
        $this->assertSame(array_keys(FamilyStats::AGE_BANDS) + [8 => 'unknown'], array_column($d['age_bands'], 'key'), 'fixed band keys, in order');
        $bands = array_column($d['age_bands'], 'count', 'key');
        $this->assertSame(['0-12' => 1, '13-17' => 0, '18-25' => 0, '26-35' => 0, '36-50' => 1, '51-65' => 0, '66-80' => 1, '81+' => 1, 'unknown' => 1], $bands);
        $this->assertSame(FamilyStats::EDUCATION, array_column($d['education'], 'key'));
        $edu = array_column($d['education'], 'count', 'key');
        $this->assertSame(['none' => 0, 'primary' => 2, 'secondary' => 0, 'certificate' => 0, 'diploma' => 0, 'degree' => 1, 'masters' => 1, 'phd' => 0, 'unknown' => 1], $edu);
        $this->assertSame([['name' => 'Uganda', 'count' => 4], ['name' => 'Kenya', 'count' => 1]], $d['places']['countries']);
        $this->assertSame(['name' => 'Kampala', 'count' => 2], $d['places']['districts'][0]);
        $this->assertSame(['name' => 'Farmer', 'count' => 2], $d['occupations'][0]);
        $this->assertSame(['id' => $ruth, 'name' => 'Ruth Sample', 'age' => $year - 1940], $d['facts']['eldest_living']);
        $this->assertSame(1, $d['facts']['missing_birth_date']);
        $this->assertNotSame($amos, $d['facts']['youngest']['id']);
        $this->assertSame('Pinned news', $d['announcement']['title']);
        $this->assertFalse($d['purse']['enabled']);
        $this->assertSame(0, $d['dues']['active']);
    }
}
