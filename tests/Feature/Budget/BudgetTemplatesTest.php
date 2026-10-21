<?php

namespace Tests\Feature\Budget;

use App\Services\Budget\BudgetTemplates;
use App\Services\Budget\EventSettings;
use App\Services\Budget\MessageTemplates;
use Tests\TestCase;

/**
 * The budget template data (resources/data/budget_templates.json): valid, complete and priced in whole shillings;
 * the quantity, price and total rules; the message texts and how they render; the pledge groups read leniently;
 * and the phone app's bundled copy being the same file.
 */
class BudgetTemplatesTest extends TestCase
{
    public function test_the_data_file_is_valid_complete_and_priced(): void
    {
        BudgetTemplates::flush();
        $this->assertSame([], BudgetTemplates::problems());
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{1,2}\.\d+$/', BudgetTemplates::version());
        $all = BudgetTemplates::all();
        $this->assertGreaterThanOrEqual(15, count($all));
        $keys = array_column($all, 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
        foreach (['kwanjula-buganda', 'kuhingira-ankole-kiga', 'catholic-wedding', 'anglican-wedding', 'pentecostal-wedding', 'nikah-walima', 'civil-wedding',
            'intro-and-wedding', 'village-wedding', 'kampala-hotel-wedding', 'garden-wedding', 'luxury-wedding', 'acholi-lango-traditional', 'kukyala-visitation',
            'send-off-kasiki', 'graduation-party', 'fundraiser'] as $key) {
            $this->assertNotNull(BudgetTemplates::find($key), "{$key} is there");
        }
        foreach ($all as $t) {
            $summary = BudgetTemplates::summary($t);
            $this->assertGreaterThan(0, $summary['total'], $t['key']);
            $this->assertGreaterThan(0, $summary['items'], $t['key']);
            foreach ($t['categories'] as $c) {
                foreach ($c['items'] as $it) {
                    $this->assertIsInt($it['price']);
                    $this->assertGreaterThan(0, $it['price']);
                }
            }
        }
        // Sizes are sensible: the village wedding is cheaper than a garden wedding, the luxury one the dearest.
        $total = fn (string $k) => BudgetTemplates::summary(BudgetTemplates::find($k))['total'];
        $this->assertLessThan($total('garden-wedding'), $total('village-wedding'));
        $this->assertSame($total('luxury-wedding'), max(array_map(fn ($t) => BudgetTemplates::summary($t)['total'], $all)));
        $this->assertNotSame([], BudgetTemplates::problems(['templates' => [['key' => 'Bad Key', 'categories' => []]], 'messages' => [], 'groups' => []]), 'a broken file is caught');
    }

    public function test_quantities_scale_with_guests_and_prices_convert(): void
    {
        $this->assertSame(300, BudgetTemplates::quantity(['qty' => 1, 'per' => 1], 300));
        $this->assertSame(25, BudgetTemplates::quantity(['qty' => 1, 'per' => 12], 300), '2 sodas a guest, crates of 24');
        $this->assertSame(26, BudgetTemplates::quantity(['qty' => 1, 'per' => 12], 301), 'rounded up');
        $this->assertSame(6, BudgetTemplates::quantity(['qty' => 2, 'per' => 100], 250), 'two per hundred guests');
        $this->assertSame(4, BudgetTemplates::quantity(['qty' => 4], 999), 'a fixed item does not scale');
        $this->assertSame(22000, BudgetTemplates::price(22000, 'UGX'));
        $this->assertSame(6, BudgetTemplates::price(22000, 'USD'));
        $this->assertSame(1, BudgetTemplates::price(1000, 'USD'), 'never below 1');
        $this->assertSame(22000, BudgetTemplates::price(22000, 'XYZ'), 'an unknown currency keeps the figure');

        $t = BudgetTemplates::find('kwanjula-buganda');
        $at300 = BudgetTemplates::plan($t, 300);
        $at150 = BudgetTemplates::plan($t, 150);
        $this->assertLessThan(BudgetTemplates::total($at300), BudgetTemplates::total($at150));
        $line = fn (array $plan, string $name) => collect($plan)->flatMap(fn ($c) => $c['items'])->firstWhere('name', $name);
        $this->assertSame(300, $line($at300, 'Buffet for the guests')['quantity']);
        $this->assertSame(150, $line($at150, 'Buffet for the guests')['quantity']);
        $this->assertSame(2000000, $line($at150, 'Omutwalo (bride price envelope)')['total'], 'the bride price does not scale');
        $this->assertSame(300 * 25000, $line($at300, 'Buffet for the guests')['total']);
        $detail = BudgetTemplates::detail($t, 150);
        $this->assertSame(150, $detail['guests']);
        $this->assertSame(300, $detail['default_guests']);
        $this->assertSame(BudgetTemplates::total($at150), $detail['total']);
        $this->assertSame(BudgetTemplates::MAX_GUESTS, BudgetTemplates::clampGuests(999999, $t));
    }

    public function test_filters_by_type_culture_religion_size_and_words(): void
    {
        $keys = fn (array $f) => array_column(BudgetTemplates::filter($f), 'key');
        $this->assertContains('nikah-walima', $keys(['religion' => 'muslim']));
        $this->assertNotContains('catholic-wedding', $keys(['religion' => 'muslim']));
        $this->assertContains('village-wedding', $keys(['religion' => 'muslim']), 'a template for any religion matches every religion');
        $this->assertSame(['kwanjula-buganda', 'kukyala-visitation'], array_values(array_intersect(['kwanjula-buganda', 'kukyala-visitation'], $keys(['culture' => 'baganda']))));
        $this->assertNotContains('kuhingira-ankole-kiga', $keys(['culture' => 'baganda']));
        $this->assertSame(['luxury-wedding'], $keys(['size' => 'luxury']));
        foreach (BudgetTemplates::filter(['type' => 'introduction']) as $t) {
            $this->assertSame('introduction', $t['event_type']);
        }
        $this->assertContains('kuhingira-ankole-kiga', $keys(['q' => 'ankole']));
    }

    public function test_messages_render_with_placeholders_and_drop_empty_lines(): void
    {
        foreach (MessageTemplates::KINDS as $kind) {
            foreach (MessageTemplates::LANGS as $lang) {
                $this->assertNotSame('', MessageTemplates::defaultText($kind, $lang), "{$kind} in {$lang}");
            }
        }
        $this->assertSame(MessageTemplates::defaultText('reminder', 'en'), MessageTemplates::defaultText('reminder', 'fr'), 'English when a language has none');

        $text = "Hello {name}, {event} on {date}.\nPay to: {payment_number}\nTreasurer: {treasurer} {treasurer_phone}\nKeep {unknown}";
        $this->assertSame("Hello Grace, Wedding on 2 Jan 2027.\nTreasurer: Doreen 0772 123 456\nKeep {unknown}",
            MessageTemplates::render($text, ['name' => 'Grace', 'event' => 'Wedding', 'date' => '2 Jan 2027', 'payment_number' => '', 'treasurer' => 'Doreen', 'treasurer_phone' => '0772 123 456']));
        $this->assertSame("A\n\nB", MessageTemplates::render("A\n\n\n\nB", []));
        // An event with no date: the default reminder loses its date line instead of reading "on .".
        foreach (MessageTemplates::LANGS as $lang) {
            $out = MessageTemplates::render(MessageTemplates::defaultText('reminder', $lang), ['name' => 'Grace', 'event' => 'Wedding', 'date' => '']);
            $this->assertStringNotContainsString('{date}', $out);
            $this->assertDoesNotMatchRegularExpression('/ \.|: *\n/', $out, $lang);
        }

        $event = ['name' => 'Brian & Grace Wedding', 'date' => now()->addDays(10)->toDateString(), 'venue' => 'Mukono',
            'settings' => ['currency_display' => 'USh', 'meeting' => ['when' => 'Thursday 6pm', 'where' => 'Mukono']],
            'payment_details' => ['mtn' => '+256772123456', 'mtn_name' => 'Doreen', 'bank' => 'Stanbic', 'bank_account' => '9030', 'treasurer' => 'Doreen N.', 'treasurer_phone' => '+256701000111']];
        $v = MessageTemplates::values($event, ['name' => 'Aunt Grace', 'amount' => '500000', 'paid_amount' => '200000', 'not_paid_amount' => '300000'],
            ['amount' => '100000', 'method' => 'mobile_money', 'receipt_no' => 'R-7'], 'UGX');
        $this->assertSame('USh 500,000', $v['amount']);
        $this->assertSame('USh 300,000', $v['balance']);
        $this->assertSame('USh 100,000', $v['payment']);
        $this->assertSame('Mobile money, R-7', $v['how']);
        $this->assertSame('10', $v['days_left']);
        $this->assertSame('0772 123 456', $v['payment_number']);
        $this->assertSame("MTN MoMo: 0772 123 456 (Doreen)\nBank: Stanbic, A/C 9030", $v['payment_details']);
        $msg = MessageTemplates::render(MessageTemplates::defaultText('reminder', 'en'), $v);
        $this->assertStringContainsString('Aunt Grace', $msg);
        $this->assertStringContainsString("Brian & Grace Wedding.\nDate: ", $msg);
        $this->assertStringContainsString('Pay to: 0772 123 456', $msg);
        $this->assertStringNotContainsString('{', $msg);
        $this->assertSame(MessageTemplates::PLACEHOLDERS, array_keys($v), 'every placeholder has a value');
    }

    public function test_groups_and_settings_are_read_leniently(): void
    {
        $this->assertSame(["Groom's family", 'Friends'], EventSettings::parseGroups('["Groom\'s family", " Friends ", "friends", ""]'));
        $this->assertSame(['Family', 'MTK'], EventSettings::parseGroups('[{"key":"Family","label":"Family"},{"label":"MTK"}]'));
        $this->assertSame(['Family', 'Friends', 'Church'], EventSettings::parseGroups("Family, Friends\nChurch"), 'an older value typed as text');
        $this->assertSame([], EventSettings::parseGroups(null));
        $this->assertSame([], EventSettings::parseGroups('[]'));
        $this->assertNull(EventSettings::groupsJson([' ', '']));
        $this->assertSame(BudgetTemplates::defaultGroups(), EventSettings::read((object) ['groups' => null])['groups'], 'no list: the defaults');

        $s = EventSettings::settings('{"v":1,"messages":{"reminder":"Hi {name}","thanks":"  "},"message_lang":"xx","presets":["50,000",100000,-5,100000],"guests":"300","future":true}');
        $this->assertSame(['future' => true, 'v' => 1, 'messages' => ['reminder' => 'Hi {name}'], 'presets' => [50000, 100000], 'guests' => 300], $s);
        $this->assertSame(['v' => 1], EventSettings::settings('not json'));
        $this->assertNull(EventSettings::settingsJson('{oops'));
        $this->assertSame(['mtn' => '+256772123456', 'bank' => 'Stanbic'], EventSettings::paymentDetails('{"mtn":"0772 123 456","bank":"Stanbic","evil":"x"}'));
    }

    public function test_the_phone_app_bundles_the_same_file(): void
    {
        $app = (string) env('MOBILE_REPO', '/Users/mac/Desktop/github/budget-pro-mobo');
        $copy = rtrim($app, '/').'/assets/pledges/budget_templates.json';
        if (! is_file($copy)) {
            $this->markTestSkipped('The phone app is not next to this checkout (set MOBILE_REPO to compare its bundled templates).');
        }
        $this->assertSame(hash_file('sha256', BudgetTemplates::path()), hash_file('sha256', $copy),
            'The app bundles an older copy: cp resources/data/budget_templates.json '.$copy);
    }
}
