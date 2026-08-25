<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Embedded;

use Laioutr\Connector\Embedded\EmbeddedConfig;
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

    public function testBlockedRouteRedirectsToCart(): void
    {
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, true);
        $this->setConfig(EmbeddedConfig::LOCKDOWN, true);

        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testUnblockedRoutePassesThrough(): void
    {
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, true);
        $this->setConfig(EmbeddedConfig::LOCKDOWN, true);
        $this->setConfig(DomainWhitelistValidator::CONFIG_KEY, 'localhost');

        $response = $this->request('GET', 'laioutr/cookie-bridge', ['redirect-route' => 'http://localhost/callback']);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('http://localhost/callback', $response->headers->get('Location'));
    }

    public function testWidgetPathPassesThrough(): void
    {
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, true);
        $this->setConfig(EmbeddedConfig::LOCKDOWN, true);

        $response = $this->request('GET', 'widgets/menu/offcanvas', []);

        // Render-independent: whatever the widget returns, lockdown must not redirect it.
        static::assertFalse(
            $response->isRedirect('/checkout/cart'),
            'Lockdown must not redirect a storefront AJAX fragment to the cart',
        );
    }

    public function testLockdownCanBeDisabledWithoutLeavingEmbeddedMode(): void
    {
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, true);
        $this->setConfig(EmbeddedConfig::LOCKDOWN, false);

        $response = $this->request('GET', '', []);

        // The whole point of the split: framing and the bridge stay on while route
        // restriction is off, so a misbehaving payment method can be diagnosed in place.
        static::assertFalse(
            $response->isRedirect('/checkout/cart'),
            'Lockdown must not redirect when only the lockdown flag is disabled',
        );
    }

    public function testLockdownAppliesWithoutEmbeddedMode(): void
    {
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, false);
        $this->setConfig(EmbeddedConfig::LOCKDOWN, true);

        // A project that renders content in Laioutr but sends shoppers to this storefront's own
        // checkout wants the route policy without the frame.
        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testLockdownStaysOnWhenTheFlagWasNeverStored(): void
    {
        // An install predating the flag has no system_config row for it, because config.xml
        // defaults are only written at install time. getBool() reads that as false, which would
        // silently drop lockdown on upgrade.
        static::getContainer()->get(SystemConfigService::class)->delete(EmbeddedConfig::LOCKDOWN);
        $this->setConfig(EmbeddedConfig::EMBEDDED_MODE, true);

        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }

    public function testLockdownIsEnabledByDefault(): void
    {
        // No config set: the config.xml defaults are applied at plugin install
        // (tests/TestBootstrap.php force-installs the plugin), so lockdown is active.
        $response = $this->request('GET', '', []);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
    }
}
