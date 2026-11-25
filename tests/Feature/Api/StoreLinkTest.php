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
        $this->assertSame('', $listing(['X-App' => 'budget-pro']), 'Budget Pro is on the web');
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
}
