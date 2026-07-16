<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration;

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

    public function testResponseAllowsEmbedding(): void
    {
        $response = $this->request(
            'GET',
            'laioutr/cookie-bridge',
            ['redirect-route' => 'http://localhost/callback'],
        );

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertFalse($response->headers->has('X-Frame-Options'));
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

    public function testConnectorRoutesOnlyAcceptGetRequests(): void
    {
        $response = $this->request('POST', 'laioutr/cookie-bridge', []);

        static::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        static::assertSame('GET', $response->headers->get('Allow'));
    }
}
