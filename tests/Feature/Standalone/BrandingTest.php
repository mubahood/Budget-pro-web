<?php

namespace Tests\Feature\Standalone;

use App\Console\Commands\StandaloneHeartbeat;
use App\Mail\ShopMessage;
use App\Models\Company;
use App\Services\Messaging\MailChannel;
use App\Support\BrandColour;
use App\Support\Rules\CompanyRules;
use App\Support\SchedulerHeartbeat;
use App\Support\ShopBrand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\RunsAsStandalone;
use Tests\TestCase;

/**
 * The buyer's brand (docs/STANDALONE_EDITION_PLAN.md §15): a colour that is always #RRGGBB and readable
 * under white text, applied on a standalone copy only (the SaaS keeps Budget Pro's look), branded
 * emails, and the scheduler heartbeat both apps can read.
 */
class BrandingTest extends TestCase
{
    use DatabaseTransactions, RunsAsStandalone;

    private function company(array $attrs = []): Company
    {
        $company = Company::withoutGlobalScopes()->oldest('id')->first();
        $company->forceFill($attrs + ['name' => 'Fresh Mart', 'slogan' => 'Fresh every morning'])->saveQuietly();

        return $company->fresh();
    }

    private function colourErrors(string $value): array
    {
        $company = $this->company();

        return Validator::make(['color' => $value], CompanyRules::only($company, ['color']))->errors()->get('color');
    }

    public function test_a_brand_colour_must_be_a_hex_code_dark_enough_for_white_text(): void
    {
        $this->assertSame('#0F7B4F', BrandColour::normalise('0f7b4f'));
        $this->assertSame('#AABBCC', BrandColour::normalise('#abc'));
        $this->assertNull(BrandColour::normalise('red;}</style><script>'));

        $this->assertSame([], $this->colourErrors('#0f7b4f'));
        $this->assertSame([], $this->colourErrors(''));
        $this->assertStringContainsString('hex code', $this->colourErrors('</style>')[0]);
        $this->assertStringContainsString('too light', $this->colourErrors('#FFEE00')[0]);
        $this->assertStringContainsString('4.5:1', $this->colourErrors('#7FB3D5')[0]);

        $company = CompanyRules::apply($this->company(), ['color' => '0b5e3c']);
        $this->assertSame('#0B5E3C', $company->color, 'stored normalised, so it can never break the <style> tag');
        $this->assertContains('logo_wide', CompanyRules::writable());
        $this->assertNotContains('color', CompanyRules::sectionFields('profile'), 'the SaaS profile form does not carry the brand');
        $this->asStandalone();
        $this->assertContains('color', CompanyRules::sectionFields('profile'));
    }

    public function test_the_brand_applies_on_standalone_only(): void
    {
        $company = $this->company(['color' => '#1D4ED8']);

        $saas = ShopBrand::forCompany($company);
        $this->assertNull($saas->colour(), 'SaaS shops keep Budget Pro\'s look');
        $this->assertSame('', $saas->adminCss());
        $this->assertSame('Budget Pro', ShopBrand::productName());
        $this->assertNull(ShopBrand::forSignIn()->company());

        $this->asStandalone();
        $brand = ShopBrand::forSignIn();
        $this->assertSame($company->id, $brand->company()->id);
        $this->assertSame('#1D4ED8', $brand->colour());
        $this->assertSame('Fresh every morning', $brand->tagline());
        $this->assertTrue($brand->isSet());
        $this->assertStringStartsWith(':root{--br:#1D4ED8;--ac:#1D4ED8;--br-d:', $brand->adminCss());
        $this->assertStringContainsString('--pri:#1D4ED8', $brand->authCss());
        $this->assertSame('Fresh Mart', ShopBrand::productName());
        $this->assertTrue(ShopBrand::poweredBy(), 'Powered by Budget Pro stays without a white-label licence');

        $company->forceFill(['color' => '#FFEE00'])->saveQuietly(); // an imported row the form would have refused
        $this->assertNull(ShopBrand::forCompany($company->fresh())->colour());
        $this->assertSame('', ShopBrand::forCompany($company->fresh())->adminCss());
    }

    public function test_emails_wear_the_business_brand_on_standalone_and_stay_plain_on_the_saas(): void
    {
        $company = $this->company(['color' => '#1D4ED8', 'address' => 'Plot 4, Kampala Road']);
        Mail::fake();

        $this->assertTrue((new MailChannel)->send('owner@example.test', 'Your code is 123456.', ['subject' => 'Your code', 'company_id' => $company->id])['ok']);
        Mail::assertNothingSent(); // SaaS: the same raw message as always (Mail::raw)

        $this->asStandalone();
        $this->assertTrue((new MailChannel)->send('owner@example.test', 'Your code is 123456.', ['company_id' => $company->id])['ok']);
        Mail::assertSent(ShopMessage::class, function (ShopMessage $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Fresh Mart', $mail->subject, 'the subject is in the business\'s name');
            $this->assertStringContainsString('border-top:4px solid #1D4ED8', $html);
            $this->assertStringContainsString('Your code is 123456.', $html);
            $this->assertStringContainsString('Plot 4, Kampala Road', $html);
            $this->assertStringContainsString('Powered by Budget Pro', $html);
            $this->assertStringNotContainsString('Budget Pro</span>', explode('<td style="padding:12px 24px', $html)[0], 'the header is the business\'s');

            return $mail->hasTo('owner@example.test');
        });
    }

    public function test_the_heartbeat_is_a_file_both_apps_can_read(): void
    {
        $dir = sys_get_temp_dir().'/bp-heartbeat-'.uniqid();
        config(['edition.installed_path' => $dir.'/installed.json']);
        $this->assertSame($dir.'/system/heartbeat.json', SchedulerHeartbeat::path());
        $this->assertFalse(SchedulerHeartbeat::running());

        Artisan::call('standalone:heartbeat');
        $this->assertFileExists($dir.'/system/heartbeat.json');
        $this->assertTrue(SchedulerHeartbeat::running());
        $this->assertNotNull(cache()->get(StandaloneHeartbeat::KEY), 'the cache key stays for Licence & updates');

        SchedulerHeartbeat::record(now()->subMinutes(SchedulerHeartbeat::STALE_MINUTES + 1));
        $this->assertFalse(SchedulerHeartbeat::running(), 'a missing or wrong cron line shows up within ten minutes');
        @unlink($dir.'/system/heartbeat.json');
        @rmdir($dir.'/system');
        @rmdir($dir);
    }
}
