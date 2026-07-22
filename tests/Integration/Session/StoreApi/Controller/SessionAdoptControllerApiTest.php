<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Integration\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\HttpFoundation\Response;

class SessionAdoptControllerApiTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testRedeemsCodeAndReturnsContextToken(): void
    {
        $browser = $this->getSalesChannelBrowser();
        $code = $this->issueCodeFor($this->salesChannelIdOf($browser), 'ctx-token-integration');

        $this->adopt($browser, $code);

        $response = $browser->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertSame(
            ['context-token' => 'ctx-token-integration'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testRejectsAlreadyRedeemedCode(): void
    {
        $browser = $this->getSalesChannelBrowser();
        $code = $this->issueCodeFor($this->salesChannelIdOf($browser), 'ctx-token-integration');

        $this->adopt($browser, $code);
        $this->adopt($browser, $code);

        static::assertSame(Response::HTTP_BAD_REQUEST, $browser->getResponse()->getStatusCode());
    }

    public function testRejectsCodeIssuedForDifferentSalesChannel(): void
    {
        $browser = $this->getSalesChannelBrowser();
        // Issue for a sales channel other than the one the browser authenticates against.
        $code = $this->issueCodeFor('0123456789abcdef0123456789abcdef', 'ctx-token-integration');

        $this->adopt($browser, $code);

        static::assertSame(Response::HTTP_BAD_REQUEST, $browser->getResponse()->getStatusCode());
    }

    /**
     * Posts the JSON body the laioutr backend actually sends (Content-Type: application/json),
     * exercising Shopware's store-api JSON decoding into the request bag.
     */
    private function adopt(AbstractBrowser $browser, string $code): void
    {
        $browser->request(
            'POST',
            '/store-api/laioutr/session-adopt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => $code], \JSON_THROW_ON_ERROR),
        );
    }

    private function issueCodeFor(string $salesChannelId, string $contextToken): string
    {
        $code = static::getContainer()->get(SessionHandoffCodeService::class)->generateCode();
        static::getContainer()->get(SessionHandoffStore::class)->issue(
            $code,
            $contextToken,
            $salesChannelId,
            null,
            null,
            null,
        );

        return $code;
    }

    private function salesChannelIdOf(AbstractBrowser $browser): string
    {
        $salesChannelId = $browser->getServerParameter('test-sales-channel-id');
        static::assertIsString($salesChannelId);

        return $salesChannelId;
    }
}
