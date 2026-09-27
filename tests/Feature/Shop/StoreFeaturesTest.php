<?php

namespace Tests\Feature\Shop;

use App\Support\StoreFeatures;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket features are off for every existing shop, on with Supermarket mode, and switchable one by one. */
class StoreFeaturesTest extends AdminTestCase
{
    public function test_off_by_default_on_with_mode_and_per_feature_overrides(): void
    {
        $c = $this->makeTenant('company')['company'];
        $this->assertFalse(StoreFeatures::mode($c));
        $this->assertFalse(StoreFeatures::enabled($c, 'promotions'), 'existing shops see nothing new');
        $this->assertSame(0, StoreFeatures::setting($c, 'cash_rounding'));
        $this->assertSame([1000, 2000, 5000, 10000, 20000, 50000], StoreFeatures::setting($c, 'note_buttons'), 'UGX notes');

        $c->forceFill(['business_type' => 'supermarket'])->save();
        $this->assertTrue(StoreFeatures::mode($c->fresh()), 'a supermarket starts in supermarket mode');

        StoreFeatures::update($c, ['mode' => true, 'features' => ['promotions' => false, 'nonsense' => true], 'settings' => ['cash_rounding' => 50, 'bogus' => 1]]);
        $c = $c->fresh();
        $this->assertTrue(StoreFeatures::enabled($c, 'loyalty'));
        $this->assertFalse(StoreFeatures::enabled($c, 'offline_till'), 'opt-in only: Supermarket mode never turns it on');
        $this->assertFalse(StoreFeatures::enabled($c, 'promotions'));
        $this->assertFalse(StoreFeatures::enabled($c, 'nonsense'));
        $this->assertSame(50, StoreFeatures::setting($c, 'cash_rounding'));
        $this->assertArrayNotHasKey('bogus', $c->store_settings['settings']);

        StoreFeatures::update($c, ['mode' => false, 'features' => ['promotions' => null, 'loyalty' => true]]);
        $c = $c->fresh();
        $this->assertFalse(StoreFeatures::enabled($c, 'fefo'));
        $this->assertTrue(StoreFeatures::enabled($c, 'loyalty'), 'one feature without the whole mode');
        $this->assertFalse(StoreFeatures::enabled($c, 'promotions'), 'reset to follow the mode');
    }
}
