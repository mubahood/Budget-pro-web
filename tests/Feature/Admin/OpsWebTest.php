<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;

/** P4-6 on the web: System health for platform admins only; "Your data" for the owner. */
class OpsWebTest extends AdminTestCase
{
    public function test_health_page_and_your_data(): void
    {
        $admin = $this->makeTenant('admin');
        $this->asAdmin($admin['user'])->get('/system-health')->assertOk()->assertSee('Unresolved errors')->assertSee('Backups');

        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();
        $this->asAdmin($t['user'])->get('/system-health')->assertForbidden();
        $this->asAdmin($t['user'])->get('/your-data')->assertOk()->assertSee('Download all your data')->assertSee('Schedule deletion');
        $this->asAdmin($t['user'])->post('/your-data/export')->assertRedirect(admin_url('your-data'));
        $id = DB::table('data_requests')->where('company_id', $t['company']->id)->where('kind', 'export')->value('id');
        $this->asAdmin($t['user'])->get("/your-data/exports/{$id}")->assertOk()->assertDownload();
        $this->asAdmin($t['user'])->post('/your-data/delete', ['password' => 'secret123'])->assertRedirect();
        $this->asAdmin($t['user'])->get('/your-data')->assertSee('Keep my shop');
        $this->asAdmin($t['user'])->post('/your-data/delete/cancel')->assertRedirect();
        $this->assertSame('cancelled', DB::table('data_requests')->where('company_id', $t['company']->id)->where('kind', 'delete')->value('status'));
    }
}
