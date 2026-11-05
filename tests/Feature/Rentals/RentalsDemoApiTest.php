<?php

namespace Tests\Feature\Rentals;

use App\Models\User;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Search\SearchIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The phone's demo, on the real demo builder: the role picker, one tap per role with no password, the people's own tokens, and Today's figures against the overview the web reads. A year of
 * books is built once, so these are one test, on a stopped clock.
 */
class RentalsDemoApiTest extends RentalsPhoneApiCase
{
    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rentals.demo.enabled' => true, 'edition.name' => 'saas']);
        $this->disk = sys_get_temp_dir().'/rentals-demo-api-'.uniqid();
        Storage::set('public', Storage::createLocalDriver(['root' => $this->disk]));
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disk);
        parent::tearDown();
    }

    public function test_the_demo_on_the_phone_from_the_picker_to_each_role(): void
    {
        // Before it is built: no roles to offer.
        $before = $this->getJson('/api/v1/demo/session?app=estate-ledger')->assertOk()->json('data');
        if (! $before['ready']) {
            $this->assertSame([], array_filter($before['roles'], fn ($r) => $r['account'] === 'agency'));
            $this->postJson('/api/v1/rentals/portal-auth/demo', ['role' => 'tenant'])->assertStatus(409)->assertJsonPath('errors.code', 'demo_rebuilding');
        }

        $first = (new RentalsDemo)->run(true);
        $this->assertSame([], $first['skipped']);
        $company = RentalsDemo::company();
        $cid = (int) $company->id;

        // The picker: everyone, with no sign-in details.
        $res = $this->getJson('/api/v1/demo/session?app=estate-ledger')->assertOk();
        $d = $res->json('data');
        $this->assertSame(['app', 'ready', 'roles'], array_keys($d));
        $this->assertTrue($d['ready']);
        $keys = array_map(fn ($r) => $r['account'].':'.$r['role'], $d['roles']);
        $this->assertEqualsCanonicalizing(['agency:owner', 'agency:manager', 'agency:accountant', 'agency:caretaker', 'agency:viewer', 'agency:tenant', 'agency:tenant-paid', 'agency:landlord', 'solo:owner'], $keys);
        foreach ($d['roles'] as $r) {
            $this->assertSame(['role', 'label', 'what', 'name', 'type', 'account'], array_keys($r));
            $this->assertNotSame('', $r['name'], $r['role']);
            $this->assertNotSame('', $r['what'], $r['role']);
        }
        $this->assertSame('tenant', collect($d['roles'])->firstWhere('role', 'tenant')['type']);
        $this->assertSame('landlord', collect($d['roles'])->firstWhere('role', 'landlord')['type']);
        $text = (string) $res->getContent();
        $this->assertStringNotContainsString(RentalsDemo::password(), $text);
        $this->assertStringNotContainsString('@', $text, 'no sign-in addresses');
        foreach (['Budget Pro', 'Shelfie', 'Kuku', 'Akiba', 'Pledge'] as $other) {
            $this->assertStringNotContainsString($other, $text, 'it stands alone');
        }
        $this->fixture('demo_roles', $res->json());

        // Staff: one tap, a short token, the staff app.
        $session = $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger', 'role' => 'manager', 'device_name' => 'phone'])->assertOk();
        $s = $session->json('data');
        $this->assertSame(['token', 'token_type', 'expires_at', 'demo', 'app', 'account', 'user', 'company'], array_keys($s));
        $this->assertTrue($s['demo']);
        $this->assertSame('agency', $s['account']);
        $this->assertSame('2026-10-16T22:00:00+00:00', Carbon::parse($s['expires_at'])->utc()->toIso8601String());
        $this->assertSame($cid, $s['company']['id']);
        $this->assertSame('Rachel Namugga', $s['user']['name']);

        // The landlord on his own: his own account, in owner mode.
        $solo = $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger', 'role' => 'owner', 'account' => 'solo'])->assertOk()->json('data');
        $this->assertNotSame($cid, $solo['company']['id']);
        $this->assertSame('owner', $this->getJson('/api/v1/rentals/rent-book', $this->auth($solo['token']))->assertOk()->json('data.mode'));
        // Refused: a person's role here, an unknown role, an unknown account. Never the owner by default.
        foreach ([['role' => 'tenant'], ['role' => 'nobody'], ['role' => 'owner', 'account' => 'other'], ['role' => ['owner']]] as $body) {
            $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger'] + $body)->assertStatus(422)->assertJsonPath('errors.code', 'demo_unknown_role');
        }

        // The agency's figures on Today are the web's overview for each role.
        foreach (['owner', 'accountant'] as $role) {
            $token = $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger', 'role' => $role])->assertOk()->json('data.token');
            $today = $this->getJson('/api/v1/rentals/today', $this->auth($token))->assertOk()->json('data');
            $overview = Figures::overview(RentalsDemo::login($role));
            foreach (['units', 'occupied', 'vacant', 'occupancy_pct', 'month', 'arrears', 'credit_minor', 'deposits_minor', 'landlords_minor', 'maintenance_open', 'ending_30', 'cash', 'attention'] as $k) {
                $this->assertEquals($overview[$k], $today['figures'][$k], "{$role}: {$k}");
            }
            // Chase's total: one line for each tenant Arrears finds.
            $tenants = count(array_unique(array_map(fn ($r) => $r['tenant']['id'], Arrears::list($cid, RentalsDemo::login($role)))));
            $chase = collect($today['groups'])->firstWhere('key', 'chase');
            $this->assertSame($tenants, $chase['total'] ?? 0, $role);
            $this->assertLessThanOrEqual(5, count($chase['lines']));
            // Whose money, only with landlords' money in hand.
            $this->assertNotNull($today['money_position']);
        }

        // Every line links to something the book or a screen can open.
        foreach ($today['groups'] as $g) {
            foreach ($g['lines'] as $line) {
                $this->assertContains($line['link']['type'], ['lease', 'maintenance', 'units', 'arrears', 'billing', 'expenses', 'landlords', 'report', 'month_end'], $line['kind']);
            }
        }

        // The caretaker: their two buildings' figures, in the book, Today and search.
        $care = $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger', 'role' => 'caretaker'])->assertOk()->json('data.token');
        $h = $this->auth($care);
        $caretaker = RentalsDemo::login('caretaker');
        $book = $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Kiwatule Flats', 'Najjera Rooms'], array_column($book['properties'], 'name'));
        $this->assertSame(Figures::overview($caretaker)['units'], $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data.figures.units'));
        $this->assertNull($this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data.figures.landlords_minor'));
        $this->assertNull($this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data.money_position'));
        $this->getJson('/api/v1/rentals/month-end', $h)->assertStatus(403);
        (new SearchIndex)->fill($cid, true);
        $this->assertSame([], $this->getJson('/api/v1/rentals/search?q=Kisaasi', $h)->assertOk()->json('data.groups'), 'another building');

        // The people: their own token, on their own routes.
        $tenant = $this->postJson('/api/v1/rentals/portal-auth/demo', ['role' => 'tenant', 'device_name' => 'phone'])->assertStatus(201);
        $this->assertSame(['token', 'expires_at', 'demo', 'person'], array_keys($tenant->json('data')));
        $this->assertSame('tenant', $tenant->json('data.person.type'));
        $th = $this->auth($tenant->json('data.token'));
        $home = $this->getJson('/api/v1/rentals/me/home', $th)->assertOk()->json('data');
        $this->assertGreaterThan(0, $home['owed_minor'], 'the tenant behind on rent');
        $this->getJson('/api/v1/rentals/today', $th)->assertStatus(401);
        $landlord = $this->postJson('/api/v1/rentals/portal-auth/demo', ['role' => 'landlord'])->assertStatus(201)->json('data');
        $this->assertSame('landlord', $landlord['person']['type']);
        $this->getJson('/api/v1/rentals/me/home', $this->auth($landlord['token']))->assertOk();
        $this->assertLessThan(13 * 3600, Carbon::parse($landlord['expires_at'])->diffInSeconds(now(), true), 'as short as a staff demo token');
        foreach ([['role' => 'owner'], ['role' => 'nobody'], ['role' => 'tenant', 'account' => 'solo']] as $body) {
            $this->postJson('/api/v1/rentals/portal-auth/demo', $body)->assertStatus(422)->assertJsonPath('errors.code', 'demo_unknown_role');
        }

        // No demo on this server.
        config(['rentals.demo.enabled' => false]);
        $this->getJson('/api/v1/demo/session?app=estate-ledger')->assertStatus(404);
        $this->postJson('/api/v1/demo/session', ['app' => 'estate-ledger', 'role' => 'owner'])->assertStatus(404);
        $this->postJson('/api/v1/rentals/portal-auth/demo', ['role' => 'tenant'])->assertStatus(404);
        $this->assertNotNull(User::withoutGlobalScopes()->find($caretaker->id));
    }
}
