<?php

namespace Tests\Feature;

use App\Mail\ShopMessage;
use App\Models\Company;
use App\Services\Messaging\MailChannel;
use App\Services\Support\SupportClicks;
use App\Support\ErrorTracker;
use App\Support\SupportContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RunsAsStandalone;
use Tests\Feature\Api\ApiTestCase;

/**
 * The support number on every surface (docs/roadmap/06 §4): one reader, prefilled messages that never
 * carry a customer, the standalone order, the email footer, the receipt line and the API blocks.
 * The real number is never typed here (scripts/ci/support-number-scan.sh): tests use a made-up one.
 */
class SupportContactTest extends ApiTestCase
{
    use RunsAsStandalone;

    private const FAKE = '256700000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.whatsapp' => self::FAKE, 'support.phone' => '+256 700 000 001', 'support.email' => '', 'support.hours' => 'Monday to Saturday, 8:00 to 20:00', 'support.status_url' => '']);
    }

    public function test_the_default_contact_is_set_in_config_only(): void
    {
        $defaults = require config_path('support.php');
        $this->assertMatchesRegularExpression('/^256\d{9}$/', (string) env('SUPPORT_WHATSAPP', '') ?: (string) $defaults['whatsapp'], 'a Ugandan WhatsApp number, digits only');
        $this->assertNotSame('', trim((string) $defaults['phone']));
        $this->assertNotSame('', trim((string) $defaults['hours']));
        $this->assertStringNotContainsString('gmail', (string) $defaults['email'], 'a personal address never becomes the support address');
        $this->assertSame(['saas' => true, 'standalone' => false], $defaults['receipt_line_default']);
    }

    public function test_platform_contact_and_its_formats(): void
    {
        $us = SupportContact::platform();
        $this->assertSame([self::FAKE, '+256 700 000 001', true], [$us->whatsappDigits(), $us->whatsappDisplay(), $us->hasWhatsapp()]);
        $this->assertSame(['tel:+256700000001', true, '', ''], [$us->phoneLink(), $us->phoneIsWhatsapp(), $us->email(), $us->emailLink()]);
        $this->assertSame([SupportContact::WHO_PLATFORM, 'Budget Pro', true], [$us->who(), $us->name(), $us->reachable()]);

        config(['support.whatsapp' => '+44 7946 0000 01', 'support.phone' => '0772 000 001', 'support.email' => 'help@example.test']);
        $other = SupportContact::platform();
        $this->assertSame(['447946000001', '+447946000001', 'tel:0772000001', false], [$other->whatsappDigits(), $other->whatsappDisplay(), $other->phoneLink(), $other->phoneIsWhatsapp()]);
        $this->assertSame('mailto:help@example.test', $other->emailLink());

        config(['support.whatsapp' => '', 'support.phone' => '', 'support.email' => '']);
        $none = SupportContact::platform();
        $this->assertSame(['', '', '', false], [$none->whatsappDisplay(), $none->waLink('site.general'), $none->goLink('site.general'), $none->reachable()]);
        $this->assertSame('', $none->mailLine());
    }

    public function test_messages_fill_cleanly_and_never_leave_a_placeholder(): void
    {
        $us = SupportContact::platform();
        $this->assertSame('Hello Budget Pro, I need help with Products. I am the owner at Mama Shop.',
            $us->message('admin.help', ['screen' => 'Products', 'role' => 'owner', 'shop' => 'Mama Shop']));
        $this->assertSame('Hello Budget Pro, I need help.', $us->message('admin.help'), 'optional parts drop out whole');
        $this->assertSame('Hello Budget Pro, I need help with Sell.', $us->message('admin.help', ['screen' => 'Sell', 'shop' => 'Mama Shop']), 'a part needs all its values');
        $this->assertSame('Hello Budget Pro, I need help with the Kuku Tally app (version 3.2.0).', $us->message('app.help', ['app' => 'Kuku Tally', 'version' => '3.2.0']));
        $this->assertSame($us->message('site.general'), $us->message('no.such.context'), 'an unknown context gets the general message');
        $this->assertSame('Hello Budget Pro, something went wrong at Mama Shop. Reference EAB12CD34.', $us->message('admin.error', ['shop' => 'Mama Shop', 'ref' => 'EAB12CD34']));

        // A shop named after its phone number does not put the number in a message.
        $this->assertSame('Hello Budget Pro, I would like to renew the subscription for Mama Shop.', $us->message('admin.renew', ['shop' => 'Mama Shop 0772 123 456']));

        foreach (SupportContact::templates() as $context => $template) {
            $this->assertStringNotContainsString(':', preg_replace('/^Hello Budget Pro,|report:/', '', $us->message($context)) ?? '', "{$context} leaves a placeholder behind");
        }
    }

    public function test_no_template_asks_for_a_customer_an_amount_or_a_phone(): void
    {
        $allowed = ['page', 'app', 'shop', 'role', 'screen', 'ref', 'limit', 'version', 'report', 'licence'];
        $templates = SupportContact::templates();
        foreach (['site.general', 'site.page', 'site.pricing', 'site.demo', 'site.own_copy', 'auth.signin', 'trial.welcome', 'admin.help', 'admin.error',
            'admin.limit', 'admin.renew', 'app.help', 'app.diagnostics', 'standalone.install', 'standalone.update'] as $required) {
            $this->assertArrayHasKey($required, $templates);
        }
        foreach ($templates as $context => $template) {
            preg_match_all('/:([a-z_]+)/', $template, $m);
            $this->assertSame([], array_diff($m[1], $allowed), "{$context} asks for something it must not");
            $this->assertDoesNotMatchRegularExpression('/\d{6,}/', $template, "{$context} carries a number");
        }
        $this->assertSame($templates, \Illuminate\Support\Facades\Lang::getLoader()->load('en', 'support', 'bp'), 'the bp:: namespace reads the same file');
        $this->assertSame($templates['site.demo'], __('bp::support.site.demo'));
    }

    public function test_links_plain_and_tracked(): void
    {
        $us = SupportContact::platform();
        $wa = $us->waLink('admin.renew', ['shop' => 'Mama Shop']);
        $this->assertStringStartsWith('https://wa.me/'.self::FAKE.'?text=', $wa);
        $this->assertSame('Hello Budget Pro, I would like to renew the subscription for Mama Shop.', rawurldecode(substr($wa, strpos($wa, '=') + 1)));

        // No web app to answer /go/whatsapp: the plain link.
        config(['saas.new_ui_url' => '']);
        $this->assertSame($us->waLink('auth.signin'), $us->goLink('auth.signin', [], '/auth/login', 'classic'));

        // The web app answers it: a tracked link with the context, the page path and where it was, never the values.
        config(['saas.new_ui_url' => 'https://shop.example.test/']);
        $go = $us->goLink('auth.signin', [], 'https://evil.example/auth/login?u=someone', 'classic');
        $this->assertSame('https://shop.example.test/go/whatsapp?c=auth.signin&p=%2Fauth%2Flogin&a=classic', $go);
        $this->assertStringContainsString('&r=EAB12CD34', $us->goLink('admin.error', ['ref' => 'EAB12CD34'], null, 'admin'));
        $this->assertStringNotContainsString('r=', $us->goLink('admin.error', ['ref' => '0772123456'], null, 'admin'), 'a reference is E or R and 8 hex characters, never a phone');
        $this->assertSame($us->waLink('admin.renew', ['shop' => 'Mama Shop']), $us->goLink('admin.renew', ['shop' => 'Mama Shop']), 'values the web app cannot see stay in a plain link');
        $this->assertStringNotContainsString('a=', $us->goLink('site.general', [], null, 'nowhere'));
    }

    public function test_standalone_shows_the_copys_own_contact_first(): void
    {
        $t = $this->registerTenant();
        $this->assertTrue(SupportContact::forCompany(Company::withoutGlobalScopes()->find($t['company_id']))->isPlatform(), 'the SaaS is always ours');

        DB::table('companies')->where('id', $t['company_id'])->update(['phone_number' => '0772 111 222', 'email' => 'office@shop.test', 'name' => 'Kato Hardware']);
        $this->asStandalone();
        $own = SupportContact::forCompany(Company::withoutGlobalScopes()->find($t['company_id']));
        $this->assertSame([SupportContact::WHO_OWN, 'Kato Hardware', '0772 111 222', 'office@shop.test'], [$own->who(), $own->name(), $own->phone(), $own->email()]);
        $this->assertSame(['', '', ''], [$own->whatsappDigits(), $own->hours(), $own->statusUrl()], 'our WhatsApp and hours do not stay behind under their name');
        $this->assertSame('Need a hand? Contact Kato Hardware on 0772 111 222 or at office@shop.test.', $own->mailLine());
        $this->assertSame('own', $own->toArray()['who']);

        DB::table('companies')->where('id', $t['company_id'])->update(['phone_number' => null, 'email' => null]);
        $this->assertTrue(SupportContact::forCompany(Company::withoutGlobalScopes()->find($t['company_id']))->isPlatform(), 'nothing of their own: ours');
    }

    public function test_it_answers_with_the_database_down(): void
    {
        $this->asStandalone();
        $default = config('database.default');
        config(['database.connections.bp_down' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none', 'username' => 'none', 'password' => 'none']]);
        DB::setDefaultConnection('bp_down');
        try {
            $contact = SupportContact::current();
            $this->assertTrue($contact->isPlatform());
            $this->assertSame(self::FAKE, $contact->toArray()['whatsapp']);
            $this->assertMatchesRegularExpression(SupportContact::REFERENCE_PATTERN, SupportContact::reference());
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('bp_down');
        }
    }

    public function test_references(): void
    {
        $e = new \RuntimeException('boom');
        $ref = SupportContact::reference($e);
        $this->assertSame('E'.strtoupper(substr(ErrorTracker::fingerprint($e), 0, 8)), $ref);
        $this->assertMatchesRegularExpression(SupportContact::REFERENCE_PATTERN, $ref);
        $this->assertSame($ref, SupportContact::reference(new HttpException(500, 'boom', $e)), 'a 500 page names the error behind it');

        $r = SupportContact::reference(new HttpException(419));
        $this->assertMatchesRegularExpression('/^R[0-9A-F]{8}$/', $r);
        $this->assertSame($r, SupportContact::reference(), 'one reference per request');
    }

    public function test_the_email_footer(): void
    {
        config(['mail.default' => 'array', 'saas.new_ui_url' => 'https://shop.example.test']);
        Mail::purge('array');
        $this->assertTrue((new MailChannel)->send('owner@example.test', 'Your code is 1234.', ['subject' => 'Code'])['ok']);
        $this->assertTrue((new MailChannel)->send('inbox@example.test', 'Website enquiry', ['subject' => 'Enquiry', 'support_footer' => false])['ok']);
        $this->assertTrue((new MailChannel)->send('owner@example.test', 'Welcome', ['support_context' => 'trial.welcome', 'support_vars' => ['shop' => 'Mama Shop']])['ok']);
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(3, $sent);
        $first = $sent[0]->getOriginalMessage()->getTextBody();
        $this->assertStringStartsWith('Your code is 1234.', $first);
        $this->assertStringContainsString('Need a hand? WhatsApp or call us on +256 700 000 001. Monday to Saturday, 8:00 to 20:00.', $first);
        $this->assertStringContainsString('https://shop.example.test/go/whatsapp?c=mail.general&a=mail', $first);
        $this->assertStringNotContainsString('Need a hand?', $sent[1]->getOriginalMessage()->getTextBody(), 'mail to our own inbox has no footer');
        $this->assertStringContainsString(rawurlencode('I have just opened an account for Mama Shop.'), $sent[2]->getOriginalMessage()->getTextBody());

        // A standalone copy: the business's own footer says its contact once; with none of its own, ours.
        $t = $this->registerTenant();
        $this->asStandalone();
        $company = Company::withoutGlobalScopes()->find($t['company_id']);
        $company->forceFill(['phone_number' => null, 'email' => null])->save();
        $html = (new ShopMessage('Hi', 'Hi', \App\Support\ShopBrand::forCompany($company), SupportContact::forCompany($company)->mailParts()))->render();
        $this->assertStringContainsString('WhatsApp or call us on +256 700 000 001', $html);
        $this->assertStringContainsString('Open WhatsApp', $html);

    }

    public function test_the_receipt_line_by_edition_and_choice(): void
    {
        $t = $this->registerTenant();
        $company = fn () => Company::withoutGlobalScopes()->find($t['company_id']);
        $this->assertNull($company()->receipt_support_line);
        $this->assertSame('Powered by Budget Pro · WhatsApp +256 700 000 001', SupportContact::receiptLine($company()), 'on by default on the SaaS');

        $h = $this->auth($t['token']);
        $this->putJson('/api/v1/company', ['receipt_support_line' => false], $h)->assertOk();
        $this->assertNull(SupportContact::receiptLine($company()), 'the owner switched it off');
        $this->assertNull($this->getJson('/api/v1/meta', $h)->assertOk()->json('data.business.receipt_support_line'));
        $this->putJson('/api/v1/company', ['receipt_support_line' => 'maybe'], $h)->assertStatus(422);
        $this->putJson('/api/v1/company', ['receipt_support_line' => null], $h)->assertOk();
        $this->assertNotNull(SupportContact::receiptLine($company()));

        // The receipts' shared partial prints it; a standalone copy leaves it off unless the owner turns it on.
        $this->assertStringContainsString('Powered by Budget Pro · WhatsApp +256 700 000 001', view('reports.partials.support-line', ['company' => $company()])->render());

        $this->asStandalone();
        $this->assertNull(SupportContact::receiptLine($company()), 'off by default on a standalone copy');
        DB::table('companies')->where('id', $t['company_id'])->update(['receipt_support_line' => true]);
        $this->assertSame('Powered by Budget Pro · WhatsApp +256 700 000 001', SupportContact::receiptLine($company()), 'the line is about who made the system: our number');
        $this->assertSame('', trim(view('reports.partials.support-line', ['company' => null])->render()));
    }

    public function test_clicks_are_counted_without_personal_data(): void
    {
        $clicks = new SupportClicks();
        $this->assertTrue($clicks->record('admin.help', 'admin', 'https://shop.example.test/products?q=Kato+0772123456', 12));
        $this->assertTrue($clicks->record('made.up', 'somewhere', null, null));
        $rows = DB::table('support_clicks')->orderByDesc('id')->limit(2)->get()->reverse()->values();
        $this->assertSame(['admin.help', 'admin', '/products', 12], [$rows[0]->context, $rows[0]->app, $rows[0]->page, (int) $rows[0]->company_id]);
        $this->assertSame(['site.general', 'site', null, null], [$rows[1]->context, $rows[1]->app, $rows[1]->page, $rows[1]->company_id]);
        $this->assertSame(['id', 'context', 'app', 'page', 'company_id', 'created_at'], \Illuminate\Support\Facades\Schema::getColumnListing('support_clicks'));
        $this->assertNotEmpty($clicks->summary(now()->subMinute()));
    }

    public function test_meta_and_app_version_carry_the_support_block(): void
    {
        $public = $this->getJson('/api/v1/app/version')->assertOk()->json('data');
        $this->assertSame(self::FAKE, $public['support']['whatsapp']);
        $this->assertSame(['+256 700 000 001', 'platform', 'Budget Pro'], [$public['support']['whatsapp_display'], $public['support']['who'], $public['support']['name']]);
        $this->assertArrayHasKey('app.help', $public['support']['templates']);
        $this->assertArrayHasKey('registration_open', $public, 'the edition keys stay where they were');

        $t = $this->registerTenant();
        $meta = $this->getJson('/api/v1/meta', $this->auth($t['token']))->assertOk()->json('data');
        $this->assertSame($public['support'], $meta['support']);
        $this->assertArrayNotHasKey('support', $meta['edition'], 'said once in meta');
        $this->assertSame('Powered by Budget Pro · WhatsApp +256 700 000 001', $meta['business']['receipt_support_line']);

        // A standalone copy: the business's own contact.
        DB::table('companies')->where('id', $t['company_id'])->update(['phone_number' => '0772 111 222']);
        $this->asStandalone();
        $own = $this->getJson('/api/v1/meta', $this->auth($t['token']))->assertOk()->json('data.support');
        $this->assertSame(['own', null, '0772 111 222'], [$own['who'], $own['whatsapp'], $own['phone']]);
    }
}
