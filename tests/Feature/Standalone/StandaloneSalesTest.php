<?php

namespace Tests\Feature\Standalone;

use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Notifications\StandaloneDownloadReady;
use App\Services\FlutterwaveService;
use App\Services\StandaloneSalesService;
use App\Support\BuyerLink;
use App\Support\Licensing\Verifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\FakeFlutterwaveService;
use Tests\Feature\Standalone\Concerns\SellsStandalone;
use Tests\TestCase;

/**
 * Selling a copy (docs/STANDALONE_EDITION_PLAN.md §11): a verified payment issues exactly one licence
 * however many times the webhook and the return page arrive; a short or foreign-currency payment issues
 * nothing; a renewal extends the same licence; offline issuing emails the buyer; address-change rules;
 * the email's link is made when it is sent and opens the buyer page.
 */
class StandaloneSalesTest extends TestCase
{
    use DatabaseTransactions, SellsStandalone;

    private FakeFlutterwaveService $flw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSigningKey();
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
        Notification::fake();
    }

    private function sales(): StandaloneSalesService
    {
        return app(StandaloneSalesService::class);
    }

    public function test_a_verified_payment_issues_one_licence_through_the_webhook_and_the_return_page(): void
    {
        $this->release();
        $product = $this->product();
        $this->assertTrue($this->sales()->open());

        ['order' => $order, 'link' => $link] = $this->sales()->start($product, $this->buyer(), 'usd', 'https://web.test/own/payment/return');
        $this->assertStringStartsWith('https://checkout.flutterwave.test/pay/BPL-', $link);
        $this->assertSame([$order->reference, 400.0, 'USD', 'https://web.test/own/payment/return', app(\App\Services\Billing\BillingService::class)->paymentOptions('USD')], [
            $this->flw->lastPayload['tx_ref'], $this->flw->lastPayload['amount'], $this->flw->lastPayload['currency'],
            $this->flw->lastPayload['redirect_url'], $this->flw->lastPayload['payment_options'],
        ]);
        $product->update(['price_usd' => 900, 'updates_months' => 6]);
        $this->assertSame(['400.00', 12], [$order->fresh()->amount, $order->fresh()->months], 'the checkout price is the price');

        $this->flw->willVerify($order->reference, 400, 'USD', 777001);
        $this->postJson('/api/v1/webhooks/flutterwave', ['event' => 'charge.completed',
            'data' => ['id' => 777001, 'tx_ref' => $order->reference, 'status' => 'successful', 'amount' => 400, 'currency' => 'USD'],
        ], ['verif-hash' => 'test-webhook-hash'])->assertOk()->assertJson(['status' => 'settled']);
        $this->assertSame('already_settled', $this->sales()->settle($order->reference, 777001), 'the return page arrives second');
        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['id' => 777001, 'tx_ref' => $order->reference, 'status' => 'successful']],
            ['verif-hash' => 'test-webhook-hash'])->assertOk()->assertJson(['status' => 'already_settled']);

        $licence = StandaloneLicence::query()->sole();
        $this->assertSame(['Kato Hardware', 'owner@kato.test', ['shop.kato.test'], 'online', $order->reference], [$licence->customer, $licence->email, $licence->domains, $licence->source, $licence->reference]);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $licence->updates_until->toDateString());
        $this->assertEquals(['max_users' => 5, 'max_locations' => 1, 'max_devices' => 5], $licence->limits);
        $this->assertSame(['paid', $licence->id, '777001'], [$order->fresh()->status, $order->fresh()->licence_id, $order->fresh()->gateway_tx_id]);
        Notification::assertSentOnDemandTimes(StandaloneDownloadReady::class, 1);
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n, $c, AnonymousNotifiable $to) => $n->reason === 'purchased' && $to->routes['mail'] === 'owner@kato.test');

        $document = $this->sales()->document($licence);
        $this->assertTrue(app(Verifier::class)->verifiesDocument($document));
        $this->assertSame([$licence->id, ['shop.kato.test'], 5], [$document['licence']['id'], $document['licence']['domains'], $document['licence']['limits']['max_users']]);
    }

    public function test_an_underpayment_or_the_wrong_currency_issues_nothing(): void
    {
        $this->release();
        $product = $this->product();
        ['order' => $short] = $this->sales()->start($product, $this->buyer(), 'UGX', 'https://web.test/r');
        ['order' => $foreign] = $this->sales()->start($product, $this->buyer(), 'UGX', 'https://web.test/r');

        $paid = fn (StandaloneOrder $o, float $amount, string $cur) => ['id' => random_int(1, 99999), 'tx_ref' => $o->reference, 'status' => 'successful', 'amount' => $amount, 'currency' => $cur];
        $this->assertSame('mismatch', $this->sales()->settleVerified($short->reference, $paid($short, 1499999, 'UGX')));
        $this->assertSame('mismatch', $this->sales()->settleVerified($foreign->reference, $paid($foreign, 1500000, 'USD')));
        $this->assertSame('unknown_reference', $this->sales()->settleVerified($short->reference, $paid($foreign, 1500000, 'UGX')), 'another order\'s payment');

        $this->assertSame(0, StandaloneLicence::query()->count());
        $this->assertSame(['mismatch', 'mismatch'], [$short->fresh()->status, $foreign->fresh()->status]);
        Notification::assertNothingSent();
    }

    public function test_a_paid_renewal_extends_the_same_licence_once(): void
    {
        $this->release();
        $licence = $this->licence($this->product(), ['updates_until' => now()->addMonth()->toDateString()]);
        ['order' => $order] = $this->sales()->startRenewal($licence, 'UGX', 'https://web.test/r');
        $this->assertSame(['renewal', '300000.00', $licence->id], [$order->kind, $order->amount, $order->licence_id]);

        $data = ['id' => 5501, 'tx_ref' => $order->reference, 'status' => 'successful', 'amount' => 300000, 'currency' => 'UGX'];
        $this->assertSame('settled', $this->sales()->settleVerified($order->reference, $data));
        $this->assertSame('already_settled', $this->sales()->settleVerified($order->reference, $data));
        $this->assertSame(1, StandaloneLicence::query()->count());
        $this->assertSame(now()->addMonth()->addMonthsNoOverflow(12)->toDateString(), $licence->fresh()->updates_until->toDateString());
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n) => $n->reason === 'renewed');

        $lapsed = $this->licence($this->product(), ['updates_until' => now()->subYear()->toDateString()]);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $this->sales()->extend($lapsed, 3)->updates_until->toDateString(), 'from today when it has ended');

        $this->expectException(\DomainException::class);
        $this->sales()->startRenewal($this->licence($this->product(['renewal_price_ugx' => null])), 'UGX', 'https://web.test/r');
    }

    public function test_offline_issue_emails_a_link_made_when_sent_that_opens_the_buyer_page(): void
    {
        $licence = $this->sales()->issueOffline($this->product(), $this->buyer(['domain' => 'pos.kato.test']), 'BANK-0042');
        $this->assertSame(['offline', 'BANK-0042', ['pos.kato.test']], [$licence->source, $licence->reference, $licence->domains]);
        Notification::assertSentOnDemand(StandaloneDownloadReady::class, fn ($n, $c, $to) => $n->reason === 'issued' && $to->routes['mail'] === 'owner@kato.test');

        // Delivered for real through the app's mail channel: the link is made now, valid 24 hours.
        config(['messaging.mail.driver' => 'mail', 'saas.new_ui_url' => 'https://web.test']);
        Mail::fake();
        $this->travel(3)->hours();
        $text = (new StandaloneDownloadReady($licence->id, 'issued'))->toText(new AnonymousNotifiable());
        $this->assertSame('Your Budget Pro download is ready', $text['subject']);
        $this->assertStringContainsString($text['link'], $text['body']);
        // The email ends with our help line, its message naming the licence (never the buyer): SupportContact via MailChannel.
        $this->assertSame(['support_context' => 'standalone.install', 'support_vars' => ['licence' => $licence->id]], $text['options']);
        parse_str((string) parse_url($text['link'], PHP_URL_QUERY), $q);
        $this->assertSame(now()->addHours(24)->getTimestamp(), (int) $q['expires']);
        $this->assertStringStartsWith('https://web.test/my-licence/claim/'.$licence->id.'?', $text['link']);
        $this->assertTrue(BuyerLink::valid($licence->id, $q['expires'], $q['signature']));
        $this->assertFalse(BuyerLink::valid($licence->id, $q['expires'], strrev($q['signature'])));
        $this->assertFalse(BuyerLink::valid((string) \Illuminate\Support\Str::uuid(), $q['expires'], $q['signature']));
        $this->travel(25)->hours();
        $this->assertFalse(BuyerLink::valid($licence->id, $q['expires'], $q['signature']), 'expired');

        (new \App\Notifications\Channels\MessagingMailChannel())->send((new AnonymousNotifiable())->route('mail', $licence->email), new StandaloneDownloadReady($licence->id, 'link'));
        $this->assertNotNull($licence->fresh()->last_link_sent_at);
        $log = DB::table('message_log')->where('purpose', 'standalone_link')->latest('id')->first();
        $this->assertSame(['mail', 'sent'], [$log->channel, $log->status]);
        $this->assertStringNotContainsString('signature=', $log->body, 'the stored copy leaves the link out');
    }

    public function test_the_buyer_moves_the_address_once_in_30_days_and_staff_any_time(): void
    {
        $licence = $this->licence($this->product());
        $this->assertSame('new.kato.test', $this->sales()->changeDomain($licence, ' NEW.Kato.test ')->domain());
        try {
            $this->sales()->changeDomain($licence->fresh(), 'again.kato.test');
            $this->fail('a second move within 30 days is refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString(now()->addDays(30)->format('j M Y'), $e->getMessage());
        }
        $this->assertSame('again.kato.test', $this->sales()->changeDomain($licence->fresh(), 'again.kato.test', true)->domain());
        $this->travel(31)->days();
        $this->assertSame('later.kato.test', $this->sales()->changeDomain($licence->fresh(), 'later.kato.test')->domain());
        foreach (['https://shop.kato.test', 'shop.kato.test/path', 'localhost', 'a b.test'] as $bad) {
            try {
                $this->sales()->changeDomain($licence->fresh(), $bad, true);
                $this->fail("{$bad} is not an address");
            } catch (\DomainException) {
                $this->assertSame('later.kato.test', $licence->fresh()->domain());
            }
        }
    }

    public function test_selling_is_closed_without_a_release_a_product_signing_or_on_standalone(): void
    {
        $product = $this->product();
        $this->assertFalse($this->sales()->open(), 'no published release');
        $this->release();
        $this->assertTrue($this->sales()->open());
        config(['edition.signing_secret' => null]);
        $this->assertFalse($this->sales()->open(), 'no signing key');
        $this->useSigningKey();
        $product->update(['is_active' => false]);
        $this->assertFalse($this->sales()->open(), 'nothing on sale');
        $product->update(['is_active' => true]);
        config(['edition.name' => 'standalone']);
        $this->assertFalse($this->sales()->open());
        $this->expectException(\DomainException::class);
        $this->sales()->start($product, $this->buyer(), 'UGX', 'https://web.test/r');
    }
}
