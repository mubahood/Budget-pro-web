<?php

namespace Tests\Feature\Kin;

use App\Services\Billing\PlanLine;
use App\Services\Messaging\MailChannel;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Database\Seeders\FamilyRootsPlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * My Family Roots 360 stands alone: what the kernel says to a family (its welcome, codes, mail, refusals, plans and permissions)
 * never names another app. Solavia may appear only as "by Solavia".
 */
class KinIndependenceTest extends ApiTestCase
{
    use MakesKin;

    private const OTHERS = ['Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge & Budget', 'Pledge and Budget', 'Akiba', 'Estate Ledger', 'SACCO'];

    private function assertIndependent(string $text): void
    {
        foreach (self::OTHERS as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $text, "names {$name}: {$text}");
        }
        $this->assertDoesNotMatchRegularExpression('/(?<!by )Solavia/i', $text, 'Solavia only as "by Solavia": '.$text);
    }

    public function test_the_kin_strings_name_no_other_app(): void
    {
        $this->assertIndependent(CompanyRules::exclusiveMessage(['kin']));
        $this->assertIndependent(CompanyRules::exclusiveMessage(['kin', 'shop'], ['kin']));
        $this->assertIndependent((string) config('onboarding.modules.kin'));
        $this->assertIndependent(json_encode(config('onboarding.business_types.kin')));
        $this->assertIndependent(json_encode(config('kin')));
        foreach (['kin_view', 'kin_people', 'kin_branches', 'kin_settings'] as $p) {
            $this->assertIndependent((string) config('permissions.permissions.'.$p));
        }
        foreach (['kin_secretary', 'kin_treasurer', 'kin_branch_coordinator', 'kin_member'] as $r) {
            $this->assertIndependent((string) config('permissions.roles.'.$r.'.label'));
        }
        foreach (FamilyRootsPlanSeeder::plans() as $plan) {
            $this->assertIndependent($plan['name'].' '.$plan['description']);
        }
        $this->assertIndependent(SupportContact::forKin()->message('mail.general'));
        $refusal = (new \ReflectionClass(PlanLine::class))->getConstant('NOT_FOR')[PlanLine::FAMILY_ROOTS];
        $this->assertIndependent($refusal);
    }

    public function test_a_familys_welcome_codes_and_mail_name_only_my_family_roots_360(): void
    {
        config(['support.whatsapp' => '+256700000001', 'support.email' => 'help@example.com', 'mail.default' => 'array']);
        [$company] = $this->kinAccount();
        $welcome = DB::table('message_log')->where('company_id', $company->id)->where('purpose', 'welcome')->first();
        $this->assertNotNull($welcome);
        $this->assertStringStartsWith('Welcome to My Family Roots 360', $welcome->body);
        $this->assertIndependent($welcome->body);

        $staff = $this->kinStaff($company, 'kin_secretary');
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $staff->email, 'purpose' => 'reset'])->assertOk();
        $code = DB::table('message_log')->where('to', $staff->email)->where('purpose', 'otp')->orderByDesc('id')->first();
        $this->assertStringContainsString('Your My Family Roots 360 code is', $code->body);
        $this->assertIndependent($code->body);

        app()->forgetInstance('mail.manager');
        $ok = (new MailChannel())->send($staff->email, 'The family meeting is on Sunday.', ['company_id' => $company->id, 'subject' => 'Meeting']);
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $raw = rawurldecode(quoted_printable_decode(collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->toString()));
        $this->assertStringContainsString('My Family Roots 360', $raw);
        $this->assertIndependent($raw);

        $this->assertNull(SupportContact::receiptLine($company), 'no "Powered by" line for a family');
        $this->assertSame('My Family Roots 360', SupportContact::productName($company));
        foreach (DB::table('message_log')->where('company_id', $company->id)->pluck('body') as $body) {
            $this->assertIndependent((string) $body);
        }
    }
}
