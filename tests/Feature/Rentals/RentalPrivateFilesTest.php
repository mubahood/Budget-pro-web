<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLease;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Rentals\Documents;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\RentalLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Tenants' photos and papers are on a real folder of their own, not a fake: nothing reaches the public disk, and they
 * open only through a signed link, for the account and the person it was made for.
 */
class RentalPrivateFilesTest extends ApiTestCase
{
    use MakesRentals;

    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->publicRoot = sys_get_temp_dir().'/rental-public-'.uniqid('', true);
        Storage::set('public', Storage::createLocalDriver(['root' => $this->publicRoot, 'url' => 'http://media.test/storage']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->publicRoot);
        parent::tearDown();
    }

    /** @return array{0: \App\Models\Company, 1: User, 2: array<string, string>} */
    private function staff(): array
    {
        [$company, $owner] = $this->rentalsAccount();

        return [$company, $owner, $this->auth($owner->createToken('t')->plainTextToken) + ['Accept' => 'application/json']];
    }

    /** A real PNG under whatever name the client sends. */
    private function png(string $name = 'tap.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function upload(array $h, string $purpose = 'rental_photo', ?UploadedFile $file = null): TestResponse
    {
        return $this->post('/api/v1/files', ['uuid' => (string) Str::uuid(), 'purpose' => $purpose, 'file' => $file ?? $this->png()], $h);
    }

    private function assertNotPublic(string $path): void
    {
        $this->assertFileDoesNotExist($this->publicRoot.'/'.$path);
        $this->assertFileDoesNotExist(public_path('storage/'.$path));
        $this->get('/storage/'.$path)->assertNotFound();
    }

    public function test_the_folder_is_one_path_outside_every_public_folder(): void
    {
        $root = (require base_path('config/rentals.php'))['private_root'];
        $this->assertSame(realpath(base_path()).'/storage/app/rental-private', $root);
        $this->assertStringStartsNotWith(realpath(public_path()) ?: public_path(), $root);
        $this->assertStringStartsNotWith(storage_path('app/public'), $root);
        foreach ((array) config('filesystems.links') as $target) {
            $this->assertStringStartsNotWith((string) $target, $root);
        }
        $this->assertTrue(is_file(base_path('storage/app/rental-private/.gitignore')));

        config(['rentals.private_root' => public_path('storage/rental-private')]);
        $this->expectException(\RuntimeException::class);
        PrivateFiles::root();
    }

    public function test_an_api_upload_is_private_with_a_signed_link_and_the_same_shape(): void
    {
        [, $owner, $h] = $this->staff();
        foreach (['rental_photo', 'rental_document'] as $purpose) {
            $data = $this->upload($h, $purpose)->assertStatus(201)->json('data');
            $this->assertSame(['file_uuid', 'path', 'url', 'purpose', 'size_bytes'], array_keys($data));
            $this->assertStringContainsString('signature=', $data['url']);
            $this->assertStringContainsString('expires=', $data['url']);
            $this->assertStringContainsString('/rentals/files/'.$data['file_uuid'], $data['url']);
            $this->assertNotPublic($data['path']);
            $this->assertNotNull(PrivateFiles::path($data['path']));

            $open = $this->get($data['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('inline', (string) $open->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', (string) $open->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $open->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('public', (string) $open->headers->get('Cache-Control'));
        }

        // Kept to the server's own user; a retry answers with a signed link too.
        $this->assertSame('700', substr(sprintf('%o', fileperms(PrivateFiles::root())), -3));
        $uuid = (string) Str::uuid();
        $first = $this->post('/api/v1/files', ['uuid' => $uuid, 'purpose' => 'rental_photo', 'file' => $this->png()], $h)->json('data');
        $this->assertSame('600', substr(sprintf('%o', fileperms((string) PrivateFiles::path($first['path']))), -3));
        $again = $this->post('/api/v1/files', ['uuid' => $uuid, 'purpose' => 'rental_photo', 'file' => $this->png()], $h)->assertOk()->json('data');
        $this->assertStringContainsString('signature=', $again['url']);
        $this->assertStringContainsString('by_id='.$owner->id, $again['url']);

        // No person named: no link, never a public address.
        $this->assertNull(StoredFile::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail()->url());
    }

    public function test_the_type_comes_from_the_content_never_the_clients_file_name(): void
    {
        [, , $h] = $this->staff();
        $named = $this->upload($h, 'rental_photo', $this->png('evil.html'))->assertStatus(201)->json('data');
        $this->assertStringEndsWith('.png', $named['path']);

        $script = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($script, "<?php echo 1;\n");
        $this->upload($h, 'rental_document', new UploadedFile($script, 'id.jpg', 'image/jpeg', null, true))->assertStatus(422);
        $this->assertCount(1, File::allFiles(PrivateFiles::root()), 'the refused one is not kept');
    }

    public function test_a_tenants_upload_is_private_and_the_payload_gives_only_signed_links(): void
    {
        [, $owner] = $this->staff();
        [, $units] = $this->building($owner, 1);
        $lease = $this->tenantIn($owner, $units[0], now()->startOfMonth()->toDateString(), ['tenant' => ['name' => 'Tina', 'phone' => '0701'.random_int(100000, 999999), 'email' => 't'.uniqid().'@example.com']]);
        $login = app(RentalLogins::class)->enable($owner, 'tenant', (int) $lease->tenant_id, null, null, 'Secret-pass-123');
        $h = $this->auth(app(RentalLogins::class)->issueToken($login, 'test'));
        $uuid = (string) Str::uuid();

        $this->post('/api/v1/rentals/me/files', ['uuid' => $uuid, 'file' => $this->png()], $h + ['Accept' => 'application/json'])->assertStatus(201)->assertJsonPath('data.file_uuid', $uuid);
        $file = StoredFile::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $this->assertNotPublic($file->path);
        $this->assertNotNull(PrivateFiles::path($file->path));

        $photo = $this->postJson('/api/v1/rentals/me/maintenance', ['uuid' => (string) Str::uuid(), 'title' => 'Tap', 'photos' => [$uuid]], $h)->assertStatus(201)->json('data.photos.0.url');
        $this->assertStringContainsString('signature=', $photo);
        $this->assertStringContainsString('by=portal', $photo);
        $this->assertStringNotContainsString('/storage/', $photo);
        $this->get($photo)->assertOk();

        // The sign-in closed: the link stops working.
        app(RentalLogins::class)->disable($owner, 'tenant', (int) $lease->tenant_id);
        $this->get($photo)->assertNotFound();
    }

    public function test_a_tenants_photo_on_the_staff_payload_is_signed_for_that_staff_member(): void
    {
        [, $owner, $h] = $this->staff();
        $uuid = $this->upload($h)->json('data.file_uuid');
        $id = $this->postJson('/api/v1/rentals/tenants', ['name' => 'Tina', 'phone' => '0701'.random_int(100000, 999999), 'photo_uuid' => $uuid], $h)->assertStatus(201)->json('data.photo_url');
        $this->assertStringContainsString('signature=', $id);
        $this->assertStringContainsString('by=staff', $id);
        $this->assertStringContainsString('by_id='.$owner->id, $id);
        $this->get($id)->assertOk();
    }

    public function test_an_expired_a_tampered_and_an_unsigned_link_are_refused(): void
    {
        [$company, $owner, $h] = $this->staff();
        $data = $this->upload($h)->json('data');
        $url = $data['url'];
        $this->get($url)->assertOk();
        $this->get('/api/v1/rentals/files/'.$data['file_uuid'].'?company_id='.$company->id)->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $url))->assertForbidden();
        $this->get(str_replace('by_id='.$owner->id, 'by_id='.($owner->id + 1), $url))->assertForbidden();
        Carbon::setTestNow(now()->addDays(9));
        $this->get($url)->assertForbidden();
    }

    public function test_another_accounts_link_is_refused(): void
    {
        [$company, $owner, $h] = $this->staff();
        [$otherCompany, $stranger] = $this->rentalsAccount();
        $data = $this->upload($h)->json('data');
        $uuid = $data['file_uuid'];

        $this->assertNull(RentalLinks::file((int) $otherCompany->id, $uuid, 'staff', (int) $stranger->id), 'it is not theirs to link');
        $this->get(str_replace('company_id='.$company->id, 'company_id='.$otherCompany->id, $data['url']))->assertForbidden();

        // Even a genuine signature: under their account no such file; under this one, they are not its staff.
        $sign = fn (int $cid, int $by) => url(URL::temporarySignedRoute('rentals.file', now()->addDay(), ['uuid' => $uuid, 'company_id' => $cid, 'by' => 'staff', 'by_id' => $by], false));
        $this->get($sign((int) $otherCompany->id, (int) $stranger->id))->assertNotFound();
        $this->get($sign((int) $company->id, (int) $stranger->id))->assertNotFound();
        $this->get($sign((int) $company->id, (int) $owner->id))->assertOk();

        // A signed link naming no person opens nothing private.
        $this->get(url(URL::temporarySignedRoute('rentals.file', now()->addDay(), ['uuid' => $uuid, 'company_id' => (int) $company->id], false)))->assertNotFound();
    }

    public function test_an_id_copy_opens_only_for_those_who_manage_tenants_and_removing_it_deletes_the_file(): void
    {
        [$company, $owner, $h] = $this->staff();
        [, $units] = $this->building($owner, 1);
        /** @var RentalLease $lease */
        $lease = $this->tenantIn($owner, $units[0], now()->startOfMonth()->toDateString());
        $data = $this->upload($h, 'rental_document')->json('data');
        $doc = app(Documents::class)->add($owner, 'tenant', (int) $lease->tenant_id, ['file_uuid' => $data['file_uuid'], 'name' => 'National ID', 'kind' => 'id']);

        $listed = $this->getJson('/api/v1/rentals/documents?owner_type=tenant&owner_id='.$lease->tenant_id, $h)->assertOk()->json('data.0.url');
        $this->assertStringContainsString('signature=', $listed);
        $this->get($listed)->assertOk();

        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->get(RentalLinks::file((int) $company->id, $data['file_uuid'], 'staff', (int) $caretaker->id))->assertNotFound();
        $tenantLogin = app(RentalLogins::class)->enable($owner, 'tenant', (int) $lease->tenant_id, null, null, 'Secret-pass-123');
        $this->get(RentalLinks::file((int) $company->id, $data['file_uuid'], 'portal', (int) $tenantLogin->id))->assertNotFound();

        $absolute = (string) PrivateFiles::path($data['path']);
        $this->assertFileExists($absolute);
        app(Documents::class)->remove($owner, $doc);
        $this->assertFileDoesNotExist($absolute);
        $this->get($listed)->assertNotFound();
    }

    public function test_only_images_and_pdfs_show_in_the_page(): void
    {
        [$company, $owner] = $this->staff();
        $link = function (string $mime, string $ext) use ($company, $owner) {
            $uuid = (string) Str::uuid();
            $path = "files/{$company->id}/{$uuid}.{$ext}";
            PrivateFiles::put($path, 'x');
            StoredFile::withoutGlobalScopes()->create(['company_id' => $company->id, 'uuid' => $uuid, 'purpose' => 'rental_document', 'path' => $path, 'mime' => $mime, 'size_bytes' => 1]);

            return RentalLinks::file((int) $company->id, $uuid, 'staff', (int) $owner->id);
        };
        $this->get($link('application/pdf', 'pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $old = $this->get($link('text/html', 'html'))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', (string) $old->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.bin"', (string) $old->headers->get('Content-Disposition'));
    }

    public function test_every_other_purpose_stays_on_the_public_disk_unchanged(): void
    {
        [$company, , $h] = $this->staff();
        foreach (['product_image', 'receipt', 'logo'] as $purpose) {
            $data = $this->upload($h, $purpose, $this->png('cola.png'))->assertStatus(201)->json('data');
            $this->assertFileExists($this->publicRoot.'/'.$data['path']);
            $this->assertNull(PrivateFiles::path($data['path']));
            $this->assertSame('http://media.test/storage/'.$data['path'], $data['url']);
            $this->assertStringNotContainsString('signature=', $data['url']);
        }

        // An expense's receipt still opens by its link, which names no person.
        $link = RentalLinks::file((int) $company->id, $data['file_uuid']);
        $this->assertStringNotContainsString('by_id', $link);
        $this->get($link)->assertOk();
    }

    public function test_older_files_are_moved_off_the_public_folder_and_running_again_changes_nothing(): void
    {
        [$company] = $this->staff();
        $uuid = (string) Str::uuid();
        $path = "files/{$company->id}/2026/01/{$uuid}.png";
        Storage::disk('public')->put($path, 'old-bytes');
        StoredFile::withoutGlobalScopes()->create(['company_id' => $company->id, 'uuid' => $uuid, 'purpose' => 'rental_photo', 'path' => $path, 'mime' => 'image/png', 'size_bytes' => 9]);
        $logo = "files/{$company->id}/logo.png";
        Storage::disk('public')->put($logo, 'logo-bytes');
        StoredFile::withoutGlobalScopes()->create(['company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'purpose' => 'logo', 'path' => $logo, 'mime' => 'image/png', 'size_bytes' => 10]);

        $this->assertSame(0, Artisan::call('rentals:files-private'));
        $this->assertFileDoesNotExist($this->publicRoot.'/'.$path);
        $this->assertSame('old-bytes', file_get_contents((string) PrivateFiles::path($path)));
        $this->assertFileExists($this->publicRoot.'/'.$logo, 'other purposes stay');

        $this->assertSame(0, Artisan::call('rentals:files-private'));
        $this->assertSame('old-bytes', file_get_contents((string) PrivateFiles::path($path)));
    }
}
