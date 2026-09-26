<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Services\Notifications\ScheduledNotifications;
use App\Services\Team\TeamService;
use App\Support\Rules\CompanyRules;
use Illuminate\Support\Facades\DB;

/** CompanyRules / TeamRules shared with the new web interface, and the owner's "send me today's summary". */
class CompanySettingsTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant(['currency' => 'UGX']);
        $this->h = $this->auth($this->t['token']);
    }

    public function test_the_owner_edits_every_settings_column_with_one_rule_set(): void
    {
        $this->putJson('/api/v1/company', [
            'name' => 'Mama Shop', 'phone_number' => '0772000111', 'timezone' => 'Africa/Nairobi', 'tax_rate' => 18,
            'receipt_header' => 'TIN 100200', 'receipt_footer' => 'Thank you', 'receipt_channels' => ['whatsapp', 'print'], 'payment_methods' => ['cash', 'mobile_money'],
            'low_stock_default' => 4, 'negative_stock_policy' => 'block', 'require_shift' => true, 'credit_terms_days' => 14, 'debt_reminders_enabled' => true,
            'enabled_modules' => ['shop', 'finance'],
        ], $this->h)->assertOk()->assertJsonPath('data.shop.negative_stock_policy', 'block')->assertJsonPath('data.payment_methods.methods', ['cash', 'mobile_money']);

        $c = Company::withoutGlobalScopes()->find($this->t['company_id']);
        $this->assertSame('Africa/Nairobi', $c->timezone);
        $this->assertEquals(18, (float) $c->tax_rate);
        $this->assertSame(['whatsapp', 'print'], $c->receipt_channels);
        $this->assertSame(['shop', 'finance'], $c->modules());
        $this->assertTrue((bool) $c->require_shift);
        $this->assertSame(14, (int) $c->credit_terms_days);
        $this->assertEquals(0, $c->payment_methods['opening_float'], 'the stored shape keeps the wizard keys');

        // Changing payment methods keeps the wizard's momo providers and float.
        $c->payment_methods = ['methods' => ['cash'], 'momo' => ['mtn_momo'], 'opening_float' => 50000];
        $c->saveQuietly();
        $this->putJson('/api/v1/company', ['payment_methods' => ['cash', 'card']], $this->h)->assertOk();
        $this->assertEquals(['methods' => ['cash', 'card'], 'momo' => ['mtn_momo'], 'opening_float' => 50000], Company::withoutGlobalScopes()->find($c->id)->payment_methods);

        $this->putJson('/api/v1/company', ['timezone' => 'Mars/Base'], $this->h)->assertStatus(422)->assertJsonValidationErrors('timezone');
        $this->putJson('/api/v1/company', ['negative_stock_policy' => 'maybe'], $this->h)->assertStatus(422);
        $this->putJson('/api/v1/company', ['enabled_modules' => []], $this->h)->assertStatus(422);
        $this->putJson('/api/v1/company', ['payment_methods' => ['barter']], $this->h)->assertStatus(422);
        $this->putJson('/api/v1/company', ['name' => ''], $this->h)->assertStatus(422);
    }

    public function test_the_currency_is_locked_once_the_shop_has_sales(): void
    {
        $this->putJson('/api/v1/company', ['currency' => 'KES'], $this->h)->assertOk()->assertJsonPath('data.currency', 'KES');
        $this->putJson('/api/v1/company', ['currency' => 'UGX'], $this->h)->assertOk();
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', ['name' => 'Soda', 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 700, 'original_quantity' => 10], $this->h)->json('data.id');
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 1000]], 'items' => [['stock_item_id' => $item, 'quantity' => 1]]], $this->h)->assertStatus(201);
        $this->assertTrue(CompanyRules::currencyLocked(Company::withoutGlobalScopes()->find($this->t['company_id'])));
        $this->putJson('/api/v1/company', ['currency' => 'KES'], $this->h)->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->putJson('/api/v1/company', ['currency' => 'UGX', 'name' => 'Same currency is fine'], $this->h)->assertOk();
    }

    public function test_engagement_settings_use_the_same_rules(): void
    {
        $this->putJson('/api/v1/company/engagement', ['credit_terms_days' => 400], $this->h)->assertStatus(422);
        $this->putJson('/api/v1/company/engagement', ['credit_terms_days' => 21, 'debt_reminders_enabled' => true], $this->h)->assertOk()
            ->assertJsonPath('data.credit_terms_days', 21)->assertJsonPath('data.debt_reminders_enabled', true);
    }

    public function test_role_overrides_and_invite_cancelling_through_the_team_service(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->t['company_id']);
        $team = new TeamService();
        $team->setRolePermissions($company, 'cashier', ['sell', 'refund', 'discount', 'manage_team']);
        $cashier = collect(TeamService::roleList($company))->firstWhere('key', 'cashier');
        $this->assertEqualsCanonicalizing(['sell', 'refund', 'discount'], $cashier['permissions'], 'manage_team stays owner-only');
        $this->assertSame(1, DB::table('company_role_permissions')->where('company_id', $company->id)->where('role', 'cashier')->count(), 'only differences are stored');

        $this->expectExceptionMessage('This role cannot be changed.');
        $team->setRolePermissions($company, 'owner', []);
    }

    public function test_revoke_invite_is_scoped_to_the_company(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->t['company_id']);
        $owner = User::withoutGlobalScopes()->find($this->t['user_id']);
        $invite = (new TeamService())->invite($owner, 'cashier', null, 'revoke_'.uniqid().'@example.test')['invite'];
        $other = (new Company())->forceFill(['id' => $company->id + 100000]);
        $this->assertFalse((new TeamService())->revokeInvite($other, (int) $invite->id));
        $this->assertTrue((new TeamService())->revokeInvite($company, (int) $invite->id));
        $this->assertSame('revoked', DB::table('invites')->where('id', $invite->id)->value('status'));
        $this->assertFalse((new TeamService())->revokeInvite($company, (int) $invite->id), 'only a pending invite can be cancelled');
    }

    public function test_the_owner_can_send_todays_summary_to_their_phone(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->t['company_id']);
        $owner = User::withoutGlobalScopes()->find($this->t['user_id']);
        $owner->phone_e164 = null;
        $owner->save();
        try {
            (new ScheduledNotifications())->sendDailySummaryTo($company, $owner);
            $this->fail('no phone, no message');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('no_phone', $e->errorCode());
        }
        $owner->phone_e164 = '+256772404777';
        $owner->save();
        $id = (new ScheduledNotifications())->sendDailySummaryTo($company, $owner);
        $log = DB::table('message_log')->find($id);
        $this->assertSame('+256772404777', $log->to);
        $this->assertSame('daily_summary', $log->purpose);
        $this->assertStringContainsString('No sales recorded today.', $log->body);
        $m = (new ScheduledNotifications())->dailySummaryMessage($company, now()->setTimezone('Africa/Kampala'));
        $this->assertSame("*{$m['title']}*\n{$m['body']}", $log->body, 'the same words as the 8 pm summary');
    }
}
