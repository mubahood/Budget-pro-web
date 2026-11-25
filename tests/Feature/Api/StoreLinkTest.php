<?php

namespace Tests\Feature\Api;

/**
 * A phone told to update goes to its own app's listing, never another app's: the listing that was Budget Pro's is UnityDesk's,
 * so an old phone of another app must not be sent there. A request that names no app keeps MOBILE_STORE_URL, and min_version
 * stays one global number.
 */
class StoreLinkTest extends ApiTestCase
{
    private const OLD = ['X-App-Version' => '1.9.3'];

    public function test_each_app_is_sent_to_its_own_listing_or_none(): void
    {
        config(['mobile.store_url' => 'https://example.test/fallback']);
        $listing = fn (array $h, string $q = '') => $this->getJson('/api/v1/plans'.$q, self::OLD + $h)->assertStatus(426)->json('errors.store_url');

        $this->assertSame('https://play.google.com/store/apps/details?id=com.budget.dynamics', $listing(['X-App' => 'unitydesk']));
        $this->assertSame('https://play.google.com/store/apps/details?id=com.budget.dynamics', $listing(['X-App' => 'unity']));
        $this->assertSame('https://play.google.com/store/apps/details?id=musenene.com', $listing(['X-App' => 'kin']));
        $this->assertSame('https://play.google.com/store/apps/details?id=com.budget.dynamics', $listing(['X-App' => 'budget-pro']), 'Budget Pro keeps its listing until the switch day');
        config(['mobile.store_urls.budget-pro' => '']);
        $this->assertSame('', $listing(['X-App' => 'budget-pro']), 'after the switch Budget Pro is on the web');
        foreach (['shelfie', 'akiba', 'keyroll', 'kuku-tally'] as $app) {
            $this->assertStringNotContainsString('com.budget.dynamics', $listing(['X-App' => $app]), $app.' is never sent to UnityDesk\'s listing');
        }
        $this->assertSame('', $listing([], '?app=akiba-ledger'), 'the app field names the app too');
        $this->assertSame('https://example.test/fallback', $listing([]), 'no app named: the old link');
        $this->assertSame('https://example.test/fallback', $listing(['X-App' => 'no-such-app']));

        $this->getJson('/api/v1/plans', self::OLD + ['X-App' => 'unitydesk'])->assertJsonPath('errors.min_version', '2.0.0');
        $this->getJson('/api/v1/app/version?app=family-roots')->assertOk()
            ->assertJsonPath('data.store_url', 'https://play.google.com/store/apps/details?id=musenene.com')
            ->assertJsonPath('data.min_version', (string) config('mobile.min_version'));
    }

    /** Each app can be held to its own versions; an app with none set, or a request naming no app, keeps the shared ones. */
    public function test_each_app_has_its_own_versions_falling_back_to_the_shared_ones(): void
    {
        config(['mobile.min_version' => '2.0.0', 'mobile.latest_version' => '2.1.0',
            'mobile.apps.family-roots' => ['min_version' => '3.5.0', 'latest_version' => '3.6.0'], 'mobile.apps.shelfie' => ['min_version' => '', 'latest_version' => '']]);

        $this->getJson('/api/v1/plans', ['X-App-Version' => '3.4.0', 'X-App' => 'family-roots'])->assertStatus(426)->assertJsonPath('errors.min_version', '3.5.0');
        $this->getJson('/api/v1/plans', ['X-App-Version' => '3.4.0', 'X-App' => 'shelfie'])->assertOk()->assertHeader('X-Min-App-Version', '2.0.0');
        $this->getJson('/api/v1/plans', ['X-App-Version' => '3.5.0', 'X-App' => 'kin'])->assertOk()->assertHeader('X-Min-App-Version', '3.5.0');
        $this->getJson('/api/v1/plans', ['X-App-Version' => '3.4.0'])->assertOk();

        $this->getJson('/api/v1/app/version?app=family-roots')->assertOk()->assertJsonPath('data.min_version', '3.5.0')->assertJsonPath('data.latest_version', '3.6.0');
        $this->getJson('/api/v1/app/version?app=shelfie')->assertOk()->assertJsonPath('data.latest_version', '2.1.0');
    }
}
