<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\WebLink;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAsStandalone;

/** D1 meta and D7 web links (the kernel side; the web portal's half is tested in budget-pro-new). */
class PhoneMetaTest extends ApiTestCase
{
    use RunsAsStandalone;

    private function member(array $t, string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $t['company_id']])->save();
        CompanyMember::create(['company_id' => $t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['user' => $u, 'h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    public function test_meta_draws_the_shop_the_way_the_web_does(): void
    {
        config(['saas.new_ui_url' => 'https://shop.example.test/']);
        $this->getJson('/api/v1/meta')->assertStatus(401);
        $t = $this->registerTenant();
        $res = $this->getJson('/api/v1/meta', $this->auth($t['token']))->assertOk();
        $d = $res->json('data');

        $this->assertSame(1, $d['api_version']);
        $this->assertSame($t['company_id'], $d['business']['id']);
        $this->assertSame(['UGX', 0, 'UGX 1,234,568'], [$d['business']['currency'], $d['business']['decimals'], $d['business']['money']['sample']]);
        $this->assertNull($d['business']['brand_colour'], 'the SaaS keeps Budget Pro green');
        $this->assertSame('saas', $d['edition']['edition']);
        $this->assertSame('trial', $d['plan']['badge_kind']);
        $this->assertMatchesRegularExpression('/^Trial: \d+ days? left$/', $d['plan']['badge']);
        $this->assertTrue($d['plan']['can_pay']);
        $this->assertSame('owner', $d['user']['role']);
        $this->assertContains('manage_settings', $d['user']['permissions']);
        $this->assertSame('cash', $d['payment_methods'][0]['value']);
        $this->assertContains(['value' => 'Voided', 'label' => 'Voided', 'tone' => 'neutral'], $d['enums']['sale_status']);
        $this->assertContains(['value' => 'Sale', 'label' => 'Sale', 'tone' => 'info', 'direction' => 'out'], $d['enums']['movement_type']);
        foreach ($d['enums'] as $list) {
            foreach ($list as $row) {
                $this->assertContains($row['tone'], \App\Support\MetaEnums::TONES);
            }
        }
        $this->assertSame(config('mobile.latest_version'), $d['app']['latest_version']);
        $this->assertSame(['platform', 'Budget Pro'], [$d['support']['who'], $d['support']['name']], 'who to ask (SupportContact)');
        $this->assertArrayHasKey('app.help', $d['support']['templates']);
        $this->assertSame('https://shop.example.test', $d['web_url']);
        $this->assertContains('/settings', array_column($d['web_pages'], 'path'));
        $this->assertNotContains('/settings/system', array_column($d['web_pages'], 'path'), 'standalone only');
        $this->assertIsBool($d['features']['store']['mode']);
        $this->assertContains('shop', $d['features']['modules']);

        // Cache-friendly: the same ETag again answers 304.
        $etag = $res->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getJson('/api/v1/meta', $this->auth($t['token']) + ['If-None-Match' => $etag])->assertStatus(304);

        // A cashier sees their own role and only their pages.
        $cashier = $this->getJson('/api/v1/meta', $this->member($t, 'cashier')['h'])->assertOk()->json('data');
        $this->assertSame('cashier', $cashier['user']['role']);
        $this->assertNotContains('manage_settings', $cashier['user']['permissions']);
        $this->assertNotContains('/settings', array_column($cashier['web_pages'], 'path'));
        $this->assertContains('/sell', array_column($cashier['web_pages'], 'path'));
        $this->assertFalse($cashier['plan']['can_pay']);

        // A lapsed shop still reads its meta, with an urgent nudge.
        DB::table('subscriptions')->where('company_id', $t['company_id'])->update(['status' => 'expired', 'trial_ends_at' => now()->subMonths(2), 'ends_at' => now()->subMonths(2)]);
        $plan = $this->getJson('/api/v1/meta', $this->auth($t['token']))->assertOk()->json('data.plan');
        $this->assertSame(['expired', 'expired', 'bad', true], [$plan['state'], $plan['badge_kind'], $plan['tone'], $plan['urgent']]);
        $this->assertNotEmpty($plan['nudge']);
    }

    public function test_standalone_meta_has_no_plan_and_wears_the_business_colour(): void
    {
        $t = $this->registerTenant();
        DB::table('companies')->where('id', $t['company_id'])->update(['color' => '#1d4ed8']);
        $this->asStandalone();
        $d = $this->getJson('/api/v1/meta', $this->auth($t['token']))->assertOk()->json('data');
        $this->assertNull($d['plan']);
        $this->assertSame('standalone', $d['edition']['edition']);
        $this->assertSame('#1D4ED8', strtoupper((string) $d['business']['brand_colour']));
        $this->assertNotContains('/plan', array_column($d['web_pages'], 'path'));

        // The owner's banners (OwnerNotices) come through "worth a look", pointing at the web page that fixes them.
        $items = collect($this->getJson('/api/v1/dashboard/suggestions?limit=20', $this->auth($t['token']))->assertOk()->json('data.items'));
        $licence = $items->firstWhere('key', 'owner_licence');
        $this->assertNotNull($licence, 'no licence is installed in the test copy');
        $this->assertSame(['web', '/settings/system'], [$licence['route'], $licence['web_path']]);
    }

    public function test_web_link_is_signed_short_and_single_use(): void
    {
        config(['saas.new_ui_url' => 'https://shop.example.test']);
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->postJson('/api/v1/web-link', ['path' => '/promotions'])->assertStatus(401);
        $link = $this->postJson('/api/v1/web-link', ['path' => '/sales?scope=unpaid'], $h)->assertStatus(201)->json('data');
        $this->assertStringStartsWith('https://shop.example.test/web-link?token=', $link['url']);
        $this->assertSame('/sales?scope=unpaid', $link['path']);
        $this->assertEqualsWithDelta(now()->addSeconds(120)->getTimestamp(), strtotime($link['expires_at']), 3);

        foreach (['https://evil.example/x', '//evil.example', 'sales', '/web-link?token=x', '/a b', '/logout'] as $bad) {
            $this->postJson('/api/v1/web-link', ['path' => $bad], $h)->assertStatus(422);
        }

        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $q);
        $claim = WebLink::consume($q['token'], '127.0.0.1');
        $this->assertSame([$t['user_id'], $t['company_id'], '/sales?scope=unpaid'], [$claim['user_id'], $claim['company_id'], $claim['path']]);
        $this->assertSame('link_used', $this->refusal(fn () => WebLink::consume($q['token'])));
        $this->assertSame('invalid_link', $this->refusal(fn () => WebLink::consume($q['token'].'x')));
        $this->assertSame('invalid_link', $this->refusal(fn () => WebLink::consume('garbage')));

        $late = $this->postJson('/api/v1/web-link', ['path' => '/dashboard'], $h)->assertStatus(201)->json('data.url');
        parse_str((string) parse_url($late, PHP_URL_QUERY), $q2);
        $this->travel(3)->minutes();
        $this->assertSame('link_expired', $this->refusal(fn () => WebLink::consume($q2['token'])));
        $this->travelBack();

        config(['saas.new_ui_url' => '']);
        $this->postJson('/api/v1/web-link', ['path' => '/dashboard'], $h)->assertStatus(503)->assertJsonPath('errors.code', 'web_link_unavailable');
    }

    private function refusal(callable $fn): ?string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return null;
    }
}
