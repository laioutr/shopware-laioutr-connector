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

class CheckoutReturnSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    protected function setUp(): void
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        $config->set(DomainWhitelistValidator::CONFIG_KEY, 'localhost');
        $config->set(EmbeddedConfig::EMBEDDED_MODE, true);
    }

    public function testFinishRedirectsToTheConfiguredSuccessPage(): void
    {
        static::getContainer()->get(SystemConfigService::class)
            ->set(EmbeddedConfig::FINISH_FALLBACK_URL, 'http://localhost/thank-you');

        // No session, so the finish route answers with its register redirect — the "session lost"
        // case, which the fallback exists to rescue.
        $response = $this->request('GET', 'checkout/finish', ['orderId' => 'ord1']);

        static::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        static::assertSame('http://localhost/thank-you?order=ord1', $response->headers->get('Location'));
    }

    public function testFinishIsLeftAloneWithoutATarget(): void
    {
        static::getContainer()->get(SystemConfigService::class)
            ->set(EmbeddedConfig::FINISH_FALLBACK_URL, '');

        $response = $this->request('GET', 'checkout/finish', ['orderId' => 'ord1']);

        static::assertStringNotContainsString(
            'thank-you',
            (string) $response->headers->get('Location'),
            'Without a configured target the finish route must be left as Shopware answered it',
        );
    }

    public function testFinishIsLeftAloneWithoutAnOrderId(): void
    {
        static::getContainer()->get(SystemConfigService::class)
            ->set(EmbeddedConfig::FINISH_FALLBACK_URL, 'http://localhost/thank-you');

        $response = $this->request('GET', 'checkout/finish', []);

        static::assertStringNotContainsString(
            'thank-you',
            (string) $response->headers->get('Location'),
            'A finish hit with no order id must not be redirected',
        );
    }

    public function testDisallowedFallbackDomainIsIgnored(): void
    {
        static::getContainer()->get(SystemConfigService::class)
            ->set(EmbeddedConfig::FINISH_FALLBACK_URL, 'https://not-allowed.example/thank-you');

        $response = $this->request('GET', 'checkout/finish', ['orderId' => 'ord1']);

        static::assertStringNotContainsString(
            'not-allowed.example',
            (string) $response->headers->get('Location'),
        );
    }
}
