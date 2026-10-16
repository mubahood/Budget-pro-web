<?php

namespace Tests\Feature\Standalone;

use App\Http\Middleware\RedirectToNewUi;
use App\Support\ClassicPath;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * On a standalone copy the classic screens live under /classic (ADMIN_ROUTE_PREFIX, both apps on one
 * address: docs/STANDALONE_EDITION_PLAN.md §8.2). Path-based checks match the path without the prefix,
 * so the platform-only areas stay closed, permissions keep applying and redirects to the new screens
 * keep finding their target. Signed in, the drill showed /classic/auth/users answering 403.
 */
class ClassicPrefixTest extends TestCase
{
    public function test_path_checks_see_through_the_classic_prefix(): void
    {
        config(['admin.route.prefix' => 'classic']);
        $this->assertSame('stock-items/15/edit', ClassicPath::of('/classic/stock-items/15/edit'));
        $this->assertSame('', ClassicPath::of('classic'));
        $this->assertSame('classical-music', ClassicPath::of('classical-music'), 'only a whole segment is the prefix');
        $this->assertTrue(ClassicPath::is(Request::create('/classic/auth/users/3/edit'), 'auth/users', 'auth/users/*'));
        $this->assertTrue(ClassicPath::is(Request::create('/classic/auth/login'), 'auth/login'));
        $this->assertFalse(ClassicPath::is(Request::create('/classic/stock-items'), 'auth/users'));
        $this->assertSame('/products?peek=15', RedirectToNewUi::target(ClassicPath::of('classic/stock-items/15')));
        $this->assertSame('/dashboard', RedirectToNewUi::target(ClassicPath::of('classic')));

        config(['admin.route.prefix' => '']);
        $this->assertSame('classic/stock-items', ClassicPath::of('/classic/stock-items'), 'the SaaS has no prefix');
        $this->assertTrue(ClassicPath::is(Request::create('/auth/users'), 'auth/users'));
    }
}
