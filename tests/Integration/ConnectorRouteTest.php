<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration;

use Laioutr\Connector\Embedded\EmbeddedConfig;
use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

class ConnectorRouteTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    protected function setUp(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(
            DomainWhitelistValidator::CONFIG_KEY,
            'localhost',
        );
    }

    public function testCookieBridgeRedirectsToAllowedUrl(): void
    {
        $response = $this->request(
            'GET',
            'laioutr/cookie-bridge',
            ['redirect-route' => 'http://localhost/callback?existing=1'],
        );

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('http://localhost/callback?existing=1', $response->headers->get('Location'));
    }

    public function testConnectSessionRedirectsToShopwareRoute(): void
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            $this->getSalesChannelId(),
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'frontend.account.login.page',
        );

        $response = $this->request('GET', 'laioutr/connect-session', ['code' => $code]);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('/account/login', $response->headers->get('Location'));
        // Spec §13: the context token must never leak into the redirect target.
        static::assertStringNotContainsString(
            'test-context-token',
            (string) $response->headers->get('Location'),
        );
    }

    public function testConnectSessionRejectsUnknownCode(): void
    {
        $response = $this->request('GET', 'laioutr/connect-session', ['code' => 'does-not-exist']);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testConnectSessionRejectsForeignSalesChannel(): void
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            Uuid::randomHex(),
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'frontend.account.login.page',
        );

        $response = $this->request('GET', 'laioutr/connect-session', ['code' => $code]);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testConnectSessionRejectsUnknownRedirectRoute(): void
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            $this->getSalesChannelId(),
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'laioutr.not.a.registered.route',
        );

        $response = $this->request('GET', 'laioutr/connect-session', ['code' => $code]);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testResponseAllowsEmbeddingInEmbeddedMode(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(EmbeddedConfig::EMBEDDED_MODE, true);

        // Shopware only sets its security headers on a 2xx, so this has to be a rendered page
        // rather than one of the plugin's redirects.
        $response = $this->request('GET', 'checkout/cart', []);

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testResponseKeepsFrameOptionsOutsideEmbeddedMode(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(EmbeddedConfig::EMBEDDED_MODE, false);

        $response = $this->request('GET', 'checkout/cart', []);

        // CoreSubscriber sets `deny`; a channel that opts out of embedding keeps it.
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertSame('deny', $response->headers->get('X-Frame-Options'));
    }

    public function testDisallowedCallbackIsRejected(): void
    {
        $response = $this->request(
            'GET',
            'laioutr/cookie-bridge',
            ['redirect-route' => 'https://not-allowed.example/callback'],
        );

        static::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testCookieBridgeOnlyAcceptsGetRequests(): void
    {
        $response = $this->request('POST', 'laioutr/cookie-bridge', []);

        static::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        static::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testCheckoutOrderForwardsToTheOrderRoute(): void
    {
        $response = $this->request('POST', 'laioutr/checkout-order', ['code' => $this->issueCode()]);

        // 307 keeps the confirm form's method and body; a 302 would replay it as a GET,
        // which the order route rejects.
        static::assertSame(Response::HTTP_TEMPORARY_REDIRECT, $response->getStatusCode());
        static::assertSame('/checkout/order', $response->headers->get('Location'));
    }

    public function testCheckoutOrderCodeIsSingleUse(): void
    {
        $code = $this->issueCode();

        $this->request('POST', 'laioutr/checkout-order', ['code' => $code]);
        $response = $this->request('POST', 'laioutr/checkout-order', ['code' => $code]);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckoutOrderRejectsUnknownCode(): void
    {
        $response = $this->request('POST', 'laioutr/checkout-order', ['code' => 'does-not-exist']);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckoutOrderRejectsMissingCode(): void
    {
        $response = $this->request('POST', 'laioutr/checkout-order', []);

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckoutOrderRejectsForeignSalesChannel(): void
    {
        $response = $this->request(
            'POST',
            'laioutr/checkout-order',
            ['code' => $this->issueCode(Uuid::randomHex())],
        );

        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckoutOrderRejectsGetRequests(): void
    {
        $response = $this->request('GET', 'laioutr/checkout-order', []);

        static::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
    }

    private function issueCode(?string $salesChannelId = null): string
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            'test-context-token',
            $salesChannelId ?? $this->getSalesChannelId(),
            'http://localhost/login-callback',
            'http://localhost/logout-callback',
            'frontend.checkout.confirm.page',
        );

        return $code;
    }
}
