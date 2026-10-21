<?php

namespace Tests\Feature\Admin;

/** Classic System health shows config:doctor's list to platform admins, problems first, with their fix. */
class SystemHealthConfigTest extends AdminTestCase
{
    public function test_platform_admins_see_the_configuration_panel_with_each_fix(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://bp.example.test', 'queue.default' => 'sync',
            'saas.data_key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'saas.master_password_plaintext_set' => false,
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'saas.mail_enabled' => true, 'messaging.mail.driver' => 'mail',
            'messaging.otp.phone_codes' => true, 'messaging.sms.driver' => 'log', 'saas.config_doctor_accept' => ['sms'],
            'messaging.whatsapp.driver' => 'meta', 'messaging.whatsapp.meta.token' => 't', 'messaging.whatsapp.meta.phone_number_id' => '1',
            'flutterwave.secret_key' => 'FLWSECK-live-abc-X', 'flutterwave.public_key' => 'FLWPUBK-live-abc-X', 'flutterwave.secret_hash' => 'h',
            'support.whatsapp' => '256700000001', 'support.email' => '', 'support.hours' => 'Every day',
            'saas.new_ui_url' => 'https://portal.example.test', 'saas.invite_url' => 'https://portal.example.test',
            'sacco.nin_pepper' => 'a-long-random-pepper', 'sacco.payouts.driver' => 'off',
        ]);
        $admin = $this->makeTenant('admin');

        $this->asAdmin($admin['user'])->get('/system-health')->assertOk()
            ->assertSee('Configuration')
            ->assertSee('1 to fix')
            ->assertSeeInOrder(['queue_sync', 'The queue is sync', 'Fix: Set QUEUE_CONNECTION=database (ADR 0008).', 'support_details'])
            ->assertSee('Accepted by the owner')
            ->assertSee('settings are fine')
            ->assertSee('Unresolved errors');

        $shop = $this->makeTenant('company');
        $shop['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();
        $this->asAdmin($shop['user'])->get('/system-health')->assertForbidden();
    }
}
