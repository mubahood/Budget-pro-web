<?php

namespace Tests\Feature\Sacco;

use App\Models\Plan;
use App\Models\SaccoSetting;
use App\Models\Subscription;
use App\Services\Sacco\Messaging\SaccoMessages;
use App\Services\Sacco\Messaging\SaccoReceipt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.17 (04 §10): receipts and member messages. In tests only message_log is written (the log driver);
 * nothing goes before the posting commits, so a rolled-back posting sends nothing; an opted-out member gets nothing;
 * SMS falls back to WhatsApp when the plan's allowance is used up; every text names the SACCO and never the platform.
 */
class SaccoMessagesTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    private const FAMILY = ['Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge', 'budget-pro', 'BudgetPro', 'family'];

    private function log(int $companyId)
    {
        return DB::table('message_log')->where('company_id', $companyId)->where('purpose', 'like', 'sacco_%')->orderBy('id')->get();
    }

    private function assertIndependent(string $text): void
    {
        foreach (self::FAMILY as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $text);
        }
    }

    public function test_a_posted_receipt_is_messaged_in_the_saccos_name_and_only_to_the_log(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        SaccoSetting::for((int) $c->id)->update(['short_name' => 'Teachers SACCO']);
        $m = app(\App\Services\Sacco\Members\MemberService::class)->register($c, ['first_name' => 'Apio', 'last_name' => 'Rose', 'phone' => '0772555111', 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $tx = $this->deposit($c, $a, 250_000, $s['teller']);
        $rows = $this->log((int) $c->id);
        $this->assertCount(1, $rows);
        $this->assertSame(['sms', '+256772555111', 'sacco_receipt'], [$rows[0]->channel, $rows[0]->to, $rows[0]->purpose]);
        $this->assertSame('Teachers SACCO: received UGX 250,000 to '.$a->account_no.' on '.now()->format('d/m/Y').'. Balance UGX 250,000. Rct '.$tx->receipt_no.'.', $rows[0]->body);
        $this->assertLessThanOrEqual(160, mb_strlen($rows[0]->body));
        $this->assertIndependent($rows[0]->body);

        $welcome = DB::table('message_log')->where('company_id', $c->id)->where('purpose', 'welcome')->first();
        $this->assertStringStartsWith('Welcome to Akiba Ledger', $welcome->body);
        foreach (DB::table('message_log')->where('company_id', $c->id)->pluck('body') as $body) {
            $this->assertIndependent($body);
        }
        $this->post('/api/v1/sacco/member-auth/otp', ['email' => 'nobody@example.com']); // an unknown address: nothing goes out, nothing is said

        $text = SaccoReceipt::text($tx);
        $this->assertStringContainsString('Test Teachers SACCO', $text);
        $this->assertStringContainsString('CS No. CS 1234', $text);
        $this->assertStringNotContainsString('Regulated', $text);
        foreach (explode("\n", trim($text)) as $line) {
            $this->assertLessThanOrEqual(32, strlen($line), $line);
        }
        $this->assertIndependent($text);
        $h = ['Authorization' => 'Bearer '.$s['teller']->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $json = $this->getJson("/api/v1/sacco/transactions/{$tx->id}/receipt", $h)->assertOk()->json('data');
        $this->assertStringContainsString($tx->receipt_no, $json['whatsapp']);
        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/sacco/transactions/{$tx->id}/receipt?format=pdf", $h)->assertOk()->getContent());
    }

    public function test_a_rolled_back_posting_sends_nothing_and_an_opted_out_member_gets_nothing(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $m = app(\App\Services\Sacco\Members\MemberService::class)->register($c, ['first_name' => 'Okello', 'last_name' => 'Tom', 'phone' => '0701222333', 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $a = $this->savingsAccount($c, $m, $s['teller']);
        try {
            DB::transaction(function () use ($c, $a, $s) {
                $this->deposit($c, $a, 10_000, $s['teller']);
                throw new \RuntimeException('the till crashed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(0, $this->log((int) $c->id), 'nothing was posted, so nothing was sent');
        $this->assertSame(0, $this->balanceOf((int) $a->id));

        $m->forceFill(['messages_opt_out_at' => now()])->save();
        $this->deposit($c, $a, 10_000, $s['teller']);
        $rows = $this->log((int) $c->id);
        $this->assertSame(['opted_out'], $rows->pluck('status')->all(), 'logged, never sent');
    }

    public function test_sms_falls_back_to_whatsapp_when_the_allowance_is_used_and_none_sends_nothing(): void
    {
        $s = $this->saccoCompany();
        $c = $s['company'];
        $plan = Plan::where('slug', 'akiba-enterprise')->firstOrFail();
        $plan->update(['limits' => array_merge((array) $plan->limits, ['max_sms_per_month' => 300])]); // rolled back with the test
        $m = app(\App\Services\Sacco\Members\MemberService::class)->register($c, ['first_name' => 'Nambi', 'last_name' => 'Joy', 'phone' => '0752111222', 'consent_data' => true], $s['owner'], ['status' => 'active'])['member'];
        $a = $this->savingsAccount($c, $m, $s['teller']);
        $rows = array_fill(0, 300, ['company_id' => $c->id, 'channel' => 'sms', 'to' => '+256700000000', 'purpose' => 'sacco_receipt', 'body' => 'x', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('message_log')->insert($rows);
        $this->deposit($c, $a, 5_000, $s['teller']);
        $last = DB::table('message_log')->where('company_id', $c->id)->where('purpose', 'sacco_receipt')->orderByDesc('id')->first();
        $this->assertSame('whatsapp', $last->channel, 'the allowance is used up: WhatsApp instead, never a failure');
        $this->assertStringContainsString("\n", $last->body, 'the WhatsApp text');

        SaccoSetting::for((int) $c->id)->update(['receipts_channel' => 'none']);
        $before = $this->log((int) $c->id)->count();
        $this->deposit($c, $a, 5_000, $s['teller']);
        $this->assertSame($before, $this->log((int) $c->id)->count());

        $free = $this->saccoCompany('group', 'akiba-trial');
        Subscription::where('company_id', $free['company']->id)->update(['plan_id' => Plan::where('slug', 'akiba-free')->value('id')]);
        SaccoSetting::for((int) $free['company']->id)->update(['receipts_channel' => 'sms']);
        $fm = app(\App\Services\Sacco\Members\MemberService::class)->register($free['company'], ['first_name' => 'Group', 'last_name' => 'Member', 'phone' => '0782000111', 'consent_data' => true], $free['owner'])['member'];
        $this->deposit($free['company'], $this->savingsAccount($free['company'], $fm, $free['teller'], 'GRP'), 2_000, $free['teller']);
        $this->assertSame('whatsapp', $this->log((int) $free['company']->id)->first()->channel, 'no SMS on Akiba Free');
    }

    public function test_every_template_fits_an_sms_in_english_and_kiswahili_and_luganda_falls_back(): void
    {
        $long = str_repeat('Long SACCO name ', 6);
        foreach (array_keys(SaccoMessages::TEXTS) as $key) {
            foreach (['en', 'sw', 'lg'] as $lang) {
                $r = SaccoMessages::render($key, $lang, ['sacco' => $long, 'amount' => 'UGX 12,345,678', 'account' => 'SAV-000123', 'date' => '21/10/2026', 'balance' => 'UGX 99,999,999',
                    'receipt' => 'RCT-2026-000123', 'count' => '10', 'total' => '250', 'what' => 'the entrance fee', 'to' => 'SAV-000124', 'original' => 'RCT-2026-000001',
                    'reason' => 'typed on the wrong account by the teller', 'product' => 'Ordinary savings', 'rate' => '6 %']);
                $this->assertLessThanOrEqual(160, mb_strlen($r['sms']), "{$key}/{$lang}");
                $this->assertStringNotContainsString('{', $r['sms'].$r['whatsapp'], "{$key}/{$lang} has no placeholder left");
                if ($key !== 'terms_notice') {
                    $this->assertStringContainsString('UGX', $r['sms'], "{$key}/{$lang} keeps the figures");
                }
            }
        }
        $this->assertSame(SaccoMessages::render('receipt_deposit', 'en', ['sacco' => 'S'])['sms'], SaccoMessages::render('receipt_deposit', 'lg', ['sacco' => 'S'])['sms']);
    }

    public function test_a_saccos_staff_codes_mail_footers_and_refusals_name_no_other_product(): void
    {
        config(['support.whatsapp' => '+256700000001', 'support.email' => 'help@example.com', 'mail.default' => 'array']);
        $s = $this->saccoCompany();
        $c = $s['company'];
        $email = $s['treasurer']->email;
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $email, 'purpose' => 'reset'])->assertOk();
        $code = DB::table('message_log')->where('to', $email)->where('purpose', 'otp')->orderByDesc('id')->first();
        $this->assertStringContainsString('Your Test Teachers SACCO code is', $code->body);
        $this->assertIndependent($code->body);

        // What the mail itself carries: the subject and the footer the kernel adds.
        app()->forgetInstance('mail.manager');
        $ok = (new \App\Services\Messaging\MailChannel())->send($email, $code->body, ['subject' => 'Test Teachers SACCO: your code', 'akiba' => true]);
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last();
        $raw = $sent->getOriginalMessage()->toString();
        $this->assertStringContainsString('Hello%20Akiba%20Ledger', quoted_printable_decode($raw), 'the WhatsApp link greets Akiba Ledger');
        $this->assertIndependent(rawurldecode(quoted_printable_decode($raw)));
        $staff = (new \App\Services\Messaging\MailChannel())->send($email, 'Your term as treasurer starts today.', ['company_id' => $c->id, 'subject' => 'Office']);
        $this->assertTrue($staff['ok']);
        $this->assertStringContainsString('Hello%20Akiba%20Ledger', quoted_printable_decode(collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->toString()));

        $this->assertIndependent(\App\Support\Rules\CompanyRules::EXCLUSIVE_MESSAGE);
        $this->assertNull(\App\Support\SupportContact::receiptLine($c), 'no "Powered by" line for a SACCO');
        $this->assertIndependent(\App\Support\SupportContact::forSacco()->message('mail.general'));
        $this->assertStringContainsString('Budget Pro', \App\Support\SupportContact::platform()->message('mail.general'), 'the family keeps its own words');
    }
}
