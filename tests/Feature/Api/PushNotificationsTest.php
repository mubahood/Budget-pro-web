<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\PushSender;
use App\Services\Notifications\PushStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Push notifications (engage plan §2): the phone hands over its token, Notifier::send writes the inbox and pushes through FCM,
 * within the rules (quiet hours, 3 a day per app, once only, hide details), and a dead token or a signed-out phone gets nothing.
 */
class PushNotificationsTest extends ApiTestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        // An invented service account with a throwaway key, made for this test only.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->keyFile, json_encode(['type' => 'service_account', 'project_id' => 'demo-project', 'client_email' => 'push@demo-project.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['services.firebase.credentials' => $this->keyFile]);
        $this->beforeApplicationDestroyed(fn () => @unlink($this->keyFile));
        $this->travelTo(Carbon::parse('2026-11-26 12:00', 'Africa/Kampala'));
    }

    private function fakeFcm(array $fcm = ['name' => 'projects/demo-project/messages/1'], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response($fcm, $status),
        ]);
    }

    private function phone(array $t, string $deviceId, ?string $pushToken, string $app = 'shelfie'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/devices/register', ['device_id' => $deviceId, 'push_token' => $pushToken, 'push_provider' => 'fcm', 'app' => $app, 'locale' => 'en'],
            $this->auth($t['token']));
    }

    private function fcmCalls(): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))->map(fn ($pair) => $pair[0]->data()['message'])->values();
    }

    public function test_the_token_is_kept_moves_with_the_phone_and_goes_at_sign_out(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $this->phone($a, 'phone-aaaa-1111', 'tok-1')->assertOk()->assertJsonPath('data.push', true);
        $row = Device::withoutGlobalScopes()->where('device_id', 'phone-aaaa-1111')->firstOrFail();
        $this->assertSame(['tok-1', 'fcm', 'shelfie', 'en'], [$row->push_token, $row->push_provider, $row->app, $row->locale]);
        $this->assertNotNull($row->access_token_id);

        // The same phone signs in to another account: the token goes with it.
        $this->phone($b, 'phone-bbbb-2222', 'tok-1', 'kuku-tally')->assertOk();
        $this->assertNull($row->fresh()->push_token);
        $this->assertSame('tok-1', Device::withoutGlobalScopes()->where('device_id', 'phone-bbbb-2222')->value('push_token'));

        // Registering without the key leaves it; null clears it.
        $this->postJson('/api/v1/devices/register', ['device_id' => 'phone-bbbb-2222'], $this->auth($b['token']))->assertOk()->assertJsonPath('data.push', true);
        $this->phone($b, 'phone-bbbb-2222', null, 'kuku-tally')->assertOk()->assertJsonPath('data.push', false);

        $this->phone($b, 'phone-bbbb-2222', 'tok-2', 'kuku-tally')->assertOk();
        $this->postJson('/api/v1/auth/logout', [], $this->auth($b['token']))->assertOk();
        $this->assertNull(Device::withoutGlobalScopes()->where('device_id', 'phone-bbbb-2222')->value('push_token'), 'signing out stops the pushes');
    }

    public function test_send_pushes_within_the_rules(): void
    {
        $this->fakeFcm();
        $t = $this->registerTenant();
        $this->phone($t, 'phone-cccc-3333', 'tok-3')->assertOk();
        $user = User::withoutGlobalScopes()->findOrFail($t['user_id']);
        $notifier = app(Notifier::class);

        $id = $notifier->send($user, 'shelfie', 'big_sale', 'A big sale', 'UGX 900,000 at the main till', 'sale/88', dedupe: 'sale:88');
        $this->assertNotNull($id);
        $msg = $this->fcmCalls()->first();
        $this->assertSame('tok-3', $msg['token']);
        $this->assertSame(['title' => 'A big sale', 'body' => 'UGX 900,000 at the main till'], $msg['notification']);
        $this->assertSame(['kind' => 'big_sale', 'link' => 'sale/88', 'notification_id' => (string) $id, 'app' => 'shelfie'], $msg['data']);
        $this->assertSame('activity', $msg['android']['notification']['channel_id']);
        $this->assertSame('sent', DB::table('app_notifications')->where('id', $id)->value('push_status'));

        $this->assertNull($notifier->send($user, 'shelfie', 'big_sale', 'A big sale', 'again', 'sale/88', dedupe: 'sale:88'), 'the same message once only');

        // Three a day per app: the fourth stays in the inbox.
        $notifier->send($user, 'shelfie', 'low_stock', 'Low stock', 'Sugar is low');
        $notifier->send($user, 'shelfie', 'low_stock', 'Low stock', 'Salt is low');
        $fourth = $notifier->send($user, 'shelfie', 'low_stock', 'Low stock', 'Rice is low');
        $this->assertSame('skipped', DB::table('app_notifications')->where('id', $fourth)->value('push_status'));
        $this->assertCount(3, $this->fcmCalls());

        // Hide details: a plain body on the lock screen, the inbox keeps the words.
        $notifier->setPreference((int) $user->id, 'hide_details', true, []);
        $this->travelTo(Carbon::parse('2026-11-27 09:00', 'Africa/Kampala'));
        $notifier->send($user, 'shelfie', 'cash_variance', 'Till short', 'UGX 5,000 short at till 2');
        $this->assertSame('You have a new notification. Open the app to see it.', $this->fcmCalls()->last()['notification']['body']);
        $this->assertSame('Shelfie', $this->fcmCalls()->last()['notification']['title']);

        // A kind turned off: nothing at all.
        $notifier->setPreference((int) $user->id, 'low_stock', false, ['in_app', 'push']);
        $this->assertNull($notifier->send($user, 'shelfie', 'low_stock', 'Low stock', 'Beans'));
    }

    public function test_quiet_hours_wait_unless_urgent_and_a_dead_token_is_dropped(): void
    {
        $dead = false;
        Http::fake(function ($request) use (&$dead) {
            return match (true) {
                str_contains($request->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
                $dead => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404),
                default => Http::response(['name' => 'projects/demo-project/messages/1']),
            };
        });
        $t = $this->registerTenant();
        $this->phone($t, 'phone-dddd-4444', 'tok-4')->assertOk();
        $user = User::withoutGlobalScopes()->findOrFail($t['user_id']);
        $notifier = app(Notifier::class);

        $this->travelTo(Carbon::parse('2026-11-26 22:30', 'Africa/Kampala'));
        $late = $notifier->send($user, 'shelfie', 'daily_summary', 'Today', 'UGX 1,200,000 in 40 sales', 'notifications');
        $this->assertSame('pending', DB::table('app_notifications')->where('id', $late)->value('push_status'));
        $notifier->send($user, 'shelfie', 'billing', 'Payment failed', 'Your plan could not renew', urgent: true);
        $this->assertCount(1, $this->fcmCalls(), 'only the urgent one goes at night');

        $this->travelTo(Carbon::parse('2026-11-27 06:40', 'Africa/Kampala'));
        $this->assertSame(0, $notifier->pushDue());
        $this->travelTo(Carbon::parse('2026-11-27 07:20', 'Africa/Kampala'));
        $this->artisan('notifications:push')->assertSuccessful();
        $this->assertSame('sent', DB::table('app_notifications')->where('id', $late)->value('push_status'));

        $dead = true;
        $notifier->send($user, 'shelfie', 'team', 'New team member', 'Amina joined as cashier');
        $this->assertNull(Device::withoutGlobalScopes()->where('device_id', 'phone-dddd-4444')->value('push_token'), 'a token Google no longer knows is dropped');
    }

    public function test_no_key_means_no_push_and_the_inbox_still_fills(): void
    {
        Http::fake();
        config(['services.firebase.credentials' => '/nowhere/firebase.json']);
        $t = $this->registerTenant();
        $this->phone($t, 'phone-eeee-5555', 'tok-5')->assertOk();
        $id = app(Notifier::class)->send(User::withoutGlobalScopes()->findOrFail($t['user_id']), 'shelfie', 'team', 'Hello', 'World');
        $this->assertSame('skipped', DB::table('app_notifications')->where('id', $id)->value('push_status'));
        Http::assertNothingSent();
    }

    public function test_a_tap_counts_as_an_open_and_preferences_are_the_apps_own(): void
    {
        $this->fakeFcm();
        $t = $this->registerTenant();
        $this->phone($t, 'phone-ffff-6666', 'tok-6')->assertOk();
        $id = app(Notifier::class)->send(User::withoutGlobalScopes()->findOrFail($t['user_id']), 'shelfie', 'big_sale', 'A big sale', 'UGX 1,000,000');
        $this->postJson('/api/v1/notifications/read', ['ids' => [$id], 'via' => 'push'], $this->auth($t['token']))->assertOk();
        $this->assertNotNull(DB::table('app_notifications')->where('id', $id)->value('opened_at'));

        $row = collect(PushStats::byAppAndKind(now()->subHour()))->firstWhere('kind', 'big_sale');
        $this->assertSame(['app' => 'shelfie', 'read' => 1, 'pushed' => 1, 'opened' => 1], array_intersect_key($row, array_flip(['app', 'pushed', 'opened', 'read'])));

        $keys = fn (string $app) => collect($this->getJson('/api/v1/notifications/preferences', $this->auth($t['token']) + ['X-App' => $app])->assertOk()->json('data'))->pluck('key')->all();
        $this->assertContains('daily_summary', $keys('shelfie'));
        $this->assertNotContains('pledges', $keys('shelfie'));
        $akiba = $keys('akiba-ledger');
        $this->assertContains('loan_due', $akiba);
        $this->assertEmpty(array_intersect(['daily_summary', 'low_stock', 'pledges', 'poultry', 'kin_dates'], $akiba));
        $this->assertContains('hide_details', $akiba);
    }

    public function test_dead_token_answers_are_recognised(): void
    {
        $this->assertTrue(PushSender::isDeadToken(404, []));
        $this->assertTrue(PushSender::isDeadToken(403, ['error' => ['details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]]));
        $this->assertTrue(PushSender::isDeadToken(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']]));
        $this->assertFalse(PushSender::isDeadToken(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload']]));
        $this->assertFalse(PushSender::isDeadToken(503, []));
    }
}
