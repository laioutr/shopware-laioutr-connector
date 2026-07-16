<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Session\StoreApi\Controller;

use Doctrine\DBAL\Connection;
use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Response;

class SessionHandoffControllerApiTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    protected function setUp(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(
            DomainWhitelistValidator::CONFIG_KEY,
            'allowed.example',
        );
    }

    public function testValidAccessKeyMintsCodeAndPersistsRow(): void
    {
        $browser = $this->getSalesChannelBrowser();

        $browser->request(
            'POST',
            '/store-api/laioutr/session-handoff',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'login-success-callback' => 'https://allowed.example/login',
                'logout-success-callback' => 'https://allowed.example/logout',
                'redirect-route' => 'frontend.checkout.cart.page',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $browser->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        static::assertIsArray($content);
        static::assertArrayHasKey('code', $content);
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $content['code']);

        $count = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM laioutr_session_handoff WHERE token_hash = :hash',
            ['hash' => static::getContainer()->get(SessionHandoffCodeService::class)->hashCode((string) $content['code'])],
        );
        static::assertSame(1, (int) $count);
    }

    public function testMissingAccessKeyIsRejected(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $countBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM laioutr_session_handoff');

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->setServerParameter('HTTP_ACCEPT', 'application/json');

        $browser->request(
            'POST',
            '/store-api/laioutr/session-handoff',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'login-success-callback' => 'https://allowed.example/login',
                'logout-success-callback' => 'https://allowed.example/logout',
                'redirect-route' => 'frontend.checkout.cart.page',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $browser->getResponse();
        static::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $decoded = json_decode((string) $response->getContent(), true);
        if (\is_array($decoded)) {
            static::assertArrayNotHasKey('code', $decoded);
        }

        $countAfter = (int) $connection->fetchOne('SELECT COUNT(*) FROM laioutr_session_handoff');
        static::assertSame($countBefore, $countAfter);
    }
}
