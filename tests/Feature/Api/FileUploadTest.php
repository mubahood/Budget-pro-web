<?php

namespace Tests\Feature\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadTest extends ApiTestCase
{
    public function test_upload_is_idempotent_by_uuid_and_tenant_scoped(): void
    {
        Storage::fake('public');
        $t = $this->registerTenant();
        $uuid = (string) Str::uuid();
        $res = $this->post('/api/v1/files', ['uuid' => $uuid, 'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('cola.jpg', 600, 600)], $this->auth($t['token']) + ['Accept' => 'application/json']);
        $res->assertStatus(201)->assertJsonPath('data.file_uuid', $uuid);
        Storage::disk('public')->assertExists($res->json('data.path'));
        $this->assertStringContainsString("files/{$t['company_id']}/", $res->json('data.path'));

        $again = $this->post('/api/v1/files', ['uuid' => $uuid, 'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('cola.jpg')], $this->auth($t['token']) + ['Accept' => 'application/json']);
        $again->assertOk()->assertJsonPath('data.path', $res->json('data.path'));

        $this->post('/api/v1/files', ['uuid' => (string) Str::uuid(), 'purpose' => 'product_image', 'file' => UploadedFile::fake()->create('x.exe', 10)], $this->auth($t['token']) + ['Accept' => 'application/json'])->assertStatus(422);
    }
}
