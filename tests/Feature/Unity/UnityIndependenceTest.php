<?php

namespace Tests\Feature\Unity;

use App\Services\Billing\PlanLine;
use App\Services\Messaging\MailChannel;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Database\Seeders\UnityDeskPlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesKin;
use Tests\Concerns\MakesUnity;
use Tests\Feature\Api\ApiTestCase;

/**
 * UnityDesk stands alone: what the kernel says to an association (welcome, codes, mail, refusals, plans, permissions) names no
 * other app, Solavia only as "by Solavia"; and what other apps say never names UnityDesk.
 */
class UnityIndependenceTest extends ApiTestCase
{
    use MakesKin;
    use MakesUnity;

    private const OTHERS = ['Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge & Budget', 'Pledge and Budget', 'Akiba', 'Estate Ledger', 'Family Roots', 'SACCO'];

    private function assertIndependent(string $text): void
    {
        foreach (self::OTHERS as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $text, "names {$name}: {$text}");
        }
        $this->assertDoesNotMatchRegularExpression('/(?<!by )Solavia/i', $text, 'Solavia only as "by Solavia": '.$text);
    }

    public function test_the_unity_strings_name_no_other_app(): void
    {
        $this->assertIndependent(CompanyRules::exclusiveMessage(['unity']));
        $this->assertIndependent(CompanyRules::exclusiveMessage(['unity', 'shop'], ['unity']));
        $this->assertIndependent((string) config('onboarding.modules.unity'));
        $this->assertIndependent(json_encode(config('onboarding.business_types.unity')));
        $this->assertIndependent(json_encode(config('unity')));
        foreach (['unity_view', 'unity_members', 'unity_finance', 'unity_chapters', 'unity_settings'] as $p) {
            $this->assertIndependent((string) config('permissions.permissions.'.$p));
        }
        foreach (['unity_secretary', 'unity_treasurer', 'unity_chapter_lead', 'unity_member'] as $r) {
            $this->assertIndependent((string) config('permissions.roles.'.$r.'.label'));
        }
        foreach (UnityDeskPlanSeeder::plans() as $plan) {
            $this->assertIndependent($plan['name'].' '.$plan['description']);
        }
        $this->assertIndependent(SupportContact::forUnity()->message('mail.general'));
        $line = new \ReflectionClass(PlanLine::class);
        $this->assertIndependent($line->getConstant('NOT_FOR')[PlanLine::UNITYDESK]);
    }

    public function test_other_apps_never_say_unitydesk(): void
    {
        $line = new \ReflectionClass(PlanLine::class);
        $this->assertStringNotContainsString('UnityDesk', $line->getConstant('FOR_OTHERS')[PlanLine::UNITYDESK]);
        foreach ([PlanLine::AKIBA, PlanLine::ESTATE_LEDGER, PlanLine::FAMILY_ROOTS] as $other) {
            $this->assertStringNotContainsString('UnityDesk', $line->getConstant('NOT_FOR')[$other]);
        }
        foreach (['sacco', 'rentals', 'kin'] as $m) {
            $this->assertStringNotContainsString('UnityDesk', CompanyRules::exclusiveMessage([$m, 'unity'], [$m]));
        }
        $this->assertStringNotContainsString('UnityDesk', CompanyRules::exclusiveMessage(['shop', 'unity'], ['shop']));
        foreach (['kin_view', 'kin_people', 'sacco_view', 'rentals_view'] as $p) {
            $this->assertStringNotContainsString('UnityDesk', (string) config('permissions.permissions.'.$p));
        }
        config(['mail.default' => 'array']);
        [$family] = $this->kinAccount();
        foreach (DB::table('message_log')->where('company_id', $family->id)->pluck('body') as $body) {
            $this->assertStringNotContainsString('UnityDesk', (string) $body);
        }
    }

    public function test_an_associations_welcome_codes_and_mail_name_only_unitydesk(): void
    {
        config(['support.whatsapp' => '+256700000001', 'support.email' => 'help@example.com', 'mail.default' => 'array']);
        [$company] = $this->unityAccount();
        $welcome = DB::table('message_log')->where('company_id', $company->id)->where('purpose', 'welcome')->first();
        $this->assertNotNull($welcome);
        $this->assertStringStartsWith('Welcome to UnityDesk', $welcome->body);
        $this->assertIndependent($welcome->body);

        $staff = $this->unityStaff($company, 'unity_secretary');
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $staff->email, 'purpose' => 'reset'])->assertOk();
        $code = DB::table('message_log')->where('to', $staff->email)->where('purpose', 'otp')->orderByDesc('id')->first();
        $this->assertStringContainsString('Your UnityDesk code is', $code->body);
        $this->assertIndependent($code->body);

        app()->forgetInstance('mail.manager');
        $ok = (new MailChannel())->send($staff->email, 'The general meeting is on Saturday.', ['company_id' => $company->id, 'subject' => 'Meeting']);
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $raw = rawurldecode(quoted_printable_decode(collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->toString()));
        $this->assertStringContainsString('UnityDesk', $raw);
        $this->assertIndependent($raw);

        $this->assertNull(SupportContact::receiptLine($company), 'no "Powered by" line for an association');
        $this->assertSame('UnityDesk', SupportContact::productName($company));
        foreach (DB::table('message_log')->where('company_id', $company->id)->pluck('body') as $body) {
            $this->assertIndependent((string) $body);
        }
    }
}
