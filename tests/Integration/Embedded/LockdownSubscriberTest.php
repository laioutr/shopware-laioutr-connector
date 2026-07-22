<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Embedded;

use Laioutr\Connector\Embedded\Subscriber\LockdownSubscriber;
use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

class LockdownSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private function setConfig(string $key, bool|string $value): void
    {
        static::getContainer()->get(SystemConfigService::class)->set($key, $value);
    }

    public function testDisallowedRouteRedirectsToCartWhenEmbedded(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, true);

        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testAllowedRoutePassesThroughWhenEmbedded(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, true);
        $this->setConfig(DomainWhitelistValidator::CONFIG_KEY, 'localhost');

        // The plugin's own cookie-bridge route is allowlisted (frontend.laioutr.*);
        // lockdown must let it reach the controller, which redirects to the callback.
        $response = $this->request('GET', 'laioutr/cookie-bridge', ['redirect-route' => 'http://localhost/callback']);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('http://localhost/callback', $response->headers->get('Location'));
    }

    public function testWidgetPathPassesThroughWhenEmbedded(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, true);

        $response = $this->request('GET', 'widgets/menu/offcanvas', []);

        // A /widgets/* AJAX fragment is allowed by path even though its route name
        // (frontend.menu.offcanvas) is not in the name allowlist. Render-independent:
        // whatever the widget returns, lockdown must not redirect it to the cart.
        static::assertFalse(
            $response->isRedirect('/checkout/cart'),
            'Lockdown must not redirect a /widgets/* route to the cart',
        );
    }

    public function testDisallowedRouteIsNotRedirectedWhenDisabled(): void
    {
        $this->setConfig(LockdownSubscriber::CONFIG_KEY_EMBEDDED_MODE, false);

        $response = $this->request('GET', '', []);

        // With embedded mode off, lockdown must not fire: the disallowed home
        // route must NOT be redirected to the cart. Render-independent (holds
        // whether home renders 200 or errors) unlike asserting status 200.
        static::assertFalse(
            $response->isRedirect('/checkout/cart'),
            'Lockdown must not redirect to the cart when embedded mode is disabled',
        );
    }

    public function testEmbeddedModeIsEnabledByDefault(): void
    {
        // No config set: the config.xml default (true) is applied at plugin install
        // (tests/TestBootstrap.php force-installs the plugin), so lockdown is active.
        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }
}
