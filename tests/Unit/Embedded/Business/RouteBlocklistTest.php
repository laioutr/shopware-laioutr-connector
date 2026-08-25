<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Embedded\Business;

use Laioutr\Connector\Embedded\Business\RouteBlocklist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class RouteBlocklistTest extends TestCase
{
    #[DataProvider('routeProvider')]
    public function testIsBlocked(string $additionalRoutes, string $route, bool $expected): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService
            ->method('getString')
            ->with(RouteBlocklist::CONFIG_KEY_ADDITIONAL_ROUTES, null)
            ->willReturn($additionalRoutes);

        $blocklist = new RouteBlocklist($systemConfigService);

        static::assertSame($expected, $blocklist->isBlocked($route));
    }

    public static function routeProvider(): iterable
    {
        // Pages Laioutr renders itself.
        yield 'home blocked' => ['', 'frontend.home.page', true];
        yield 'navigation blocked' => ['', 'frontend.navigation.page', true];
        yield 'product detail blocked' => ['', 'frontend.detail.page', true];
        yield 'search blocked' => ['', 'frontend.search.page', true];
        yield 'search suggest blocked' => ['', 'frontend.search.suggest', true];
        yield 'landing page blocked' => ['', 'frontend.landing.page', true];
        yield 'standalone cms page blocked' => ['', 'frontend.cms.page.full', true];
        yield 'wishlist blocked' => ['', 'frontend.wishlist.page', true];

        // The confirm page's terms and cancellation-policy modals fetch this widget; blocking it
        // would break checkout.
        yield 'cms widget stays reachable' => ['', 'frontend.cms.page', false];

        // Checkout, account and the plugin's own flows.
        yield 'cart reachable' => ['', 'frontend.checkout.cart.page', false];
        yield 'confirm reachable' => ['', 'frontend.checkout.confirm.page', false];
        yield 'finish reachable' => ['', 'frontend.checkout.finish.page', false];
        yield 'login reachable' => ['', 'frontend.account.login.page', false];
        yield 'connect session reachable' => ['', 'frontend.laioutr.connect-session', false];
        yield 'checkout order handoff reachable' => ['', 'frontend.laioutr.checkout-order', false];
        yield 'error page reachable' => ['', 'error', false];

        // Payment plugin routes: the whole reason the list is inverted.
        yield 'paypal create order reachable' => ['', 'frontend.paypal.create_order', false];
        yield 'paypal restore context reachable' => ['', 'frontend.paypal.restore_context', false];
        yield 'unknown payment plugin route reachable' => ['', 'frontend.someplugin.callback', false];

        // Storefront AJAX fragments no longer need a path exception.
        yield 'menu offcanvas reachable' => ['', 'frontend.menu.offcanvas', false];
        yield 'quickview reachable' => ['', 'widgets.quickview.minimal', false];
        yield 'search filter widget reachable' => ['', 'widgets.search.filter', false];

        // Additional configured route names.
        yield 'additional route blocked' => ["frontend.example.page\n", 'frontend.example.page', true];
        yield 'additional route trims and ignores blanks' => ["\n  frontend.example.page  \n", 'frontend.example.page', true];
        yield 'unlisted route stays reachable with blank config' => ["\n  \n", 'frontend.example.page', false];
    }
}
