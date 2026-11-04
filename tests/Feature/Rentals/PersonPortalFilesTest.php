<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLogin;
use App\Models\StoredFile;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\Portal\TenantRequests;
use App\Services\Rentals\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * What the web portal's `/my` adds to the people's read models (KR-16): their homes, a receipt and a statement as a file
 * for a page that serves it from the person's session (never someone else's), keeping a tenant's photo in a folder the
 * caller names, and saying whether a code can reach anyone at all.
 */
class PersonPortalFilesTest extends ApiTestCase
{
    use MakesRentals;

    /** @return array{0: \App\Models\User, 1: \App\Models\RentalLease, 2: RentalLogin} */
    private function tenant(): array
    {
        [, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2);
        $lease = $this->tenantIn($owner, $units[0], now()->startOfMonth()->toDateString(), ['tenant' => ['name' => 'Tina', 'phone' => '0701'.random_int(100000, 999999), 'email' => 't'.uniqid().'@example.com']]);

        return [$owner, $lease, app(RentalLogins::class)->enable($owner, 'tenant', (int) $lease->tenant_id, null, null, 'Secret-pass-123')];
    }

    public function test_homes_are_the_tenants_own_live_leases(): void
    {
        [, $lease, $login] = $this->tenant();
        [, $other] = $this->tenant();
        $homes = PersonView::for($login)->homes();
        $this->assertSame([(int) $lease->id], array_column($homes, 'id'));
        $this->assertNotContains((int) $other->id, array_column(PersonView::for($login)->homes(false), 'id'));
    }

    public function test_a_receipt_file_is_only_the_tenants_own(): void
    {
        [$owner, $lease, $login] = $this->tenant();
        [$otherOwner, $other] = $this->tenant();
        $mine = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 50000, 'method' => 'cash']);
        $theirs = app(Payments::class)->receive($otherOwner, $other, ['amount_minor' => 50000, 'method' => 'cash']);
        $file = PersonView::for($login)->receiptFile((int) $mine->id);
        $this->assertStringStartsWith('%PDF', $file['bytes']);
        $this->assertSame('receipt-'.$mine->number.'.pdf', $file['filename']);
        $this->assertNull(PersonView::for($login)->receiptFile((int) $theirs->id));
    }

    public function test_a_statement_file_is_for_the_landlord_alone(): void
    {
        [$owner, $lease, $tenantLogin] = $this->tenant();
        $login = app(RentalLogins::class)->enable($owner, 'landlord', (int) $lease->landlord_id, 'l'.uniqid().'@example.com', null, 'Secret-pass-123');
        $file = PersonView::for($login)->statementFile(now()->format('Y-m'));
        $this->assertStringStartsWith('%PDF', $file['bytes']);
        $this->expectException(BusinessRuleException::class);
        PersonView::for($tenantLogin)->statementFile(now()->format('Y-m'));
    }

    public function test_a_photo_is_kept_once_by_its_content_in_private_storage(): void
    {
        Storage::fake('public');
        [, , $login] = $this->tenant();
        $uuid = (string) Str::uuid();
        $requests = app(TenantRequests::class);
        $first = $requests->photo($login, $uuid, UploadedFile::fake()->image('tap.png', 20, 20));
        $this->assertFalse($first['replayed']);
        Storage::disk('public')->assertMissing($first['file']->path);
        $this->assertNotNull(PrivateFiles::path($first['file']->path));
        $this->assertStringEndsWith('.png', $first['file']->path);
        $this->assertTrue($requests->photo($login, $uuid, UploadedFile::fake()->image('tap.png', 20, 20))['replayed']);

        $this->assertSame('portal:'.$login->id, StoredFile::withoutGlobalScopes()->where('uuid', $first['file']->uuid)->value('device_id'));

        // A script under a photo's name is refused by what it is, not what it is called.
        $path = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, "<?php echo 1;\n");
        $script = new UploadedFile($path, 'tap.jpg', 'image/jpeg', null, true);
        try {
            $requests->photo($login, (string) Str::uuid(), $script);
            $this->fail('a script was kept as a photo');
        } catch (BusinessRuleException $e) {
            $this->assertSame('file_type', $e->errorCode());
        }
    }

    public function test_a_landlord_has_no_photos_and_a_reused_id_is_refused(): void
    {
        Storage::fake('public');
        [$owner, $lease, $tenantLogin] = $this->tenant();
        $login = app(RentalLogins::class)->enable($owner, 'landlord', (int) $lease->landlord_id, 'l'.uniqid().'@example.com');
        $uuid = (string) Str::uuid();
        $this->assertSame('not_found', $this->codeOf(fn () => app(TenantRequests::class)->photo($login, $uuid, UploadedFile::fake()->image('x.png'))));

        $neighbour = app(RentalLogins::class)->enable($owner, 'tenant', (int) $this->tenantIn($owner, \App\Models\RentalUnit::withoutGlobalScopes()->where('property_id', $lease->property_id)->where('id', '!=', $lease->unit_id)->firstOrFail(),
            now()->startOfMonth()->toDateString(), ['tenant' => ['name' => 'Ned', 'phone' => '0701'.random_int(100000, 999999), 'email' => 'n'.uniqid().'@example.com']])->tenant_id, null, null, 'Secret-pass-123');
        app(TenantRequests::class)->photo($tenantLogin, $uuid, UploadedFile::fake()->image('x.png'));
        $this->assertSame('idempotency_conflict', $this->codeOf(fn () => app(TenantRequests::class)->photo($neighbour, $uuid, UploadedFile::fake()->image('x.png'))));
    }

    public function test_codes_are_on_only_when_this_server_sends_email(): void
    {
        config(['messaging.mail.driver' => 'log', 'mail.default' => 'smtp']);
        $this->assertFalse(RentalLogins::codesOn());
        config(['messaging.mail.driver' => 'mail', 'mail.default' => 'log']);
        $this->assertFalse(RentalLogins::codesOn());
        config(['messaging.mail.driver' => 'mail', 'mail.default' => 'smtp']);
        $this->assertTrue(RentalLogins::codesOn());
    }

    public function test_the_refusals_a_tenant_reads_name_no_agency_or_office(): void
    {
        [$owner, $lease, $login] = $this->tenant();
        $day = now()->addMonth()->toDateString();
        app(\App\Services\Rentals\Leases\Leases::class)->notice($owner, $lease, now()->toDateString(), $day);
        try {
            app(TenantRequests::class)->notice($login, ['move_out_on' => $day]);
            $this->fail('notice on a lease already under notice');
        } catch (BusinessRuleException $e) {
            $this->assertStringNotContainsStringIgnoringCase('office', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('agency', $e->getMessage());
        }
    }

    private function codeOf(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'nothing refused';
    }
}
