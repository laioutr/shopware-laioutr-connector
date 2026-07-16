<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Session\StoreApi\Controller;

use Laioutr\Connector\Session\Business\DomainWhitelistValidator;
use Laioutr\Connector\Session\Business\SessionHandoffCodeService;
use Laioutr\Connector\Session\Integration\SessionHandoffStore;
use Laioutr\Connector\Session\StoreApi\Controller\SessionHandoffController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SessionHandoffControllerTest extends TestCase
{
    public function testIssuesCodeForAllowedCallbacks(): void
    {
        $validator = $this->createStub(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(true);

        $codeService = $this->createStub(SessionHandoffCodeService::class);
        $codeService->method('generateCode')->willReturn('generated-code');

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::once())->method('issue')->with(
            'generated-code',
            'ctx-token',
            'sales-channel-id',
            'https://allowed.example/login',
            'https://allowed.example/logout',
            'frontend.checkout.cart.page',
        );

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController($validator, $codeService, $store);

        $response = $controller->issue($this->requestWith([
            'login-success-callback' => 'https://allowed.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
            'redirect-route' => 'frontend.checkout.cart.page',
        ]), $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['code' => 'generated-code'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsCallbackOutsideAllowlist(): void
    {
        $validator = $this->createStub(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(false);

        $store = $this->createMock(SessionHandoffStore::class);
        $store->expects(static::never())->method('issue');

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController(
            $validator,
            $this->createStub(SessionHandoffCodeService::class),
            $store,
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->issue($this->requestWith([
            'login-success-callback' => 'https://evil.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
            'redirect-route' => 'frontend.checkout.cart.page',
        ]), $context);
    }

    public function testRejectsMissingRedirectRoute(): void
    {
        $validator = $this->createStub(DomainWhitelistValidator::class);
        $validator->method('isValidUrl')->willReturn(true);

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getToken')->willReturn('ctx-token');
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $controller = new SessionHandoffController(
            $validator,
            $this->createStub(SessionHandoffCodeService::class),
            $this->createStub(SessionHandoffStore::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $controller->issue($this->requestWith([
            'login-success-callback' => 'https://allowed.example/login',
            'logout-success-callback' => 'https://allowed.example/logout',
        ]), $context);
    }

    /**
     * @param array<string, string> $body
     */
    private function requestWith(array $body): Request
    {
        return new Request([], $body);
    }
}
