<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Admin\AdminTestCase;

/** TransferService::assignDevice: which location a phone sells from (classic Locations, devices form). */
class TransferServiceAssignDeviceTest extends AdminTestCase
{
    private function device(int $companyId, array $extra = []): int
    {
        return (int) DB::table('devices')->insertGetId($extra + ['company_id' => $companyId, 'device_id' => (string) Str::uuid(), 'name' => 'Counter', 'number_prefix' => strtoupper(Str::random(4)),
            'prefix_index' => random_int(1, 9999), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_phone_is_assigned_to_a_location_and_back_to_the_main_one(): void
    {
        LocationStock::flush();
        $t = $this->makeTenant('company');
        $cid = (int) $t['company']->id;
        $main = LocationStock::defaultLocation($cid);
        $store = (int) DB::table('locations')->insertGetId(['company_id' => $cid, 'name' => 'Store', 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $phone = $this->device($cid);

        (new TransferService)->assignDevice($cid, $phone, $store);
        $this->assertSame($store, (int) DB::table('devices')->where('id', $phone)->value('location_id'));
        (new TransferService)->assignDevice($cid, $phone, $main);
        $this->assertSame($main, (int) DB::table('devices')->where('id', $phone)->value('location_id'));
        (new TransferService)->assignDevice($cid, $phone, null);
        $this->assertNull(DB::table('devices')->where('id', $phone)->value('location_id'));
    }

    public function test_refusals_other_shops_revoked_phones_and_closed_locations(): void
    {
        LocationStock::flush();
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');
        $cid = (int) $a['company']->id;
        $other = (int) $b['company']->id;
        $theirLocation = LocationStock::defaultLocation($other);
        $closed = (int) DB::table('locations')->insertGetId(['company_id' => $cid, 'name' => 'Old', 'is_default' => false, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $phone = $this->device($cid);
        $theirPhone = $this->device($other);
        $revoked = $this->device($cid, ['status' => 'revoked', 'revoked_at' => now()]);

        foreach ([[$phone, $theirLocation, 'location_not_found'], [$theirPhone, null, 'device_not_found'], [$revoked, null, 'device_revoked'], [$phone, $closed, 'location_closed']] as [$d, $loc, $code]) {
            try {
                (new TransferService)->assignDevice($cid, $d, $loc);
                $this->fail("expected {$code}");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode(), $e->getMessage());
            }
        }
        $this->assertNull(DB::table('devices')->where('id', $phone)->value('location_id'));
    }
}
