<?php

namespace Tests\Feature;

use App\Support\ConfigDoctor;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * App\Support\ConfigDoctor and `config:doctor` (docs/roadmap/02 §1): each rule in production on the SaaS,
 * on standalone and locally, the owner's acceptances, and the command's table, JSON and exit code.
 * Only config is set here; the real environment is never touched.
 */
class ConfigDoctorTest extends TestCase
{
    /** A production SaaS server with everything right, plus $over. */
    private function production(array $over = []): void
    {
        config(array_merge([
            'app.env' => 'production', 'edition.name' => 'saas', 'app.debug' => false, 'app.url' => 'https://bp.example.test',
            'queue.default' => 'database', 'saas.data_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'saas.master_password_plaintext_set' => false, 'saas.config_doctor_accept' => [],
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'saas.mail_enabled' => true,
            'messaging.mail.driver' => 'mail',
            'messaging.sms.driver' => 'africastalking', 'messaging.sms.africastalking.username' => 'bp', 'messaging.sms.africastalking.api_key' => 'at-key',
            'messaging.whatsapp.driver' => 'meta', 'messaging.whatsapp.meta.token' => 'wa-token', 'messaging.whatsapp.meta.phone_number_id' => '1234',
            'flutterwave.secret_key' => 'FLWSECK-live-abc-X', 'flutterwave.public_key' => 'FLWPUBK-live-abc-X', 'flutterwave.secret_hash' => 'hash',
            'support.whatsapp' => '256700000001', 'support.email' => 'help@example.test', 'support.hours' => 'Every day',
            'saas.new_ui_url' => 'https://portal.example.test', 'saas.invite_url' => 'https://portal.example.test',
            'site.legal_name' => 'Example Ltd', 'site.address' => 'Plot 1, Kampala', 'site.support_email' => 'help@example.test', 'site.support_hours' => 'Every day',
        ], $over));
    }

    /** @return array<string, array{key:string, status:string, message:string, fix:string, accepted:bool}> */
    private function checks(string $app = ConfigDoctor::KERNEL): array
    {
        $out = [];
        foreach ((new ConfigDoctor($app))->checks() as $check) {
            $out[$check['key']] = $check;
        }

        return $out;
    }

    public function test_a_healthy_production_saas_server_passes_every_check(): void
    {
        $this->production();
        $checks = $this->checks();

        $this->assertSame(array_values(array_diff(ConfigDoctor::KEYS, ['connections', 'site_facts', 'accepted'])), array_keys($checks));
        $this->assertSame([], array_keys(array_filter($checks, fn ($c) => $c['status'] !== ConfigDoctor::OK)));
        $this->assertSame(ConfigDoctor::OK, ConfigDoctor::overall($checks));
        $this->assertSame('', $checks['queue_sync']['fix'], 'A passing check has no fix.');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function failures(): array
    {
        return [
            'debug on' => [['app.debug' => true], 'app_debug'],
            'no such edition' => [['edition.name' => 'cloud'], 'edition'],
            'sync queue' => [['queue.default' => 'sync'], 'queue_sync'],
            'null queue' => [['queue.default' => 'null'], 'queue_sync'],
            'no data key' => [['saas.data_key' => ''], 'data_key'],
            'data key too short' => [['saas.data_key' => 'base64:'.base64_encode('short')], 'data_key'],
            'plaintext master password' => [['saas.master_password_plaintext_set' => true], 'master_password_plaintext'],
            'mail to the log' => [['mail.default' => 'log'], 'mail_mailer'],
            'mail to an array' => [['mail.default' => 'array'], 'mail_mailer'],
            'smtp with no host' => [['mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null], 'mail_mailer'],
            'notification mail off' => [['saas.mail_enabled' => false], 'mail_enabled'],
            'email messages to the log' => [['messaging.mail.driver' => 'log'], 'mail_messages'],
            'sms to the log' => [['messaging.sms.driver' => 'log'], 'sms'],
            'sms off' => [['messaging.sms.driver' => 'null'], 'sms'],
            'sms with no api key' => [['messaging.sms.africastalking.api_key' => ''], 'sms'],
            'whatsapp to the log' => [['messaging.whatsapp.driver' => 'log'], 'whatsapp'],
            'whatsapp with no token' => [['messaging.whatsapp.meta.token' => null], 'whatsapp'],
            'no flutterwave secret key' => [['flutterwave.secret_key' => ''], 'flutterwave_keys'],
            'flutterwave test secret key' => [['flutterwave.secret_key' => 'FLWSECK_TEST-abc-X'], 'flutterwave_test_mode'],
            'flutterwave test public key' => [['flutterwave.public_key' => 'FLWPUBK_TEST-abc-X'], 'flutterwave_test_mode'],
            'no webhook hash' => [['flutterwave.secret_hash' => ''], 'flutterwave_webhook'],
            'no support whatsapp' => [['support.whatsapp' => ''], 'support_whatsapp'],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_rule_fails_in_production_on_the_saas(array $over, string $key): void
    {
        $this->production($over);
        $checks = $this->checks();

        $this->assertSame(ConfigDoctor::FAIL, $checks[$key]['status'], $checks[$key]['message']);
        $this->assertNotSame('', $checks[$key]['fix']);
        $this->assertSame([$key], array_keys(array_filter($checks, fn ($c) => $c['status'] === ConfigDoctor::FAIL)), 'One wrong setting, one failure.');
        $this->assertSame(ConfigDoctor::FAIL, ConfigDoctor::overall($checks));
        // The fix offers CONFIG_DOCTOR_ACCEPT only where the owner may use it.
        $this->assertSame(! in_array($key, ConfigDoctor::NEVER_ACCEPTED, true), str_contains($checks[$key]['fix'], 'CONFIG_DOCTOR_ACCEPT'));
    }

    #[DataProvider('failures')]
    public function test_locally_every_failure_is_only_a_warning(array $over, string $key): void
    {
        foreach (['local', 'testing'] as $env) {
            $this->production(['app.env' => $env] + $over);
            $checks = $this->checks();

            $this->assertSame(ConfigDoctor::WARN, $checks[$key]['status'], "{$env}: {$key}");
            $this->assertNotSame(ConfigDoctor::FAIL, ConfigDoctor::overall($checks));
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function warnings(): array
    {
        return [
            'http address' => [['app.url' => 'http://bp.example.test'], 'app_url_https'],
            'no support email' => [['support.email' => ''], 'support_details'],
            'no support hours' => [['support.hours' => null], 'support_details'],
            'no portal address' => [['saas.new_ui_url' => ''], 'new_ui_url'],
            'no invite address' => [['saas.invite_url' => ''], 'invite_url'],
        ];
    }

    #[DataProvider('warnings')]
    public function test_each_warning_only_warns_in_production(array $over, string $key): void
    {
        $this->production($over);
        $checks = $this->checks();

        $this->assertSame(ConfigDoctor::WARN, $checks[$key]['status']);
        $this->assertSame(ConfigDoctor::WARN, ConfigDoctor::overall($checks), 'A warning never stops a deploy.');
    }

    public function test_http_is_fine_off_production(): void
    {
        $this->production(['app.env' => 'local', 'app.url' => 'http://localhost']);

        $this->assertSame(ConfigDoctor::OK, $this->checks()['app_url_https']['status']);
    }

    public function test_on_standalone_messaging_and_payments_only_warn(): void
    {
        $this->production([
            'edition.name' => 'standalone',
            'mail.default' => 'log', 'saas.mail_enabled' => false, 'messaging.mail.driver' => 'log',
            'messaging.sms.driver' => 'log', 'messaging.whatsapp.driver' => 'log',
            'flutterwave.secret_key' => 'FLWSECK_TEST-abc-X', 'flutterwave.secret_hash' => '',
            'support.whatsapp' => '', 'saas.master_password_plaintext_set' => true,
            'saas.new_ui_url' => '', 'saas.invite_url' => '',
        ]);
        $checks = $this->checks();

        foreach (['mail_mailer', 'mail_enabled', 'mail_messages', 'sms', 'whatsapp', 'flutterwave_test_mode', 'flutterwave_webhook', 'support_whatsapp', 'master_password_plaintext'] as $key) {
            $this->assertSame(ConfigDoctor::WARN, $checks[$key]['status'], $key);
        }
        $this->assertStringContainsString('Settings › Connections', $checks['sms']['fix'], 'A buyer fixes it on the Connections screen, not in .env.');
        $this->assertSame(ConfigDoctor::WARN, $checks['connections']['status']);
        $this->assertStringContainsString('No email, SMS or WhatsApp', $checks['connections']['message']);
        $this->assertArrayNotHasKey('new_ui_url', $checks, 'SaaS links do not apply to a standalone copy.');
        $this->assertArrayNotHasKey('invite_url', $checks);
        $this->assertSame(ConfigDoctor::WARN, ConfigDoctor::overall($checks), 'A buyer\'s update is never blocked by messaging or payments.');

        // What is not the buyer's own account still fails.
        config(['app.debug' => true]);
        $this->assertSame(ConfigDoctor::FAIL, $this->checks()['app_debug']['status']);
    }

    public function test_standalone_names_the_channels_that_are_set_up(): void
    {
        $this->production(['edition.name' => 'standalone', 'messaging.sms.driver' => 'log']);
        $checks = $this->checks();

        $this->assertSame(ConfigDoctor::OK, $checks['connections']['status']);
        $this->assertSame('Set up: email, WhatsApp.', $checks['connections']['message']);
    }

    public function test_the_web_app_also_checks_the_site_facts(): void
    {
        $this->production(['site.legal_name' => null, 'site.address' => '']);
        $portal = $this->checks(ConfigDoctor::PORTAL);

        $this->assertSame(ConfigDoctor::WARN, $portal['site_facts']['status']);
        $this->assertStringContainsString('SITE_COMPANY_LEGAL_NAME, SITE_ADDRESS', $portal['site_facts']['message']);
        $this->assertArrayNotHasKey('site_facts', $this->checks(ConfigDoctor::KERNEL), 'The kernel has no public site.');

        config(['site.legal_name' => 'Example Ltd', 'site.address' => 'Plot 1']);
        $this->assertSame(ConfigDoctor::OK, $this->checks(ConfigDoctor::PORTAL)['site_facts']['status']);
        config(['edition.name' => 'standalone']);
        $this->assertArrayNotHasKey('site_facts', $this->checks(ConfigDoctor::PORTAL), 'A standalone copy has no public site.');
    }

    public function test_the_owner_can_accept_a_failure_and_it_stays_visible(): void
    {
        $this->production([
            'messaging.sms.driver' => 'log', 'messaging.whatsapp.driver' => 'log', 'app.debug' => true,
            'saas.config_doctor_accept' => ['sms', 'whatsapp', 'app_debug', 'faxes'],
        ]);
        $checks = $this->checks();

        foreach (['sms', 'whatsapp'] as $key) {
            $this->assertSame(ConfigDoctor::WARN, $checks[$key]['status'], $key);
            $this->assertTrue($checks[$key]['accepted'], $key);
            $this->assertStringContainsString('never leave the server', $checks[$key]['message'], 'Still says what is wrong.');
        }
        $this->assertSame(ConfigDoctor::FAIL, $checks['app_debug']['status'], 'Debug mode can never be accepted.');
        $this->assertFalse($checks['app_debug']['accepted']);
        $this->assertSame(ConfigDoctor::WARN, $checks['accepted']['status']);
        $this->assertStringContainsString('accepts: sms, whatsapp', $checks['accepted']['message']);
        $this->assertStringContainsString('app_debug can never be accepted', $checks['accepted']['message']);
        $this->assertStringContainsString('faxes is not a check key', $checks['accepted']['message']);

        config(['app.debug' => false, 'saas.config_doctor_accept' => ['sms', 'whatsapp']]);
        $checks = $this->checks();
        $this->assertSame(ConfigDoctor::WARN, ConfigDoctor::overall($checks), 'With only accepted failures the deploy goes ahead.');
        $this->assertSame(ConfigDoctor::OK, $checks['accepted']['status']);
    }

    public function test_the_dangerous_failures_can_never_be_accepted(): void
    {
        $broken = [
            'edition' => ['edition.name' => 'cloud'],
            'app_debug' => ['app.debug' => true],
            'data_key' => ['saas.data_key' => ''],
            'queue_sync' => ['queue.default' => 'sync'],
            'flutterwave_test_mode' => ['flutterwave.secret_key' => 'FLWSECK_TEST-abc-X'],
            'master_password_plaintext' => ['saas.master_password_plaintext_set' => true],
        ];
        $this->assertEqualsCanonicalizing(ConfigDoctor::NEVER_ACCEPTED, array_keys($broken));

        foreach ($broken as $key => $over) {
            $this->production($over + ['saas.config_doctor_accept' => ConfigDoctor::NEVER_ACCEPTED]);
            $check = $this->checks()[$key];
            $this->assertSame(ConfigDoctor::FAIL, $check['status'], $key);
            $this->assertFalse($check['accepted'], $key);
        }
    }

    public function test_the_list_is_sorted_failures_first(): void
    {
        $this->production(['support.email' => '', 'queue.default' => 'sync']);
        $sorted = ConfigDoctor::sorted((new ConfigDoctor(ConfigDoctor::KERNEL))->checks());

        $this->assertSame(['queue_sync', 'support_details'], array_column(array_slice($sorted, 0, 2), 'key'));
        $this->assertSame(ConfigDoctor::OK, end($sorted)['status']);
    }

    public function test_the_command_prints_a_table_and_passes_without_failures(): void
    {
        $this->production(['support.email' => '']);

        $this->artisan('config:doctor')
            ->expectsOutputToContain('support_details')
            ->expectsOutputToContain('Overall: WARN')
            ->expectsOutputToContain('Never accepted: edition, app_debug')
            ->assertExitCode(0);
    }

    public function test_the_command_exits_1_on_a_failure(): void
    {
        $this->production(['queue.default' => 'sync']);

        $this->artisan('config:doctor')
            ->expectsOutputToContain('Set QUEUE_CONNECTION=database (ADR 0008).')
            ->expectsOutputToContain('Overall: FAIL')
            ->assertExitCode(1);
    }

    public function test_the_command_marks_accepted_failures(): void
    {
        $this->production(['messaging.whatsapp.driver' => 'log', 'saas.config_doctor_accept' => ['whatsapp']]);

        $this->artisan('config:doctor')->expectsOutputToContain('WARN (accepted by the owner)')->assertExitCode(0);
    }

    public function test_the_command_answers_in_json(): void
    {
        $this->production(['flutterwave.public_key' => 'FLWPUBK_TEST-abc-X']);

        $exit = Artisan::call('config:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $json['status']);
        $this->assertSame('kernel', $json['app']);
        $this->assertSame('production', $json['environment']);
        $this->assertSame(ConfigDoctor::NEVER_ACCEPTED, $json['never_accepted']);
        $this->assertSame(['key' => 'flutterwave_test_mode', 'status' => 'fail', 'message' => 'FLW_PUBLIC_KEY is a test key: payments are not real money.',
            'fix' => 'Set the live keys from the Flutterwave dashboard.', 'accepted' => false], $json['checks'][0]);
        $this->assertSame([], array_diff(array_column($json['checks'], 'key'), ConfigDoctor::KEYS), 'Every key is a documented, stable one.');
    }
}
