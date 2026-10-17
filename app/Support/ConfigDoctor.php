<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Settings that must never switch something off without anyone noticing (docs/roadmap/02 §1, §11).
 *
 * One list of checks for both apps, each run on its own process's config: the kernel's
 * `config:doctor` and classic System health, and budget-pro-new's `shop:check`. The web app loads
 * nine kernel config files into its own process, so its `.env` must carry those keys too, and this
 * class checks them there as well.
 *
 * Each check is ok, warn (look this week) or fail (a production deploy stops):
 * - production on the SaaS fails on what would quietly stop mail, messages, payments or the queue,
 *   on debug mode, on a missing shared data key or support WhatsApp, and on a plaintext master password;
 * - a standalone copy only warns about messaging and payments: the buyer may set them up later in
 *   Settings › Connections, and their updates must never be blocked by it;
 * - any environment other than production only warns;
 * - the owner may accept a failure for now with CONFIG_DOCTOR_ACCEPT (config saas.config_doctor_accept,
 *   comma-separated keys): it then shows as a warning marked "accepted by the owner", never hidden.
 *   The keys in NEVER_ACCEPTED always fail in production.
 *
 * Check keys (stable: CONFIG_DOCTOR_ACCEPT, monitors and tests use them):
 *   edition                    APP_EDITION is saas or standalone
 *   app_debug                  APP_DEBUG is off
 *   app_url_https              APP_URL is https in production (warns)
 *   queue_sync                 the queue is not sync or null (ADR 0008)
 *   data_key                   SAAS_DATA_KEY is set and valid (both apps read each other's encrypted values)
 *   master_password_plaintext  no plaintext MASTER_PASSWORD in .env
 *   mail_mailer                MAIL_MAILER really sends (not log or array; smtp has a host)
 *   mail_enabled               SAAS_MAIL_ENABLED is on (Utils::mail_sender)
 *   mail_messages              MESSAGING_MAIL_DRIVER is mail (codes and notices by email)
 *   sms                        SMS_DRIVER is africastalking, with its username and API key
 *   whatsapp                   WHATSAPP_DRIVER is meta, with its token and phone number id
 *   connections                standalone only: which channels Settings › Connections has set up (warns)
 *   flutterwave_keys           FLW_SECRET_KEY is set
 *   flutterwave_test_mode      no Flutterwave test key (FLWSECK_TEST, FLWPUBK_TEST)
 *   flutterwave_webhook        FLW_SECRET_HASH is set (webhooks are verified)
 *   support_whatsapp           the support WhatsApp is set (config/support.php)
 *   support_details            the support email and hours are set (warns)
 *   new_ui_url                 SaaS: SAAS_NEW_UI_URL is set (warns)
 *   invite_url                 SaaS: SAAS_INVITE_URL is set (warns)
 *   site_facts                 web app on the SaaS: the public site's company facts are set (warns)
 *   accepted                   only when CONFIG_DOCTOR_ACCEPT is set: what it accepts, and entries it ignores
 *
 * Pure and fast: it reads config only (on standalone that includes what Settings › Connections laid
 * over it at boot, see InstallSettings). No network calls, no database.
 */
final class ConfigDoctor
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Which app's config is being checked. */
    public const KERNEL = 'kernel';

    public const PORTAL = 'portal';

    /** Every check key, in the order the checks run. */
    public const KEYS = [
        'edition', 'app_debug', 'app_url_https', 'queue_sync', 'data_key', 'master_password_plaintext',
        'mail_mailer', 'mail_enabled', 'mail_messages', 'sms', 'whatsapp', 'connections',
        'flutterwave_keys', 'flutterwave_test_mode', 'flutterwave_webhook',
        'support_whatsapp', 'support_details', 'new_ui_url', 'invite_url', 'site_facts', 'accepted',
    ];

    /** Failures CONFIG_DOCTOR_ACCEPT can never turn into warnings: each is a security hole or loses data. */
    public const NEVER_ACCEPTED = ['edition', 'app_debug', 'data_key', 'queue_sync', 'flutterwave_test_mode', 'master_password_plaintext'];

    /** On standalone these groups only warn (the buyer's own accounts, set up when they are ready). */
    private const BUYER_GROUPS = ['messaging', 'payments'];

    private string $app;

    private bool $production = false;

    private bool $standalone = false;

    /** @var list<string> */
    private array $accept = [];

    /** @var list<array{key:string, status:string, message:string, fix:string, accepted:bool}> */
    private array $checks = [];

    /** @param  string|null  $app  self::KERNEL or self::PORTAL; null = this process (the portal has config budgetpro.path). */
    public function __construct(?string $app = null)
    {
        $this->app = $app ?? (config('budgetpro.path') !== null ? self::PORTAL : self::KERNEL);
    }

    /** @return list<array{key:string, status:string, message:string, fix:string, accepted:bool}> */
    public function checks(): array
    {
        $this->checks = [];
        $this->production = config('app.env') === 'production';
        $this->accept = array_values(array_map('strval', (array) config('saas.config_doctor_accept', [])));
        $this->standalone = $this->edition() === Edition::STANDALONE;

        foreach ([
            'appDebug', 'appUrl', 'queue', 'dataKey', 'masterPassword',
            'mailer', 'mailEnabled', 'mailMessages', 'sms', 'whatsapp', 'connections',
            'flutterwaveKeys', 'flutterwaveTestMode', 'flutterwaveWebhook',
            'supportWhatsapp', 'supportDetails', 'saasLinks', 'siteFacts', 'accepted',
        ] as $rule) {
            try {
                $this->{$rule}();
            } catch (\Throwable $e) {
                $this->add(Str::snake($rule), 'app', self::FAIL, 'The check itself failed: '.$e->getMessage(), 'Read the message, fix the setting, run it again.');
            }
        }

        return $this->checks;
    }

    /** fail when any check fails, else warn when any warns, else ok. @param list<array{status:string}> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    /**
     * Failures first, then warnings, then the rest, each group in its original order.
     *
     * @param  list<array{key:string, status:string, message:string, fix:string, accepted:bool}>  $checks
     * @return list<array{key:string, status:string, message:string, fix:string, accepted:bool}>
     */
    public static function sorted(array $checks): array
    {
        $rank = [self::FAIL => 0, self::WARN => 1, self::OK => 2];
        $keyed = [];
        foreach (array_values($checks) as $i => $check) {
            $keyed[] = [$rank[$check['status']] ?? 3, $i, $check];
        }
        sort($keyed);

        return array_map(fn ($row) => $row[2], $keyed);
    }

    /** The keys CONFIG_DOCTOR_ACCEPT may name. @return list<string> */
    public static function acceptable(): array
    {
        return array_values(array_diff(self::KEYS, self::NEVER_ACCEPTED, ['accepted']));
    }

    // ── App ──────────────────────────────────────────────────────────────

    private function edition(): string
    {
        $name = (string) config('edition.name', Edition::SAAS);
        if (in_array($name, [Edition::SAAS, Edition::STANDALONE], true)) {
            $this->add('edition', 'app', self::OK, $name === Edition::SAAS ? 'SaaS: many shops sign up and pay.' : 'Standalone: one business on its own server.');

            return $name;
        }
        $this->add('edition', 'app', self::FAIL, "APP_EDITION is \"{$name}\", which is not an edition.", 'Set APP_EDITION to saas or standalone.');

        return Edition::SAAS;
    }

    private function appDebug(): void
    {
        config('app.debug')
            ? $this->add('app_debug', 'app', self::FAIL, 'Debug mode is on: error pages show code and settings to anyone.', 'Set APP_DEBUG=false.')
            : $this->add('app_debug', 'app', self::OK, 'Debug mode is off.');
    }

    private function appUrl(): void
    {
        $url = (string) config('app.url');
        if (! $this->production) {
            $this->add('app_url_https', 'app', self::OK, "Not production, so http is fine ({$url}).");
        } elseif (str_starts_with(strtolower($url), 'https://')) {
            $this->add('app_url_https', 'app', self::OK, "APP_URL uses https ({$url}).");
        } else {
            $this->add('app_url_https', 'app', self::WARN, "APP_URL is \"{$url}\", not https: links in messages and receipts open without encryption.", 'Set APP_URL to the https address.');
        }
    }

    private function queue(): void
    {
        $queue = (string) config('queue.default');
        if ($queue === 'sync') {
            $this->add('queue_sync', 'app', self::FAIL, 'The queue is sync: jobs run inside web requests, and the every-minute worker never drains the jobs table, so work the other app queues is never done.', 'Set QUEUE_CONNECTION=database (ADR 0008).');
        } elseif (in_array($queue, ['', 'null'], true)) {
            $this->add('queue_sync', 'app', self::FAIL, 'The queue is off: queued messages and jobs are thrown away.', 'Set QUEUE_CONNECTION=database (ADR 0008).');
        } else {
            $this->add('queue_sync', 'app', self::OK, $queue === 'database' ? 'Database queue, drained every minute by the scheduler.' : "Queue: {$queue}.");
        }
    }

    private function dataKey(): void
    {
        $key = trim((string) config('saas.data_key'));
        $fix = 'Set SAAS_DATA_KEY to one value, the same in both apps\' .env (php artisan key:generate --show makes one).';
        if ($key === '') {
            $this->add('data_key', 'app', self::FAIL, 'SAAS_DATA_KEY is empty: each app falls back to its own APP_KEY, so values one app encrypts (EFRIS keys, Connections, web links) cannot be read by the other.', $fix);

            return;
        }
        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        Encrypter::supported($raw, $cipher)
            ? $this->add('data_key', 'app', self::OK, 'SAAS_DATA_KEY is set. It must be the same in both apps.')
            : $this->add('data_key', 'app', self::FAIL, "SAAS_DATA_KEY is not a valid {$cipher} key.", $fix);
    }

    private function masterPassword(): void
    {
        if (! config('saas.master_password_plaintext_set')) {
            $this->add('master_password_plaintext', 'app', self::OK, 'No plaintext master password in .env.');
        } elseif ($this->standalone) {
            $this->add('master_password_plaintext', 'app', self::WARN, 'MASTER_PASSWORD is set, but a standalone copy never uses it.', 'Remove MASTER_PASSWORD from .env.');
        } else {
            $this->add('master_password_plaintext', 'app', self::FAIL, 'A plaintext MASTER_PASSWORD is in .env.', 'Remove MASTER_PASSWORD. Set MASTER_PASSWORD_HASH with php artisan master-password:set instead.');
        }
    }

    // ── Mail and messages ────────────────────────────────────────────────

    private function mailer(): void
    {
        $mailer = (string) config('mail.default');
        $fix = $this->fix('Set MAIL_MAILER=smtp with the MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD of the mail server.', 'Set up email in Settings › Connections.');
        if (in_array($mailer, ['', 'log', 'array'], true)) {
            $this->add('mail_mailer', 'messaging', self::FAIL, 'MAIL_MAILER is '.($mailer === '' ? 'empty' : $mailer).': emails are written to the log, never sent.', $fix);
        } elseif ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host')) && blank(config('mail.mailers.smtp.url'))) {
            $this->add('mail_mailer', 'messaging', self::FAIL, 'MAIL_MAILER is smtp, but MAIL_HOST is empty.', $fix);
        } else {
            $this->add('mail_mailer', 'messaging', self::OK, "Email goes out through {$mailer}.");
        }
    }

    private function mailEnabled(): void
    {
        config('saas.mail_enabled')
            ? $this->add('mail_enabled', 'messaging', self::OK, 'Notification emails are on.')
            : $this->add('mail_enabled', 'messaging', self::FAIL, 'SAAS_MAIL_ENABLED is off: notification emails are dropped with only a log line.', 'Set SAAS_MAIL_ENABLED=true once the mail server works.');
    }

    private function mailMessages(): void
    {
        $driver = (string) config('messaging.mail.driver');
        $driver === 'mail'
            ? $this->add('mail_messages', 'messaging', self::OK, 'Email messages (codes, notices) go through the mailer.')
            : $this->add('mail_messages', 'messaging', self::FAIL, $this->offMessage('MESSAGING_MAIL_DRIVER', $driver, 'codes and notices by email'),
                $this->fix('Set MESSAGING_MAIL_DRIVER=mail.', 'Set up email in Settings › Connections.'));
    }

    private function sms(): void
    {
        $driver = (string) config('messaging.sms.driver');
        $fix = $this->fix('Set SMS_DRIVER=africastalking with AFRICASTALKING_USERNAME, AFRICASTALKING_API_KEY and AFRICASTALKING_SENDER_ID.', 'Add your Africa\'s Talking account in Settings › Connections.');
        if ($driver !== 'africastalking') {
            $this->add('sms', 'messaging', self::FAIL, $this->offMessage('SMS_DRIVER', $driver, 'SMS codes and messages'), $fix);
        } elseif (blank(config('messaging.sms.africastalking.username')) || blank(config('messaging.sms.africastalking.api_key'))) {
            $this->add('sms', 'messaging', self::FAIL, 'SMS uses Africa\'s Talking, but AFRICASTALKING_USERNAME or AFRICASTALKING_API_KEY is empty.', $fix);
        } else {
            $this->add('sms', 'messaging', self::OK, 'SMS goes out through Africa\'s Talking.');
        }
    }

    private function whatsapp(): void
    {
        $driver = (string) config('messaging.whatsapp.driver');
        $fix = $this->fix('Set WHATSAPP_DRIVER=meta with WHATSAPP_TOKEN and WHATSAPP_PHONE_NUMBER_ID from Meta.', 'Add your WhatsApp Business account in Settings › Connections.');
        if ($driver !== 'meta') {
            $this->add('whatsapp', 'messaging', self::FAIL, $this->offMessage('WHATSAPP_DRIVER', $driver, 'WhatsApp codes, receipts and messages'), $fix);
        } elseif (blank(config('messaging.whatsapp.meta.token')) || blank(config('messaging.whatsapp.meta.phone_number_id'))) {
            $this->add('whatsapp', 'messaging', self::FAIL, 'WhatsApp uses Meta, but WHATSAPP_TOKEN or WHATSAPP_PHONE_NUMBER_ID is empty.', $fix);
        } else {
            $this->add('whatsapp', 'messaging', self::OK, 'WhatsApp goes out through Meta.');
        }
    }

    /** Standalone only: which channels can reach someone right now (password resets and receipts depend on one). */
    private function connections(): void
    {
        if (! $this->standalone) {
            return;
        }
        $names = ['mail' => 'email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
        $ready = array_map(fn ($c) => $names[$c] ?? $c, InstallSettings::readyChannels());
        $ready !== []
            ? $this->add('connections', 'messaging', self::OK, 'Set up: '.implode(', ', $ready).'.')
            : $this->add('connections', 'messaging', self::WARN, 'No email, SMS or WhatsApp is set up: password resets and receipts cannot reach anyone.', 'Set one up in Settings › Connections.');
    }

    // ── Payments ─────────────────────────────────────────────────────────

    private function flutterwaveKeys(): void
    {
        blank(config('flutterwave.secret_key'))
            ? $this->add('flutterwave_keys', 'payments', self::FAIL, 'FLW_SECRET_KEY is empty: nobody can pay.',
                $this->fix('Set FLW_SECRET_KEY and FLW_PUBLIC_KEY from the Flutterwave dashboard.', 'Add your Flutterwave keys in Settings › Connections.'))
            : $this->add('flutterwave_keys', 'payments', self::OK, 'Flutterwave keys are set.');
    }

    private function flutterwaveTestMode(): void
    {
        $test = array_keys(array_filter([
            'FLW_SECRET_KEY' => str_starts_with(strtoupper(trim((string) config('flutterwave.secret_key'))), 'FLWSECK_TEST'),
            'FLW_PUBLIC_KEY' => str_starts_with(strtoupper(trim((string) config('flutterwave.public_key'))), 'FLWPUBK_TEST'),
        ]));
        $test === []
            ? $this->add('flutterwave_test_mode', 'payments', self::OK, 'No Flutterwave test key.')
            : $this->add('flutterwave_test_mode', 'payments', self::FAIL, implode(' and ', $test).' '.(count($test) > 1 ? 'are test keys' : 'is a test key').': payments are not real money.',
                $this->fix('Set the live keys from the Flutterwave dashboard.', 'Add your live Flutterwave keys in Settings › Connections.'));
    }

    private function flutterwaveWebhook(): void
    {
        blank(config('flutterwave.secret_hash'))
            ? $this->add('flutterwave_webhook', 'payments', self::FAIL, 'FLW_SECRET_HASH is empty: every Flutterwave webhook is refused, so payments are confirmed late or not at all.',
                $this->fix('Set FLW_SECRET_HASH to the secret hash in the Flutterwave dashboard (Settings, Webhooks).', 'Add the webhook secret hash in Settings › Connections.'))
            : $this->add('flutterwave_webhook', 'payments', self::OK, 'Flutterwave webhooks are verified.');
    }

    // ── Support and site facts ───────────────────────────────────────────

    private function supportWhatsapp(): void
    {
        $digits = (string) preg_replace('/\D+/', '', (string) config('support.whatsapp', ''));
        $digits !== ''
            ? $this->add('support_whatsapp', 'support', self::OK, "Support WhatsApp is +{$digits}.")
            : $this->add('support_whatsapp', 'support', $this->standalone ? self::WARN : self::FAIL, 'The support WhatsApp is empty: help links have nowhere to go.', 'Set SUPPORT_WHATSAPP (digits with the country code).');
    }

    private function supportDetails(): void
    {
        $missing = array_keys(array_filter(['SUPPORT_EMAIL' => 'support.email', 'SUPPORT_HOURS' => 'support.hours'], fn ($key) => blank(config($key))));
        $missing === []
            ? $this->add('support_details', 'support', self::OK, 'Support email and hours are set.')
            : $this->add('support_details', 'support', self::WARN, 'Not set: '.implode(', ', $missing).'.', 'Set them so help pages and emails can show them.');
    }

    /** SaaS only: where the classic screens, web sign-in links and team invites send people. */
    private function saasLinks(): void
    {
        if ($this->standalone) {
            return;
        }
        blank(config('saas.new_ui_url'))
            ? $this->add('new_ui_url', 'links', self::WARN, 'SAAS_NEW_UI_URL is empty: the classic screens cannot link to the web portal, and sign-in links from the apps have no address.', 'Set SAAS_NEW_UI_URL to the web portal\'s address.')
            : $this->add('new_ui_url', 'links', self::OK, 'The web portal is at '.config('saas.new_ui_url').'.');
        blank(config('saas.invite_url'))
            ? $this->add('invite_url', 'links', self::WARN, 'SAAS_INVITE_URL is empty: team invites open the classic invite page.', 'Set SAAS_INVITE_URL to the web portal\'s address so invites open there.')
            : $this->add('invite_url', 'links', self::OK, 'Team invites open at '.config('saas.invite_url').'.');
    }

    /** The web app on the SaaS: the company facts the public site prints (config/site.php). */
    private function siteFacts(): void
    {
        if ($this->app !== self::PORTAL || $this->standalone) {
            return;
        }
        $missing = array_keys(array_filter([
            'SITE_COMPANY_LEGAL_NAME' => 'site.legal_name', 'SITE_ADDRESS' => 'site.address',
            'SITE_SUPPORT_EMAIL' => 'site.support_email', 'SITE_SUPPORT_HOURS' => 'site.support_hours',
        ], fn ($key) => blank(config($key))));
        $missing === []
            ? $this->add('site_facts', 'site', self::OK, 'The public site\'s company facts are set.')
            : $this->add('site_facts', 'site', self::WARN, 'Not set: '.implode(', ', $missing).'. The public site leaves those lines out.', 'Set them in the web app\'s .env.');
    }

    /** Only when CONFIG_DOCTOR_ACCEPT is set: what the owner accepts, and the entries that cannot count. */
    private function accepted(): void
    {
        if ($this->accept === []) {
            return;
        }
        $never = array_values(array_intersect($this->accept, self::NEVER_ACCEPTED));
        $unknown = array_values(array_diff($this->accept, self::KEYS));
        $ignored = array_merge($never, $unknown);
        $valid = array_values(array_diff($this->accept, $ignored));
        $said = $valid === [] ? 'nothing it can accept' : implode(', ', $valid);
        if ($ignored === []) {
            $this->add('accepted', 'app', self::OK, "CONFIG_DOCTOR_ACCEPT accepts: {$said}.");

            return;
        }
        $why = array_filter([
            $never !== [] ? implode(', ', $never).' can never be accepted' : null,
            $unknown !== [] ? implode(', ', $unknown).' '.(count($unknown) > 1 ? 'are not check keys' : 'is not a check key') : null,
        ]);
        $this->add('accepted', 'app', self::WARN, "CONFIG_DOCTOR_ACCEPT accepts: {$said}. Ignored: ".implode('; ', $why).'.',
            'Remove the ignored entries. Keys that can be accepted: '.implode(', ', self::acceptable()).'.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Why a channel's driver means nothing is sent: `log` pretends, anything else is off. */
    private function offMessage(string $env, string $driver, string $what): string
    {
        return $driver === 'log'
            ? "{$env} is log: {$what} are marked sent but never leave the server."
            : "{$env} is ".($driver === '' ? 'empty' : $driver).": {$what} are off.";
    }

    private function fix(string $saas, string $standalone): string
    {
        return $this->standalone ? $standalone : $saas;
    }

    private function add(string $key, string $group, string $status, string $message, string $fix = ''): void
    {
        $accepted = false;
        if ($status === self::FAIL) {
            $acceptable = in_array($key, self::acceptable(), true);
            if ($acceptable && in_array($key, $this->accept, true)) {
                [$status, $accepted] = [self::WARN, true];
            } elseif (! $this->production || ($this->standalone && in_array($group, self::BUYER_GROUPS, true))) {
                $status = self::WARN;
            } elseif ($acceptable) {
                $fix .= " Or, if the owner decides to go without it for now, add {$key} to CONFIG_DOCTOR_ACCEPT.";
            }
        }
        $this->checks[] = ['key' => $key, 'status' => $status, 'message' => $message, 'fix' => $status === self::OK ? '' : trim($fix), 'accepted' => $accepted];
    }
}
