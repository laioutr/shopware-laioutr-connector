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
    public function testIsAllowed(string $additionalRoutes, string $route, string $path, bool $expected): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->method('getString')
            ->with(RouteAllowlist::CONFIG_KEY_ADDITIONAL_ROUTES, null)
            ->willReturn($additionalRoutes);

        $allowlist = new RouteAllowlist($systemConfigService);

        static::assertSame($expected, $allowlist->isAllowed($route, $path));
    }

    public static function routeProvider(): iterable
    {
        // Allowed by route-name prefix / exact name (path is a non-widget path).
        yield 'cart allowed' => ['', 'frontend.checkout.cart.page', '/checkout/cart', true];
        yield 'confirm allowed' => ['', 'frontend.checkout.confirm.page', '/checkout/confirm', true];
        yield 'finish allowed' => ['', 'frontend.checkout.finish.page', '/checkout/finish', true];
        yield 'login allowed' => ['', 'frontend.account.login.page', '/account/login', true];
        yield 'register allowed' => ['', 'frontend.account.register.page', '/account/register', true];
        yield 'plugin session route allowed' => ['', 'frontend.laioutr.connect-session', '/laioutr/connect-session', true];
        yield 'plugin cookie bridge allowed' => ['', 'frontend.laioutr.cookie-bridge', '/laioutr/cookie-bridge', true];
        yield 'error page allowed' => ['', 'error', '/', true];
        yield 'cart offcanvas allowed' => ['', 'frontend.cart.offcanvas', '/checkout/offcanvas', true];
        yield 'cookie offcanvas allowed' => ['', 'frontend.cookie.offcanvas', '/cookie/offcanvas', true];
        yield 'maintenance page allowed' => ['', 'frontend.maintenance.page', '/maintenance', true];
        yield 'maintenance single page allowed' => ['', 'frontend.maintenance.singlepage', '/maintenance/singlepage', true];

        // Allowed by /widgets/* path, whatever the (inconsistent) route name is.
        yield 'cms page widget allowed by path' => ['', 'frontend.cms.page', '/widgets/cms/019f6a19089571ca8eb7f405f383e9b8', true];
        yield 'cms navigation widget allowed by path' => ['', 'frontend.cms.navigation.page', '/widgets/cms/navigation/abc', true];
        yield 'menu offcanvas widget allowed by path' => ['', 'frontend.menu.offcanvas', '/widgets/menu/offcanvas', true];
        yield 'account order widget allowed by path' => ['', 'widgets.account.order.detail', '/widgets/account/order/detail/abc', true];
        yield 'checkout info widget allowed by path' => ['', 'frontend.checkout.info', '/widgets/checkout/info', true];
        yield 'search widget allowed by path' => ['', 'widgets.search.filter', '/widgets/search/filter', true];

        // Allowed by the `widgets.` route-name prefix even though the path is NOT under /widgets/.
        yield 'quickview allowed by name' => ['', 'widgets.quickview.minimal', '/quickview/3ac014f329884b57a2cce5a29f34779c', true];

        // Denied: full pages (their route names are not allowlisted and the path is not /widgets/).
        yield 'wrong-case maintenance denied' => ['', 'frontend.maintenance.singlePage', '/maintenance/singlepage', false];
        yield 'home denied' => ['', 'frontend.home.page', '/', false];
        yield 'navigation denied' => ['', 'frontend.navigation.page', '/navigation/abc', false];
        yield 'product detail denied' => ['', 'frontend.detail.page', '/detail/abc', false];
        yield 'search page denied' => ['', 'frontend.search.page', '/search', false];
        yield 'wishlist page denied' => ['', 'frontend.wishlist.page', '/wishlist', false];

        // Additional configured route names.
        yield 'additional route allowed' => ["frontend.example.return\n", 'frontend.example.return', '/example/return', true];
        yield 'additional route trims and ignores blanks' => ["\n  frontend.example.return  \n", 'frontend.example.return', '/example/return', true];
        yield 'unlisted route stays denied with blank config' => ["\n  \n", 'frontend.home.page', '/', false];
    }
}
