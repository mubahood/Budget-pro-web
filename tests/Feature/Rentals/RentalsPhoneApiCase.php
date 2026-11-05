<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Search\SearchIndex;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * What the phone's API tests share: one agency with two buildings, whose people are made up, on a stopped clock. EL_FIXTURES names a folder: when it is set, the real answers are also
 * written there for the phone's own tests. All of it is sample data.
 */
abstract class RentalsPhoneApiCase extends ApiTestCase
{
    use MakesRentals;

    protected const PASS = 'Tenant-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A staff member's headers. @return array<string, string> */
    protected function h(User $u): array
    {
        return $this->auth($u->createToken('t')->plainTextToken);
    }

    /**
     * Today is 26 March 2026. Kato Plaza (Margaret's): Amina paid up, Brian behind since February and promised for today, a vacant door. Lake View (Peter's): Carol behind, a door due today.
     * A caretaker looks after Kato Plaza only.
     *
     * @return array<string, mixed>
     */
    protected function world(): array
    {
        Carbon::setTestNow('2026-02-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['payment_instructions' => 'MTN MoMo 0700 000 100 (fictional)']);
        $company->forceFill(['name' => 'Kato Sample Agents', 'phone_number' => '0700 000 100'])->save();
        Subscription::where('company_id', $company->id)->update(['status' => 'active', 'trial_ends_at' => null, 'ends_at' => '2030-01-01 00:00:00']); // the clock moves on below
        $margaret = app(Landlords::class)->create($owner, ['name' => 'Margaret Fictional', 'phone' => '0700000201', 'email' => 'margaret'.uniqid().'@example.com', 'payout_method' => 'bank', 'payout_details' => 'Demo Bank, 0000 0101 (fictional)']);
        $peter = app(Landlords::class)->create($owner, ['name' => 'Peter Sample', 'phone' => '0700000202', 'payout_method' => 'cash']);
        [$p, $units] = $this->building($owner, 3, 300000, ['name' => 'Kato Plaza', 'area' => 'Ntinda', 'landlord_id' => $margaret->id]);
        [$q, $others] = $this->building($owner, 2, 200000, ['name' => 'Lake View', 'area' => 'Kisaasi', 'landlord_id' => $peter->id]);
        $tenant = fn (string $name, string $phone, array $more = []) => ['deposit_minor' => 0, 'tenant' => ['name' => $name, 'phone' => $phone, 'email' => strtolower(strtok($name, ' ')).uniqid().'@example.com'] + $more];
        $amina = $this->tenantIn($owner, $units[0], '2026-02-01', $tenant('Amina Fictional', '0772100001', ['id_number' => 'CF90SECRET789Z', 'notes' => 'Staff note about Amina']));
        $brian = $this->tenantIn($owner, $units[1], '2026-02-01', $tenant('Brian Sample', '0772100002'));
        $carol = $this->tenantIn($owner, $others[0], '2026-02-01', $tenant('Carol Madeup', '0772100003'));
        app(Payments::class)->receive($owner, $amina, ['amount_minor' => 300000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-01 09:00:00');
        app(Billing::class)->raiseAll((int) $company->id);
        app(Payments::class)->receive($owner, $amina, ['amount_minor' => 300000, 'method' => 'cash']);
        Carbon::setTestNow('2026-03-26 09:00:00');
        $dan = $this->tenantIn($owner, $others[1], '2026-03-26', $tenant('Dan Invented', '0772100004'));

        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $p, [$caretaker->id]);
        $accountant = $this->rentalsStaff($company, 'rentals_accountant');
        $viewer = $this->rentalsStaff($company, 'viewer');
        $urgentHere = app(Maintenance::class)->open($owner, (int) $company->id, ['unit_id' => $units[0]->id, 'title' => 'Kitchen tap leaking', 'priority' => 'urgent']);
        $urgentThere = app(Maintenance::class)->open($owner, (int) $company->id, ['unit_id' => $others[0]->id, 'title' => 'Roof at Lake View', 'priority' => 'urgent']);

        return compact('company', 'owner', 'margaret', 'peter', 'p', 'q', 'units', 'others', 'amina', 'brian', 'carol', 'dan', 'caretaker', 'accountant', 'viewer', 'urgentHere', 'urgentThere');
    }

    /** Fill the search index, as the night's job does: what a test wrote is committed only for the test. */
    protected function indexed(int $companyId): void
    {
        SearchIndex::flush();
        (new SearchIndex)->fill($companyId, true);
    }

    /** A tenant's or landlord's own token. @return array{0: array<string, string>, 1: RentalLogin} */
    protected function person(User $owner, string $type, int $subjectId): array
    {
        $login = app(RentalLogins::class)->enable($owner, $type, $subjectId, null, null, self::PASS);

        return [$this->auth(app(RentalLogins::class)->issueToken($login, 'test')), $login];
    }

    /** Another account altogether, with a staff token: nothing of the first one exists for it. @return array<string, string> */
    protected function stranger(): array
    {
        [, $other] = $this->rentalsAccount();

        return $this->h($other);
    }

    protected function assertKeys(array $data, array $keys, string $what = ''): void
    {
        $this->assertSame([], array_values(array_diff($keys, array_keys($data))), "missing from {$what}");
    }

    /** The answer, kept for the phone's tests when EL_FIXTURES is set. Tokens, signed links' signatures and the rest stay real only as far as they are harmless. */
    protected function fixture(string $name, mixed $data): void
    {
        $dir = getenv('EL_FIXTURES');
        if (! is_string($dir) || $dir === '' || ! is_dir($dir)) {
            return;
        }
        array_walk_recursive($data, function (&$v, $k) {
            if (is_string($v)) {
                if ($k === 'token') {
                    $v = '1|demo-token-for-tests';
                }
                $v = (string) preg_replace('/signature=[0-9a-f]+/', 'signature=test', $v);
                $v = (string) preg_replace('/\bhttps?:\/\/[^\/\s]+\//', 'https://example.test/', $v);
                $v = (string) preg_replace('/signature%3D[0-9a-f]+/i', 'signature%3Dtest', $v); // inside a WhatsApp or SMS link
                $v = (string) preg_replace('/https?%3A%2F%2F[^%\s]+%2F/i', 'https%3A%2F%2Fexample.test%2F', $v);
            }
        });
        file_put_contents(rtrim($dir, '/').'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    protected function landlordOf(array $w, string $who = 'margaret'): RentalLandlord
    {
        return $w[$who];
    }
}
