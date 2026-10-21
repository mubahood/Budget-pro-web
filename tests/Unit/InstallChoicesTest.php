<?php

namespace Tests\Unit;

use App\Services\Install\InstallChoices;
use App\Support\Rules\CompanyRules;
use PHPUnit\Framework\TestCase;

/**
 * The standalone installer's business types (read before Laravel boots) are the family's: a type built around an
 * exclusive module (a SACCO, AK-04) is made only by its own app's sign-up, so a shop buyer is never offered it.
 */
class InstallChoicesTest extends TestCase
{
    public function test_the_installer_never_offers_an_exclusive_modules_business_type(): void
    {
        $config = require dirname(__DIR__, 2).'/config/onboarding.php';
        $exclusive = array_keys(array_filter($config['business_types'], fn ($t) => array_intersect($t['modules'], CompanyRules::EXCLUSIVE_MODULES) !== []));
        $this->assertSame(['sacco'], $exclusive);
        $types = InstallChoices::businessTypes();
        $this->assertArrayNotHasKey('sacco', $types);
        $this->assertSame(array_values(array_diff(array_keys($config['business_types']), $exclusive)), array_keys($types));
        $this->assertCount(22, $types);
    }
}
