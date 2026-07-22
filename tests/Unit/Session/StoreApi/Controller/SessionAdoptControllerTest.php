<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Integration\SessionHandoff;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\StoreApi\Controller\SessionAdoptController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SessionAdoptControllerTest extends TestCase
{
    public function testReturnsContextTokenForValidCode(): void
    {
        $store = $this->createStub(SessionHandoffStore::class);
        $store->method('redeem')->willReturn(
            new SessionHandoff('ctx-token', 'sales-channel-id', null, null, null),
        );

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionAdoptController($store);

        $response = $controller->adopt($this->requestWith(['code' => 'valid-code']), $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['context-token' => 'ctx-token'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsUnknownCode(): void
    {
        $store = $this->createStub(SessionHandoffStore::class);
        $store->method('redeem')->willReturn(null);

        $controller = new SessionAdoptController($store);

        $this->expectException(BadRequestHttpException::class);
        $controller->adopt($this->requestWith(['code' => 'nope']), $this->createStub(SalesChannelContext::class));
    }

    public function testRejectsCodeIssuedForDifferentSalesChannel(): void
    {
        $store = $this->createStub(SessionHandoffStore::class);
        $store->method('redeem')->willReturn(
            new SessionHandoff('ctx-token', 'other-sales-channel', null, null, null),
        );

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionAdoptController($store);

        $this->expectException(BadRequestHttpException::class);
        $controller->adopt($this->requestWith(['code' => 'valid-code']), $context);
    }

    public function testRejectsMissingCode(): void
    {
        $controller = new SessionAdoptController($this->createStub(SessionHandoffStore::class));

        $this->expectException(BadRequestHttpException::class);
        $controller->adopt($this->requestWith([]), $this->createStub(SalesChannelContext::class));
    }

    /**
     * @param array<string, string> $body
     */
    private function requestWith(array $body): Request
    {
        return new Request([], $body);
    }
}
