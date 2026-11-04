<?php

namespace Tests\Feature\Rentals;

use App\Services\Messaging\MailChannel;
use App\Support\Rules\CompanyRules;
use App\Support\SupportContact;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Estate Ledger stands alone (KR-05): what the kernel sends an Estate Ledger account (its welcome, staff codes, team invites, mail
 * subjects, senders and footers, receipt lines and refusals) names Estate Ledger or the agency, never another app.
 */
class RentalsIndependenceTest extends ApiTestCase
{
    use MakesRentals;

    private const OTHERS = ['Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge & Budget', 'Akiba'];

    private function assertIndependent(string $text): void
    {
        foreach (self::OTHERS as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $text, "names {$name}: {$text}");
        }
    }

    public function test_a_estate_ledger_accounts_welcome_codes_invites_and_mail_name_only_estate_ledger(): void
    {
        config(['support.whatsapp' => '+256700000001', 'support.email' => 'help@example.com', 'mail.default' => 'array']);
        [$company, $owner] = $this->rentalsAccount();
        $welcome = DB::table('message_log')->where('company_id', $company->id)->where('purpose', 'welcome')->first();
        $this->assertNotNull($welcome);
        $this->assertStringStartsWith('Welcome to Estate Ledger', $welcome->body);
        $this->assertIndependent($welcome->body);

        $staff = $this->rentalsStaff($company, 'rentals_accountant');
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $staff->email, 'purpose' => 'reset'])->assertOk();
        $code = DB::table('message_log')->where('to', $staff->email)->where('purpose', 'otp')->orderByDesc('id')->first();
        $this->assertStringContainsString('Your Estate Ledger code is', $code->body);

        app()->forgetInstance('mail.manager');
        $ok = (new MailChannel())->send($staff->email, 'Your caretaker shift starts today.', ['company_id' => $company->id, 'subject' => 'Office']);
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $raw = rawurldecode(quoted_printable_decode(collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->toString()));
        $this->assertStringContainsString('Hello Estate Ledger', $raw, 'the footer greets Estate Ledger');
        $this->assertIndependent($raw);

        $this->assertNull(SupportContact::receiptLine($company), 'no "Powered by" line for an Estate Ledger account');
        $this->assertSame('Estate Ledger', SupportContact::productName($company));
        $this->assertIndependent(SupportContact::forRentals()->message('mail.general'));
        $this->assertIndependent(CompanyRules::exclusiveMessage(['rentals']));
        foreach (DB::table('message_log')->where('company_id', $company->id)->pluck('body') as $body) {
            $this->assertIndependent((string) $body);
        }
    }
}
