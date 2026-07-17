<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Embedded\Business;

use Laioutr\Connector\Embedded\Business\RouteAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteAllowlistTest extends TestCase
{
    #[DataProvider('routeProvider')]
    public function testIsAllowed(string $additionalRoutes, string $route, bool $expected): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->method('getString')
            ->with(RouteAllowlist::CONFIG_KEY_ADDITIONAL_ROUTES, null)
            ->willReturn($additionalRoutes);

        $allowlist = new RouteAllowlist($systemConfigService);

        static::assertSame($expected, $allowlist->isAllowed($route));
    }

    public static function routeProvider(): iterable
    {
        yield 'cart allowed' => ['', 'frontend.checkout.cart.page', true];
        yield 'confirm allowed' => ['', 'frontend.checkout.confirm.page', true];
        yield 'finish allowed' => ['', 'frontend.checkout.finish.page', true];
        yield 'login allowed' => ['', 'frontend.account.login.page', true];
        yield 'register allowed' => ['', 'frontend.account.register.page', true];
        yield 'account order widget allowed' => ['', 'widgets.account.order.detail', true];
        yield 'checkout widget allowed' => ['', 'widgets.checkout.info', true];
        yield 'plugin session route allowed' => ['', 'frontend.laioutr.connect-session', true];
        yield 'plugin cookie bridge allowed' => ['', 'frontend.laioutr.cookie-bridge', true];
        yield 'error page allowed' => ['', 'error', true];
        yield 'csrf token allowed' => ['', 'frontend.csrf.generateToken', true];
        yield 'cart offcanvas allowed' => ['', 'frontend.cart.offcanvas', true];
        yield 'cookie offcanvas allowed' => ['', 'frontend.cookie.offcanvas', true];
        yield 'maintenance page allowed' => ['', 'frontend.maintenance.singlePage', true];
        yield 'home denied' => ['', 'frontend.home.page', false];
        yield 'navigation denied' => ['', 'frontend.navigation.page', false];
        yield 'product detail denied' => ['', 'frontend.detail.page', false];
        yield 'search denied' => ['', 'frontend.search.page', false];
        yield 'cms widget denied' => ['', 'widgets.cms.page', false];
        yield 'wishlist denied' => ['', 'frontend.wishlist.page', false];
        yield 'additional route allowed' => ["frontend.example.return\n", 'frontend.example.return', true];
        yield 'additional route trims and ignores blanks' => ["\n  frontend.example.return  \n", 'frontend.example.return', true];
        yield 'unlisted route stays denied with blank config' => ["\n  \n", 'frontend.home.page', false];
    }
}
